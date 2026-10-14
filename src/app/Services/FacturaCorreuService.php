<?php

namespace App\Services;

use App\Models\Factura;

/**
 * El correu que acompanya la factura. No s'envia: se'n prepara un esborrany a
 * Gmail, que és on s'acaba de revisar i s'hi adjunta el PDF.
 */
class FacturaCorreuService
{
    public function dades(Factura $factura): array
    {
        $factura->loadMissing(['linies', 'lloguer', 'contracte.llogaters']);

        $lloguer = $factura->lloguer;
        $contracte = $factura->contracte ?? $lloguer->contractes()->orderByDesc('data_inici')->first();
        $client = $contracte?->llogaters->first();

        $esEscombraries = $factura->linies->isNotEmpty()
            && $factura->linies->every(fn ($l) => $l->concepte === 'escombraries');

        $assumpte = $esEscombraries
            ? ($lloguer->assumpte_correu_escombraries ?: $lloguer->assumpte_correu)
            : $lloguer->assumpte_correu;

        $cos = $esEscombraries
            ? ($lloguer->cos_correu_escombraries ?: $lloguer->cos_correu)
            : $lloguer->cos_correu;

        $variables = $this->variables($factura, $client);

        return [
            'to'       => $client?->email,
            'cc'       => $client?->email_cc,
            'assumpte' => $assumpte ? strtr($assumpte, $variables) : '',
            'cos'      => $cos ? strtr($cos, $variables) : '',
        ];
    }

    /**
     * L'esborrany s'obre al navegador amb tot escrit. Gmail no deixa adjuntar per
     * URL: el PDF s'hi arrossega, i per això el botó també el baixa.
     */
    public function enllacGmail(Factura $factura): string
    {
        $dades = $this->dades($factura);

        $params = array_filter([
            'view' => 'cm',
            'fs'   => '1',
            'to'   => $dades['to'],
            'cc'   => $dades['cc'],
            'su'   => $dades['assumpte'],
            'body' => $dades['cos'],
        ]);

        return 'https://mail.google.com/mail/?' . http_build_query($params);
    }

    private function variables(Factura $factura, mixed $client): array
    {
        $mes = $factura->mes
            ? FacturaPdfService::mes((int) $factura->mes)
            : '';

        return [
            // «de {mes}» s'apostrofa davant de vocal (d'abril, d'agost, d'octubre). strtr()
            // prova primer la clau més llarga, i per això aquesta mana sobre «{mes}» sol.
            'de {mes}'   => $factura->mes ? FacturaPdfService::deMes((int) $factura->mes) : 'de ',
            '{mes}'      => $mes,
            '{any}'      => (string) ($factura->any ?: ''),
            '{numero}'   => (string) ($factura->numero_factura ?: ''),
            '{concepte}' => (string) ($factura->lloguer->concepte_factura ?: ''),
            '{contacte}' => (string) ($client?->contacte ?: ''),
            '{total}'    => number_format((float) $factura->total, 2, ',', '.') . ' €',
            '{escombraries}' => $this->escombraries($factura),
        ];
    }

    /**
     * La fracció d'escombraries quan va com a línia de la factura del mes (Joan Maragall), per
     * dir-ho al correu. Buit a les que no en porten i a les que només són d'escombraries, que ja
     * tenen el seu text.
     */
    private function escombraries(Factura $factura): string
    {
        $linies = $factura->linies->where('concepte', 'escombraries');

        if ($linies->isEmpty() || $linies->count() === $factura->linies->count()) {
            return '';
        }

        $import = number_format((float) $linies->sum('base'), 2, ',', '.') . ' €';

        return " i a la fracció de les escombraries industrials ({$import})";
    }
}
