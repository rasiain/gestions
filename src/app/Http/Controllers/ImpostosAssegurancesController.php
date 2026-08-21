<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\CategoriesPerCompte;
use App\Models\MovimentCompteCorrent;
use App\Services\AssegurancesEstatService;
use App\Services\AssegurancesService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;

class ImpostosAssegurancesController extends Controller
{
    use CategoriesPerCompte;

    public function __construct(
        private readonly AssegurancesService $assegurances,
        private readonly AssegurancesEstatService $estats,
    ) {
    }

    public function index(Request $request)
    {
        $any         = $request->integer('any', (int) date('Y'));
        $anyAnterior = $any - 1;
        $referencia  = $this->referencia($any);

        $moviments = $this->assegurances->moviments();

        // Vista 2 — llistat pla (tots els anys, data descendent).
        $llistat = $moviments->map(fn (MovimentCompteCorrent $m) => [
            'id'                => $m->id,
            'compte_corrent_id' => $m->compte_corrent_id,
            'data'              => $m->data_moviment->toDateString(),
            'compte'            => $m->compteCorrent?->nom,
            'objecte'           => $m->getAttribute('objecte_asseguranca'),
            'tipus'             => $m->getAttribute('tipus_asseguranca'),
            'companyia'         => $m->getAttribute('companyia_asseguranca'),
            'concepte'          => $m->concepte_original,        // text brut (immutable) — cerca/visualització
            'concepte_editable' => $m->concepte?->concepte,      // text editable (MovimentConcepte)
            'concepte_id'       => $m->concepte_id,
            'notes'             => $m->notes,
            'import'            => (float) $m->import,
            'categoria_id'      => $m->categoria_id,
            'categoria_nom'     => $m->categoria?->nom,
        ])->values();

        $anysDisponibles = $moviments
            ->map(fn (MovimentCompteCorrent $m) => (int) $m->data_moviment->format('Y'))
            ->unique()
            ->sortDesc()
            ->values();

        $seccions = $this->agrupaPerObjecte(
            $moviments->filter(fn (MovimentCompteCorrent $m) => in_array((int) $m->data_moviment->format('Y'), [$any, $anyAnterior], true)),
            $any,
            $anyAnterior,
            $this->estats->estats($moviments, $any, $referencia)
        );

        return Inertia::render('Impostos/Assegurances', [
            'seccions'            => $seccions,
            'moviments'           => $llistat,
            'anyActual'           => $any,
            'anyAnterior'         => $anyAnterior,
            'anysDisponibles'     => $anysDisponibles,
            // Fins on arriba l'any en curs: la comparativa retalla l'any anterior aquí
            'dataReferencia'      => $referencia->toDateString(),
            'categoriesPerCompte' => $this->categoriesPerCompte(
                $moviments->pluck('compte_corrent_id')->unique()->filter()->values()->all()
            ),
        ]);
    }

    /**
     * Fins on ha arribat l'any demanat: avui si encara corre, el 31 de desembre
     * si ja s'ha acabat.
     */
    private function referencia(int $any): CarbonInterface
    {
        $fiAny = Carbon::create($any, 12, 31)->endOfDay();
        $avui  = Carbon::today();

        return $avui->lt($fiAny) ? $avui : $fiAny;
    }

    /**
     * Tres seccions —immobles de lloguer, altres immobles i la resta—, cadascuna
     * amb els objectes assegurats agrupats per població.
     *
     * @param  Collection<int, MovimentCompteCorrent>  $moviments
     * @param  array<string, array<string, mixed>>  $estats
     * @return array<int, array<string, mixed>>
     */
    private function agrupaPerObjecte(Collection $moviments, int $anyActual, int $anyAnterior, array $estats): array
    {
        $grups = $moviments
            ->groupBy(fn (MovimentCompteCorrent $m) => $m->getAttribute('grup_asseguranca'))
            ->map(function (Collection $movsGrup, string $grup) use ($anyActual, $anyAnterior, $estats) {
                $polisses = $movsGrup
                    ->groupBy(fn (MovimentCompteCorrent $m) => $m->getAttribute('tipus_asseguranca'))
                    ->map(fn (Collection $movsTipus, string $tipus) => [
                        'tipus'              => $tipus,
                        // Una pòlissa pot haver canviat d'asseguradora: hi són totes
                        'companyies'         => $movsTipus
                            ->map(fn (MovimentCompteCorrent $m) => $m->getAttribute('companyia_asseguranca'))
                            ->filter()->unique()->values()->all(),
                        'estat'              => $estats[AssegurancesEstatService::clau($grup, $tipus)] ?? null,
                        'moviments_actual'   => $this->movimentsAny($movsTipus, $anyActual),
                        'moviments_anterior' => $this->movimentsAny($movsTipus, $anyAnterior),
                    ])
                    ->sortBy('tipus')
                    ->values();

                $primer = fn (string $atribut) => $movsGrup
                    ->map(fn (MovimentCompteCorrent $m) => $m->getAttribute($atribut))
                    ->filter()
                    ->first();

                $suma = fn (string $camp) => round((float) $polisses->sum(fn (array $p) => $p['estat'][$camp] ?? 0), 2);

                return [
                    'objecte'         => $primer('objecte_asseguranca'),
                    'poblacio'        => $primer('poblacio_asseguranca'),
                    'lloguer'         => $primer('lloguer_nom_asseguranca'),
                    'grup'            => $grup,
                    'immoble_id'      => $primer('immoble_id_asseguranca'),
                    'seccio'          => match (true) {
                        $primer('immoble_id_asseguranca') !== null    => 'lloguer',
                        $primer('immoble_arbre_asseguranca') !== null => 'identificat',
                        default                                       => 'resta',
                    },
                    // Pista de com s'ha detectat: on està classificada la despesa
                    'paths'           => $movsGrup
                        ->map(fn (MovimentCompteCorrent $m) => $m->getAttribute('path_asseguranca'))
                        ->unique()->sort()->values()->all(),
                    'polisses'        => $polisses,
                    'total_actual'    => $suma('pagat'),
                    'total_anterior'  => $suma('anterior_total'),
                    'total_a_data'    => $suma('anterior_a_data'),
                ];
            })
            ->values();

        $titols = [
            'lloguer'     => ['Immobles de lloguer', "Lligats a un lloguer per les despeses classificades dels seus moviments."],
            'identificat' => ['Altres immobles', "L'immoble surt de l'arbre de categories, però cap despesa de lloguer no l'hi lliga."],
            'resta'       => ['Vehicles, persones i altres', "Pòlisses que no són de cap immoble, o que l'arbre no sap de qui són."],
        ];

        return collect($titols)
            ->map(fn (array $titol, string $clau) => [
                'clau'       => $clau,
                'titol'      => $titol[0],
                'descripcio' => $titol[1],
                'poblacions' => $grups
                    ->where('seccio', $clau)
                    ->groupBy(fn (array $g) => $g['poblacio'] ?? '')
                    ->map(fn (Collection $gs, string $poblacio) => [
                        'poblacio' => $poblacio !== '' ? $poblacio : null,
                        'objectes' => $gs->sortBy('objecte')->values()->all(),
                    ])
                    // Les que no tenen població, al final
                    ->sortBy(fn (array $p) => $p['poblacio'] === null ? "\u{FFFF}" : AssegurancesService::normalitza($p['poblacio']))
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * El compte hi va perquè un mateix objecte pot pagar-se des de comptes
     * diferents, i és sovint l'única pista de qui paga la pòlissa.
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
}
