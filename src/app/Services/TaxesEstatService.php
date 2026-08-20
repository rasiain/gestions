<?php

namespace App\Services;

use App\Models\FacturaLinia;
use App\Models\MovimentCompteCorrent;
use App\Models\MovimentLloguerIngresLinia;
use App\Models\TaxaRebut;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Estat de cada taxa d'un any: què s'ha pagat del total del rebut i, als
 * immobles de lloguer, què n'ha retornat el llogater.
 *
 * Cap import no s'infereix de text lliure. Les tres fonts són numèriques:
 *   1. `g_taxes_rebuts.import_total` — el que l'ajuntament gira.
 *   2. Els moviments classificats com a taxa — el que s'ha pagat.
 *   3. El desglossament del cobrament al llogater — línies de factura
 *      (`g_factura_linies`) o de l'ingrés (`g_moviment_lloguer_ingres_linia`
 *      de naturalesa `repercussio`).
 *
 * L'import retornat pel llogater es mostra encara que no s'hagi definit el
 * total del rebut: és una dada certa per ella mateixa. El que necessita el
 * total són els percentatges i el que queda per cobrar.
 */
class TaxesEstatService
{
    /** Marge per no cridar «sobrepagat» un arrodoniment. */
    private const TOLERANCIA = 1.0;

    /**
     * Estat per clau `grup||tipus`, per a l'any indicat.
     *
     * @param  Collection<int, MovimentCompteCorrent>  $moviments  moviments de taxa (tots els anys)
     * @return array<string, array<string, mixed>>
     */
    public function estats(Collection $moviments, int $any): array
    {
        $delAny     = $moviments->filter(fn (MovimentCompteCorrent $m) => (int) $m->data_moviment->format('Y') === $any);
        $rebuts     = TaxaRebut::where('any', $any)->get()->keyBy(fn (TaxaRebut $r) => $r->clau());
        $repercutit = $this->repercutitPerLloguer($any);

        $estats = [];
        foreach ($delAny->groupBy(fn (MovimentCompteCorrent $m) => TaxaRebut::clauDe(
            (string) $m->getAttribute('grup_taxa'),
            (string) $m->getAttribute('tipus_taxa')
        )) as $clau => $movs) {
            $rebut = $rebuts->get($clau);
            $total = $rebut ? (float) $rebut->import_total : null;
            $pagat = round(abs((float) $movs->sum('import')), 2);

            $estat = [
                'rebut_id'           => $rebut?->id,
                'total'              => $total,
                'pagat'              => $pagat,
                'pendent'            => $total !== null ? round($total - $pagat, 2) : null,
                'percentatge'        => $total > 0 ? round($pagat / $total * 100, 1) : null,
                'terminis_fets'      => $movs->count(),
                'terminis_previstos' => $rebut?->terminis_previstos,
                'sobrepagat'         => $total !== null && $pagat > $total + self::TOLERANCIA,
                'notes'              => $rebut?->notes,
            ] + $this->estatRepercussio($rebut, $movs, $total, $pagat, $repercutit);

            // Una fila sense rebut i sense res repercutit no aporta res: es
            // mostra com fins ara, amb l'enllaç per definir-ne el total.
            if ($rebut === null && ! $estat['repercutible']) {
                continue;
            }

            $estats[$clau] = $estat;
        }

        return $estats;
    }

    /**
     * Part del llogater. Hi entra quan el grup està lligat a un lloguer i el
     * rebut és repercutible o, sense rebut definit, quan ja s'hi ha repercutit
     * algun import d'aquell concepte.
     *
     * @param  Collection<int, MovimentCompteCorrent>  $movs
     * @param  array<int, array<string, float>>  $repercutit
     * @return array<string, mixed>
     */
    private function estatRepercussio(?TaxaRebut $rebut, Collection $movs, ?float $total, float $pagat, array $repercutit): array
    {
        $lloguerId = $movs->map(fn (MovimentCompteCorrent $m) => $m->getAttribute('lloguer_id_taxa'))->filter()->first();
        if ($lloguerId === null) {
            return ['repercutible' => false];
        }

        // Sense rebut, el concepte es dedueix del tipus de taxa: «Escombraries» → «escombraries»
        $concepte = $rebut?->concepte_repercussio
            ?? Str::lower(Str::ascii((string) $movs->first()?->getAttribute('tipus_taxa')));

        if ($rebut !== null && ! $rebut->repercutible) {
            return ['repercutible' => false];
        }

        $import = round($repercutit[$lloguerId][$concepte] ?? 0.0, 2);

        if ($rebut === null && $import === 0.0) {
            return ['repercutible' => false];
        }

        return [
            'repercutible'         => true,
            'repercutit'           => $import,
            'pendent_llogater'     => $total !== null ? round($total - $import, 2) : null,
            'percentatge_llogater' => $total > 0 ? round($import / $total * 100, 1) : null,
            // Positiu: el llogater ha avançat. Negatiu: el propietari finança.
            'saldo'                => round($import - $pagat, 2),
            // Els lloguers que encara no desglossen la repercussió no tenen cap import
            'repercussio_parcial'  => $import === 0.0,
        ];
    }

    /**
     * Repercutit de l'any per lloguer i concepte: línies de factura i línies
     * d'ingrés de naturalesa `repercussio`, que són les dues formes de cobrar-lo.
     *
     * @return array<int, array<string, float>>
     */
    private function repercutitPerLloguer(int $any): array
    {
        $resultat = [];

        $linies = FacturaLinia::whereHas('factura', fn ($q) => $q->where('any', $any))
            ->with('factura:id,lloguer_id,any')
            ->get();

        foreach ($linies as $linia) {
            $lloguerId = $linia->factura?->lloguer_id;
            if ($lloguerId === null) {
                continue;
            }

            $resultat[$lloguerId][$linia->concepte] =
                ($resultat[$lloguerId][$linia->concepte] ?? 0.0) + (float) $linia->base;
        }

        $repercussions = MovimentLloguerIngresLinia::where('naturalesa', MovimentLloguerIngresLinia::REPERCUSSIO)
            ->with(['ingres.moviment:id,data_moviment'])
            ->get()
            ->filter(fn (MovimentLloguerIngresLinia $l) => (int) $l->ingres?->moviment?->data_moviment->format('Y') === $any);

        foreach ($repercussions as $linia) {
            $lloguerId = $linia->ingres?->lloguer_id;
            if ($lloguerId === null) {
                continue;
            }

            $resultat[$lloguerId][$linia->tipus] =
                ($resultat[$lloguerId][$linia->tipus] ?? 0.0) + (float) $linia->import;
        }

        return $resultat;
    }
}
