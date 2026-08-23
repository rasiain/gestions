<?php

namespace App\Services;

use App\Models\ComunitatBens;
use App\Models\Factura;
use App\Models\Immoble;
use App\Models\Lloguer;
use App\Models\MovimentLloguerDespesa;
use App\Models\MovimentLloguerIngresLinia;
use App\Models\Persona;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Model 184: la renda que una comunitat de béns atribueix als seus comuners.
 *
 * Dues coses que el 184 barreja i que aquí es mantenen separades:
 *
 *   - La QUOTA de titularitat de l'immoble (⅓, ⅔…), que reparteix les RETENCIONS.
 *   - El PERCENTATGE DE PARTICIPACIÓ, que reparteix el RENDIMENT i que no és el mateix,
 *     perquè cada comuner es dedueix la seva pròpia amortització.
 *
 *         base repartible   = ingressos íntegres − (despeses − amortització)
 *         rendiment comuner = quota × base repartible − amortització pròpia
 *         % participació    = rendiment comuner / suma dels rendiments
 *
 * L'amortització de cada comuner no es pot deduir de les dades del projecte —depèn del
 * valor i la data d'adquisició de la seva quota— i per això és una dada d'entrada, desada
 * al pivot `g_propietaris_immobles`. La resta surt del que ja hi ha: els ingressos i les
 * retencions de les factures, i les despeses de la classificació dels moviments.
 */
class Model184Service
{
    /** Les deu caselles de despesa de la columna de capital immobiliari. */
    public const CASELLES = [
        1  => 'Interessos i despeses de finançament',
        2  => 'Conservació i reparació',
        3  => 'Interessos i despeses de reparació pendents',
        4  => 'Tributs i recàrrecs',
        5  => 'Saldos de dubtós cobrament',
        6  => 'Quantitats meritades per tercers',
        7  => "Primes d'assegurances",
        8  => 'Amortització de l\'immoble',
        9  => 'Amortització de béns mobles',
        10 => 'Altres despeses deduïbles',
    ];

    /** La casella que es nodreix de les amortitzacions dels comuners, no de cap moviment. */
    private const CASELLA_AMORTITZACIO = 8;

    /** Casella per a una categoria que el mapatge no reconeix. */
    private const CASELLA_PER_DEFECTE = 10;

    /**
     * La declaració d'una comunitat per a un exercici.
     *
     * @return array<string, mixed>
     */
    public function declaracio(ComunitatBens $comunitat, int $any): array
    {
        $immobles = [];

        foreach ($this->lloguers($comunitat) as $lloguer) {
            if ($lloguer->immoble === null) {
                continue;
            }

            $immobles[] = $this->registreImmoble($lloguer, $any);
        }

        return [
            'comunitat' => [
                'id'   => $comunitat->id,
                'nom'  => $comunitat->nom,
                'nif'  => $comunitat->nif,
            ],
            'any'       => $any,
            'immobles'  => $immobles,
            'retencions' => round(array_sum(array_column($immobles, 'retencions')), 2),
            'avisos'    => $this->avisos($immobles),
        ];
    }

    /**
     * Lloguers de la comunitat: contractes on hi consta com a arrendadora.
     *
     * @return Collection<int, Lloguer>
     */
    public function lloguers(ComunitatBens $comunitat): Collection
    {
        $arrendadors = $comunitat->arrendadors()->pluck('id');

        if ($arrendadors->isEmpty()) {
            return collect();
        }

        $contractes = DB::table('g_arrendador_contracte')
            ->whereIn('arrendador_id', $arrendadors)
            ->pluck('contracte_id');

        return Lloguer::with('immoble')
            ->whereHas('contractes', fn ($q) => $q->whereIn('g_contractes.id', $contractes))
            ->orderBy('nom')
            ->get();
    }

    /**
     * Exercicis de la comunitat que tenen factures, del més recent al més antic.
     *
     * @return array<int, int>
     */
    public function anysAmbFactures(ComunitatBens $comunitat): array
    {
        $lloguers = $this->lloguers($comunitat)->pluck('id');

        if ($lloguers->isEmpty()) {
            return [];
        }

        return Factura::whereIn('lloguer_id', $lloguers)
            ->whereNotNull('any')
            ->distinct()
            ->orderByDesc('any')
            ->pluck('any')
            ->map(fn ($any) => (int) $any)
            ->all();
    }

    /**
     * Registre de clau C d'un immoble, amb el repartiment entre comuners.
     *
     * @return array<string, mixed>
     */
    private function registreImmoble(Lloguer $lloguer, int $any): array
    {
        [$ingressos, $retencions, $factures, $noCobrades] = $this->ingressosIRetencions($lloguer, $any);

        $caselles     = $this->caselles($lloguer, $any);
        $propietaris  = $this->propietaris($lloguer->immoble, $any);
        $amortitzacio = round((float) $propietaris->sum('amortitzacio'), 2);

        if ($amortitzacio > 0) {
            $caselles[self::CASELLA_AMORTITZACIO] = $amortitzacio;
        }

        $despeses = round((float) array_sum($caselles), 2);

        // El que es reparteix per quota abans que cadascú es dedueixi la seva amortització
        $base    = round($ingressos - ($despeses - $amortitzacio), 2);
        $comuners = $this->comuners($propietaris, $base, $retencions);

        return [
            'immoble_id'           => $lloguer->immoble->id,
            'lloguer'              => $lloguer->nom,
            'referencia_cadastral' => $lloguer->immoble->referencia_cadastral,
            'ingressos'            => $ingressos,
            'despeses'             => $despeses,
            'rendiment_net'        => round($ingressos - $despeses, 2),
            'retencions'           => $retencions,
            'amortitzacio'         => $amortitzacio,
            'base_repartible'      => $base,
            'factures'             => $factures,
            'factures_no_cobrades' => $noCobrades,
            'caselles'             => $caselles,
            'comuners'             => $comuners,
            // Per poder explicar per què no hi ha comuners, si no n'hi ha
            'titularitat_des_de'   => DB::table('g_propietaris_immobles')
                ->where('immoble_id', $lloguer->immoble->id)
                ->min('data_inici'),
        ];
    }

    /**
     * Ingressos íntegres i retencions, de les factures.
     *
     * No es prenen dels moviments classificats: les factures són la sèrie completa de
     * l'any i porten la retenció calculada, mentre que la classificació dels cobraments
     * pot anar endarrerida.
     *
     * @return array{0: float, 1: float, 2: int, 3: int}  ingressos, retencions, factures, no cobrades
     */
    private function ingressosIRetencions(Lloguer $lloguer, int $any): array
    {
        $factures = Factura::where('lloguer_id', $lloguer->id)->where('any', $any)->get();

        return [
            round((float) $factures->sum('base'), 2),
            round((float) $factures->sum('irpf_import'), 2),
            $factures->count(),
            $factures->whereNull('moviment_id')->count(),
        ];
    }

    /**
     * Despeses de l'any repartides per casella del 184.
     *
     * Compta les dues formes de tenir una despesa, com fa la vista d'IRPF: el moviment
     * classificat com a despesa i la línia descomptada del cobrament (gestoria,
     * reparacions). Les repercussions no hi entren: ja són dins de la base cobrada.
     *
     * @return array<int, float>
     */
    private function caselles(Lloguer $lloguer, int $any): array
    {
        $mapatge = DB::table('g_categoria_lloguer_fiscal')->pluck('casella_184', 'categoria');

        $caselles = [];

        $suma = function (?int $casella, float $import) use (&$caselles) {
            // 0 vol dir «fora de la declaració»: una decisió explícita
            if ($casella === 0) {
                return;
            }

            $casella = $casella ?: self::CASELLA_PER_DEFECTE;
            $caselles[$casella] = round(($caselles[$casella] ?? 0) + $import, 2);
        };

        $despeses = MovimentLloguerDespesa::where('lloguer_id', $lloguer->id)
            ->whereHas('moviment', fn ($q) => $q->whereYear('data_moviment', $any)->where('exclou_lloguer', false))
            ->with('moviment')
            ->get();

        foreach ($despeses as $despesa) {
            $suma(
                $despesa->casella_184 ?? ($mapatge[$despesa->categoria] ?? null),
                abs((float) $despesa->moviment->import)
            );
        }

        $linies = MovimentLloguerIngresLinia::where('naturalesa', '!=', MovimentLloguerIngresLinia::REPERCUSSIO)
            ->whereHas('ingres', fn ($q) => $q->where('lloguer_id', $lloguer->id))
            ->with('ingres.moviment')
            ->get()
            ->filter(fn ($linia) => (int) $linia->ingres?->moviment?->data_moviment->format('Y') === $any);

        foreach ($linies as $linia) {
            $suma($mapatge[$linia->tipus] ?? null, abs((float) $linia->import));
        }

        ksort($caselles);

        return $caselles;
    }

    /**
     * Propietaris vigents de l'immoble durant l'exercici, amb quota i amortització.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function propietaris(Immoble $immoble, int $any): Collection
    {
        $inici = sprintf('%04d-01-01', $any);
        $fi    = sprintf('%04d-12-31', $any);

        return $immoble->propietaris()
            ->wherePivot('data_inici', '<=', $fi)
            ->where(fn ($q) => $q->whereNull('g_propietaris_immobles.data_fi')
                ->orWhere('g_propietaris_immobles.data_fi', '>=', $inici))
            ->get()
            ->map(fn (Persona $p) => [
                'persona_id'   => $p->id,
                'nom'          => trim($p->cognoms . ', ' . $p->nom),
                'nif'          => $p->nif,
                'quota'        => $p->pivot->quota !== null ? (float) $p->pivot->quota : null,
                'amortitzacio' => (float) ($p->pivot->amortitzacio_anual ?? 0),
            ])
            ->sortBy('nom')
            ->values();
    }

    /**
     * Rendiment, percentatge de participació i retenció de cada comuner.
     *
     * @param  Collection<int, array<string, mixed>>  $propietaris
     * @return array<int, array<string, mixed>>
     */
    private function comuners(Collection $propietaris, float $base, float $retencions): array
    {
        $comuners = $propietaris->map(function (array $p) use ($base) {
            $quota = $p['quota'];

            return $p + [
                'rendiment' => $quota === null
                    ? null
                    : round($quota / 100 * $base - $p['amortitzacio'], 2),
            ];
        });

        $total = round((float) $comuners->sum('rendiment'), 2);

        return $comuners->map(fn (array $c) => $c + [
            // La participació surt del rendiment, no de la quota: és el que canvia
            // quan cada comuner amortitza una cosa diferent.
            'participacio' => $c['rendiment'] !== null && $total != 0.0
                ? round($c['rendiment'] / $total * 100, 4)
                : null,
            // Les retencions, en canvi, es reparteixen per quota
            'retencio'     => $c['quota'] !== null ? round($c['quota'] / 100 * $retencions, 2) : null,
        ])->all();
    }

    /**
     * El que cal repassar abans de declarar.
     *
     * @param  array<int, array<string, mixed>>  $immobles
     * @return array<int, string>
     */
    private function avisos(array $immobles): array
    {
        $avisos = [];

        foreach ($immobles as $immoble) {
            $etiqueta = $immoble['lloguer'];
            $quotes   = array_column($immoble['comuners'], 'quota');

            if ($immoble['comuners'] === []) {
                // La causa quasi sempre és que `data_inici` diu quan es va donar d'alta el
                // registre i no quan es va adquirir la quota: val més dir-ho que deixar
                // l'usuari buscant un propietari que sí que hi és.
                $avisos[] = $immoble['titularitat_des_de'] === null
                    ? "{$etiqueta}: l'immoble no té cap propietari."
                    : "{$etiqueta}: la titularitat hi consta des del "
                        . date('d/m/Y', strtotime($immoble['titularitat_des_de']))
                        . ", posterior a l'exercici.";
                continue;
            }

            if (in_array(null, $quotes, true)) {
                $avisos[] = "{$etiqueta}: hi ha comuners sense quota de titularitat.";
            } elseif (abs(array_sum($quotes) - 100) > 0.01) {
                $avisos[] = "{$etiqueta}: les quotes sumen " . number_format(array_sum($quotes), 4) . ' %, no 100 %.';
            }

            if ($immoble['amortitzacio'] == 0.0) {
                $avisos[] = "{$etiqueta}: cap comuner no té amortització definida.";
            }

            if ($immoble['ingressos'] == 0.0) {
                $avisos[] = "{$etiqueta}: no hi ha cap factura de l'exercici.";
            } elseif ($immoble['rendiment_net'] < 0) {
                // Quasi sempre vol dir que hi falten factures, no que s'hi hagi perdut diners
                $avisos[] = "{$etiqueta}: les despeses superen els ingressos. Amb només "
                    . $immoble['factures'] . ' ' . ($immoble['factures'] === 1 ? 'factura' : 'factures')
                    . " a l'exercici, comprova que hi siguin totes.";
            }

            if ($immoble['factures_no_cobrades'] > 0) {
                $avisos[] = "{$etiqueta}: {$immoble['factures_no_cobrades']} "
                    . ($immoble['factures_no_cobrades'] === 1 ? 'factura encara no cobrada' : 'factures encara no cobrades')
                    . ' — els ingressos són de tot l\'any i les despeses només de les pagades.';
            }
        }

        return $avisos;
    }
}
