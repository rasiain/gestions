<?php

namespace App\Services;

use App\Models\ComunitatBens;
use App\Models\Factura;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

/**
 * El PDF d'una factura de local comercial, amb el disseny de les que s'han anat
 * emetent a mà des de 2017. Les dades de l'arrendadora i del client no es copien a
 * la factura: surten del contracte cada vegada.
 */
class FacturaPdfService
{
    private const MESOS = [
        1 => 'gener', 2 => 'febrer', 3 => 'març', 4 => 'abril', 5 => 'maig', 6 => 'juny',
        7 => 'juliol', 8 => 'agost', 9 => 'setembre', 10 => 'octubre', 11 => 'novembre',
        12 => 'desembre',
    ];

    /** Els mesos que comencen amb vocal volen «d'» i no «de». */
    private static function deMes(int $mes): string
    {
        $nom = self::MESOS[$mes];

        return in_array($mes, [4, 8, 10], true) ? "d'{$nom}" : "de {$nom}";
    }

    public static function mes(int $mes): string
    {
        return self::MESOS[$mes];
    }

    public function dades(Factura $factura): array
    {
        $factura->loadMissing([
            'linies',
            'lloguer',
            'contracte.llogaters',
            'contracte.arrendadors.arrendadorable',
        ]);

        $lloguer = $factura->lloguer;
        $contracte = $factura->contracte ?? $lloguer->contractes()->orderByDesc('data_inici')->first();

        // A efectes de facturació el subjecte passiu és un de sol: el primer arrendador,
        // igual que a l'IVA.
        $arrendador = $contracte?->arrendadors->first()?->arrendadorable;
        $client = $contracte?->llogaters->first();

        $data = $factura->data_emissio
            ? Carbon::parse($factura->data_emissio)
            : Carbon::create($factura->any, $factura->mes ?: 1, 1);

        return [
            'emissor'   => $this->emissor($arrendador),
            'client'    => $this->client($client),
            'numero'    => $factura->numero_factura ?: '',
            'data'      => $data->day . ' ' . self::deMes((int) $data->month) . ' de ' . $data->year,
            'linies'    => $this->linies($factura),
            'base'      => (float) $factura->base,
            'iva_perc'  => (float) $factura->iva_percentatge,
            'iva'       => (float) $factura->iva_import,
            'irpf_perc' => (float) $factura->irpf_percentatge,
            'irpf'      => (float) $factura->irpf_import,
            'total'     => (float) $factura->total,
            'condicions' => $this->condicions($lloguer->condicions_pagament),
        ];
    }

    public function pdf(Factura $factura)
    {
        return Pdf::loadView('pdf.factura', $this->dades($factura))
            ->setPaper('a4', 'portrait');
    }

    /**
     * Cada local té el seu patró, que és com s'han anomenat els fitxers fins ara.
     * `{sufix}` distingeix la factura d'escombraries que va a banda.
     */
    public function nomFitxer(Factura $factura): string
    {
        $lloguer = $factura->lloguer;
        $patro = $lloguer->patro_nom_fitxer ?: 'Factura {numero}';

        $nom = strtr($patro, [
            '{numero}' => $factura->numero_factura ?: sprintf('%d%02d', $factura->any, $factura->mes),
            '{sufix}'  => $this->esDEscombraries($factura) ? '_Escombraries' : '',
            '{any}'    => (string) ($factura->any ?: ''),
            '{mes}'    => sprintf('%02d', $factura->mes ?: 0),
        ]);

        return $nom . '.pdf';
    }

    /** La carpeta de l'any de la factura, no la de l'any en curs. */
    public function directori(Factura $factura): ?string
    {
        if (!$factura->lloguer->ruta_factures) {
            return null;
        }

        $any = $factura->any ?: ($factura->data_emissio ? Carbon::parse($factura->data_emissio)->year : now()->year);

        return rtrim(strtr($factura->lloguer->ruta_factures, ['{any}' => (string) $any]), '/\\');
    }

    /** Una factura que només porta escombraries: la que a Juli Garreta va a banda. */
    private function esDEscombraries(Factura $factura): bool
    {
        $linies = $factura->linies;

        return $linies->isNotEmpty() && $linies->every(fn ($l) => $l->concepte === 'escombraries');
    }

    private function emissor(mixed $arrendador): array
    {
        if ($arrendador instanceof ComunitatBens) {
            return [
                'nom'     => $arrendador->nom,
                'nif'     => $arrendador->nif,
                'adreca'  => preg_split("/\r\n|\n/", (string) $arrendador->adreca),
                'telefon' => $arrendador->telefon,
            ];
        }

        // Un arrendador persona: no en desem adreça ni telèfon.
        return [
            'nom'     => $arrendador ? trim($arrendador->nom . ' ' . ($arrendador->cognoms ?? '')) : '',
            'nif'     => $arrendador?->nif,
            'adreca'  => [],
            'telefon' => null,
        ];
    }

    private function client(mixed $llogater): array
    {
        if (!$llogater) {
            return ['nom' => '', 'nif' => null, 'adreca' => null, 'cp_poblacio' => null];
        }

        $cpPoblacio = trim(implode(' - ', array_filter([
            $llogater->codi_postal,
            $llogater->poblacio ? mb_strtoupper($llogater->poblacio) : null,
        ])));

        return [
            'nom'         => $llogater->nom_rao_social ?: $llogater->nomDisplay(),
            'nif'         => $llogater->nifDisplay(),
            'adreca'      => $llogater->adreca,
            'cp_poblacio' => $cpPoblacio ?: null,
        ];
    }

    /**
     * Les files de la taula. El qualificador és la segona columna, entre parèntesis:
     * el mes a la renda i la fracció a les escombraries («1 de 3»), que ve dins de la
     * descripció desada.
     */
    private function linies(Factura $factura): array
    {
        $files = [];

        $ordenades = $factura->linies
            ->sortBy(fn ($l) => $l->concepte === 'lloguer_base' ? 0 : 1)
            ->values();

        foreach ($ordenades as $linia) {
            if ($linia->concepte === 'lloguer_base') {
                $files[] = [
                    'concepte'     => $factura->lloguer->concepte_factura ?: $linia->descripcio,
                    'qualificador' => $factura->mes ? self::MESOS[$factura->mes] . ' de ' . $factura->any : null,
                    'import'       => (float) $linia->base,
                ];

                continue;
            }

            // «Escombraries Industrials (1 de 3)» → concepte + fracció a part.
            $descripcio = (string) ($linia->descripcio ?: 'Escombraries industrials');
            $qualificador = null;
            if (preg_match('/^(.*?)\s*\((\d+\s+de\s+\d+)\)\s*$/u', $descripcio, $m)) {
                $descripcio = $m[1];
                $qualificador = $m[2];
            }

            $files[] = [
                'concepte'     => $descripcio,
                'qualificador' => $qualificador,
                'import'       => (float) $linia->base,
            ];
        }

        if (!$files) {
            $files[] = [
                'concepte'     => $factura->lloguer->concepte_factura ?: $factura->lloguer->nom,
                'qualificador' => $factura->mes ? self::MESOS[$factura->mes] . ' de ' . $factura->any : null,
                'import'       => (float) $factura->base,
            ];
        }

        return $files;
    }

    /**
     * El bloc del peu tal com es desa: una línia per línia i, dins de cada una, el
     * tabulador separa l'etiqueta de la dreta (que és on va l'IBAN).
     */
    private function condicions(?string $text): array
    {
        if (!$text) {
            return [];
        }

        return array_map(function (string $linia) {
            $parts = explode("\t", $linia, 2);

            return [
                'etiqueta' => trim($parts[0]),
                'valor'    => isset($parts[1]) ? trim($parts[1]) : null,
            ];
        }, preg_split("/\r\n|\n/", $text));
    }
}
