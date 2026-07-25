<?php

namespace App\Http\Controllers;

use App\Http\Requests\TaxaPatroRequest;
use App\Models\MovimentCompteCorrent;
use App\Models\TaxaPatro;
use App\Services\TaxesService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;

class ImpostosTaxesController extends Controller
{
    public function __construct(private readonly TaxesService $taxes)
    {
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
            'immoble'           => $m->getAttribute('immoble_taxa'),
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
        $perImmoble = $this->agrupaPerImmoble(
            $moviments->filter(fn (MovimentCompteCorrent $m) => in_array((int) $m->data_moviment->format('Y'), [$any, $anyAnterior], true)),
            $any,
            $anyAnterior
        );

        return Inertia::render('Impostos/Taxes', [
            'perImmoble'          => $perImmoble,
            'moviments'           => $llistat,
            'anyActual'           => $any,
            'anyAnterior'         => $anyAnterior,
            'anysDisponibles'     => $anysDisponibles,
            'totalGeneral'        => (float) $moviments->sum('import'),
            'categoriesPerCompte' => $categoriesPerCompte,
        ]);
    }

    /**
     * Categories (amb full_path) de cada compte indicat, indexades per compte_corrent_id.
     *
     * @param  array<int, int>  $compteIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function categoriesPerCompte(array $compteIds): array
    {
        if ($compteIds === []) {
            return [];
        }

        $categories = \App\Models\Categoria::whereIn('compte_corrent_id', $compteIds)
            ->orderBy('nom')
            ->get();

        $perId = $categories->keyBy('id');
        $resultat = [];

        foreach ($categories as $cat) {
            // full_path (arrel > ... > fulla)
            $path = [$cat->nom];
            $parentId = $cat->categoria_pare_id;
            while ($parentId && isset($perId[$parentId])) {
                $path[] = $perId[$parentId]->nom;
                $parentId = $perId[$parentId]->categoria_pare_id;
            }

            $resultat[$cat->compte_corrent_id][] = [
                'id'                => $cat->id,
                'compte_corrent_id' => $cat->compte_corrent_id,
                'nom'               => $cat->nom,
                'categoria_pare_id' => $cat->categoria_pare_id,
                'ordre'             => $cat->ordre,
                'full_path'         => implode(' > ', array_reverse($path)),
            ];
        }

        return $resultat;
    }

    /**
     * @param  Collection<int, MovimentCompteCorrent>  $moviments
     */
    private function agrupaPerImmoble(Collection $moviments, int $anyActual, int $anyAnterior): array
    {
        return $moviments
            ->groupBy(fn (MovimentCompteCorrent $m) => $m->getAttribute('immoble_taxa'))
            ->map(function (Collection $movsImmoble, string $immoble) use ($anyActual, $anyAnterior) {
                $tipus = $movsImmoble
                    ->groupBy(fn (MovimentCompteCorrent $m) => $m->getAttribute('tipus_taxa'))
                    ->map(function (Collection $movsTipus, string $tipus) use ($anyActual, $anyAnterior) {
                        return [
                            'tipus'             => $tipus,
                            'actual'            => (float) $this->totalAny($movsTipus, $anyActual),
                            'anterior'          => (float) $this->totalAny($movsTipus, $anyAnterior),
                            'moviments_actual'  => $this->movimentsAny($movsTipus, $anyActual),
                            'moviments_anterior' => $this->movimentsAny($movsTipus, $anyAnterior),
                        ];
                    })
                    ->sortBy('tipus')
                    ->values();

                return [
                    'immoble'        => $immoble,
                    'tipus'          => $tipus,
                    'total_actual'   => (float) $this->totalAny($movsImmoble, $anyActual),
                    'total_anterior' => (float) $this->totalAny($movsImmoble, $anyAnterior),
                ];
            })
            ->sortBy('immoble')
            ->values()
            ->all();
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
     * @param  Collection<int, MovimentCompteCorrent>  $moviments
     * @return array<int, array{data: string, import: float}>
     */
    private function movimentsAny(Collection $moviments, int $any): array
    {
        return $moviments
            ->filter(fn (MovimentCompteCorrent $m) => (int) $m->data_moviment->format('Y') === $any)
            ->map(fn (MovimentCompteCorrent $m) => [
                'data'   => $m->data_moviment->toDateString(),
                'import' => (float) $m->import,
            ])
            ->sortBy('data')
            ->values()
            ->all();
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
