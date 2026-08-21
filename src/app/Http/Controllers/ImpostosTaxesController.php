<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\CategoriesPerCompte;
use App\Http\Requests\TaxaPatroRequest;
use App\Http\Requests\TaxaRebutRequest;
use App\Models\MovimentCompteCorrent;
use App\Models\TaxaPatro;
use App\Models\TaxaRebut;
use App\Services\TaxesEstatService;
use App\Services\TaxesService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;

class ImpostosTaxesController extends Controller
{
    use CategoriesPerCompte;

    public function __construct(
        private readonly TaxesService $taxes,
        private readonly TaxesEstatService $estats,
    ) {
    }

    public function index(Request $request)
    {
        $any = $request->integer('any', (int) date('Y'));
        $anyAnterior = $any - 1;

        $moviments = $this->taxes->moviments();

        // Vista 2 — llistat pla (tots els anys, data descendent).
        $llistat = $moviments->map(fn (MovimentCompteCorrent $m) => [
            'id'                => $m->id,
            'compte_corrent_id' => $m->compte_corrent_id,
            'data'              => $m->data_moviment->toDateString(),
            'compte'            => $m->compteCorrent?->nom,
            'immoble'           => $m->getAttribute('immoble_taxa') ?? 'No identificat',
            'tipus'             => $m->getAttribute('tipus_taxa'),
            'concepte'          => $m->concepte_original,        // text brut (immutable) — cerca/visualització
            'concepte_editable' => $m->concepte?->concepte,      // text editable (MovimentConcepte)
            'concepte_id'       => $m->concepte_id,
            'notes'             => $m->notes,
            'import'            => (float) $m->import,
            'categoria_id'      => $m->categoria_id,
            'categoria_nom'     => $m->categoria?->nom,
        ])->values();

        // Categories per compte (amb full_path) per al selector d'edició.
        $categoriesPerCompte = $this->categoriesPerCompte(
            $moviments->pluck('compte_corrent_id')->unique()->filter()->values()->all()
        );

        // Anys disponibles (per al selector de la Vista 1).
        $anysDisponibles = $moviments
            ->map(fn (MovimentCompteCorrent $m) => (int) $m->data_moviment->format('Y'))
            ->unique()
            ->sortDesc()
            ->values();

        // Vista 1 — per immoble, comparativa any actual vs anterior.
        $seccions = $this->agrupaPerImmoble(
            $moviments->filter(fn (MovimentCompteCorrent $m) => in_array((int) $m->data_moviment->format('Y'), [$any, $anyAnterior], true)),
            $any,
            $anyAnterior,
            $this->estats->estats($moviments, $any)
        );

        return Inertia::render('Impostos/Taxes', [
            'seccions'            => $seccions,
            'moviments'           => $llistat,
            'anyActual'           => $any,
            'anyAnterior'         => $anyAnterior,
            'anysDisponibles'     => $anysDisponibles,
            'totalGeneral'        => (float) $moviments->sum('import'),
            'categoriesPerCompte' => $categoriesPerCompte,
        ]);
    }

    /**
     * @param  Collection<int, MovimentCompteCorrent>  $moviments
     */
    /**
     * Tres seccions —de lloguer, identificats i la resta—, cadascuna amb els
     * immobles agrupats per població.
     */
    private function agrupaPerImmoble(Collection $moviments, int $anyActual, int $anyAnterior, array $estats): array
    {
        $grups = $moviments
            // Els impostos puntuals que no són de cap immoble no hi pinten res
            ->reject(fn (MovimentCompteCorrent $m) => $m->getAttribute('ocult_taxa'))
            ->groupBy(fn (MovimentCompteCorrent $m) => $m->getAttribute('grup_taxa'))
            ->map(function (Collection $movsImmoble, string $grup) use ($anyActual, $anyAnterior, $estats) {
                $tipus = $movsImmoble
                    ->groupBy(fn (MovimentCompteCorrent $m) => $m->getAttribute('tipus_taxa'))
                    ->map(function (Collection $movsTipus, string $tipus) use ($anyActual, $anyAnterior, $estats, $grup) {
                        return [
                            'tipus'             => $tipus,
                            'actual'            => (float) $this->totalAny($movsTipus, $anyActual),
                            'anterior'          => (float) $this->totalAny($movsTipus, $anyAnterior),
                            'moviments_actual'  => $this->movimentsAny($movsTipus, $anyActual),
                            'moviments_anterior' => $this->movimentsAny($movsTipus, $anyAnterior),
                            // Null quan no s'ha definit el total del rebut
                            'estat'             => $estats[\App\Models\TaxaRebut::clauDe($grup, $tipus)] ?? null,
                        ];
                    })
                    ->sortBy('tipus')
                    ->values();

                $primer = fn (string $atribut) => $movsImmoble
                    ->map(fn (MovimentCompteCorrent $m) => $m->getAttribute($atribut))
                    ->filter()
                    ->first();

                $etiqueta = $primer('immoble_taxa');

                return [
                    'immoble'        => $etiqueta ?? 'No identificat',
                    'poblacio'       => $primer('poblacio_taxa'),
                    'lloguer'        => $primer('lloguer_nom_taxa'),
                    // Identifiquen la fila per poder-hi desar el total del rebut
                    'grup'           => $grup,
                    'immoble_id'     => $primer('immoble_id_taxa'),
                    'seccio'         => match (true) {
                        $primer('immoble_id_taxa') !== null => 'lloguer',
                        $etiqueta !== null                  => 'identificat',
                        default                             => 'resta',
                    },
                    // Pista per als no identificats: on està classificada la despesa
                    'paths'          => $movsImmoble
                        ->map(fn (MovimentCompteCorrent $m) => $m->getAttribute('path_taxa'))
                        ->unique()->sort()->values()->all(),
                    'tipus'          => $tipus,
                    'total_actual'   => (float) $this->totalAny($movsImmoble, $anyActual),
                    'total_anterior' => (float) $this->totalAny($movsImmoble, $anyAnterior),
                ];
            })
            ->values();

        $titols = [
            'lloguer'     => 'Immobles de lloguer',
            'identificat' => 'Altres immobles identificats',
            'resta'       => 'Sense immoble identificat',
        ];

        return collect($titols)
            ->map(fn (string $titol, string $clau) => [
                'clau'       => $clau,
                'titol'      => $titol,
                'poblacions' => $grups
                    ->where('seccio', $clau)
                    ->groupBy(fn (array $g) => $g['poblacio'] ?? '')
                    ->map(fn (Collection $gs, string $poblacio) => [
                        'poblacio' => $poblacio !== '' ? $poblacio : null,
                        'immobles' => $gs->sortBy('immoble')->values()->all(),
                    ])
                    // Les que no tenen població, al final
                    ->sortBy(fn (array $p) => $p['poblacio'] === null ? "\u{FFFF}" : self::normalitzaOrdre($p['poblacio']))
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    private static function normalitzaOrdre(string $text): string
    {
        return \App\Services\TaxesService::normalitza($text);
    }

    /**
     * @param  Collection<int, MovimentCompteCorrent>  $moviments
     */
    private function totalAny(Collection $moviments, int $any): float
    {
        return (float) $moviments
            ->filter(fn (MovimentCompteCorrent $m) => (int) $m->data_moviment->format('Y') === $any)
            ->sum('import');
    }

    /**
     * El compte hi va perquè un mateix grup pot rebre taxes de comptes diferents,
     * i sense població ni immoble el compte és sovint l'única pista de qui paga.
     *
     * @param  Collection<int, MovimentCompteCorrent>  $moviments
     * @return array<int, array{data: string, import: float, compte: ?string, compte_digits: ?string}>
     */
    private function movimentsAny(Collection $moviments, int $any): array
    {
        return $moviments
            ->filter(fn (MovimentCompteCorrent $m) => (int) $m->data_moviment->format('Y') === $any)
            ->map(fn (MovimentCompteCorrent $m) => [
                'data'          => $m->data_moviment->toDateString(),
                'import'        => (float) $m->import,
                'compte'        => $m->compteCorrent?->nom,
                'compte_digits' => $m->compteCorrent
                    ? substr((string) $m->compteCorrent->compte_corrent, -4)
                    : null,
            ])
            ->sortBy('data')
            ->values()
            ->all();
    }

    // ---- Rebuts (total anual d'una taxa) ----

    public function storeRebut(TaxaRebutRequest $request)
    {
        $dades = $this->dadesRebut($request);

        // Un rebut per grup, tipus, any i referència: si ja hi és, s'actualitza
        TaxaRebut::updateOrCreate(
            $request->only(['grup', 'tipus', 'any']) + ['referencia' => $dades['referencia']],
            $dades
        );

        return back();
    }

    public function updateRebut(TaxaRebutRequest $request, TaxaRebut $rebut)
    {
        $rebut->update($this->dadesRebut($request));

        return back();
    }

    public function destroyRebut(TaxaRebut $rebut)
    {
        $rebut->delete();

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function dadesRebut(TaxaRebutRequest $request): array
    {
        $repercutible = $request->boolean('repercutible');

        return [
            'grup'                 => $request->input('grup'),
            'immoble_id'           => $request->input('immoble_id'),
            'tipus'                => $request->input('tipus'),
            'any'                  => $request->integer('any'),
            'referencia'           => (string) $request->input('referencia', ''),
            'import_total'         => $request->input('import_total'),
            'terminis_previstos'   => $request->input('terminis_previstos'),
            'repercutible'         => $repercutible,
            // Sense concepte no hi ha res amb què casar la repercussió
            'concepte_repercussio' => $repercutible
                ? ($request->input('concepte_repercussio') ?: 'escombraries')
                : null,
            'notes'                => $request->input('notes'),
        ];
    }

    // ---- Configuració de patrons ----

    public function patrons()
    {
        return Inertia::render('Impostos/TaxesConfig', [
            'patrons' => TaxaPatro::orderBy('ordre')->orderBy('etiqueta')->get(),
        ]);
    }

    public function storePatro(TaxaPatroRequest $request)
    {
        TaxaPatro::create([
            'etiqueta' => $request->input('etiqueta'),
            'patro'    => $request->input('patro'),
            'actiu'    => $request->boolean('actiu', true),
            'ordre'    => $request->integer('ordre', 0),
        ]);

        return back();
    }

    public function updatePatro(TaxaPatroRequest $request, TaxaPatro $patro)
    {
        $patro->update([
            'etiqueta' => $request->input('etiqueta'),
            'patro'    => $request->input('patro'),
            'actiu'    => $request->boolean('actiu', true),
            'ordre'    => $request->integer('ordre', 0),
        ]);

        return back();
    }

    public function destroyPatro(TaxaPatro $patro)
    {
        $patro->delete();

        return back();
    }
}
