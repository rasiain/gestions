<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\CategoriesPerCompte;
use App\Http\Requests\AssegurancaPatroRequest;
use App\Http\Requests\AssegurancaPolissaRequest;
use App\Models\AssegurancaPatro;
use App\Models\AssegurancaPolissa;
use App\Models\Categoria;
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

    // ---- Configuració: patrons de detecció i ajustos manuals ----

    public function config(Request $request)
    {
        $cerca = trim((string) $request->input('cerca', ''));
        // Els moviments que es compten són els de l'any en curs, que és el que
        // s'està classificant; «tots» hi és per als camins que fa temps que no
        // es fan servir.
        $any = $request->input('any', (string) date('Y')) === 'tots'
            ? null
            : (int) $request->input('any', date('Y'));

        return Inertia::render('Impostos/AssegurancesConfig', [
            'patrons'   => AssegurancaPatro::orderBy('ordre')->orderBy('etiqueta')->get(),
            'camins'    => $this->caminsDetectats($any),
            'any'       => $any,
            'cerca'     => $cerca,
            'candidats' => $this->candidats($cerca),
        ]);
    }

    /**
     * Un registre per CAMÍ de categoria: què n'ha resolt el detector i quin
     * ajust hi ha, si n'hi ha. El mateix camí existeix a cada compte que
     * l'hagi importat i totes aquelles categories volen dir el mateix, de
     * manera que s'editen juntes.
     *
     * Cada camí porta els comptes on és, amb els moviments de l'any demanat:
     * és el que permet anar de la categoria al moviment i comprovar-lo.
     *
     * @param  int|null  $any  null per comptar-los tots
     * @return array<int, array<string, mixed>>
     */
    private function caminsDetectats(?int $any): array
    {
        $categories = Categoria::with('compteCorrent')->get()->keyBy('id');
        $paths      = $this->assegurances->pathsPerCategoria($categories);
        $detectades = $this->assegurances->categoriesAsseguranca();
        $ajustos    = AssegurancaPolissa::all()->keyBy('categoria_id');

        // Les que tenen ajust però ja no es detecten també hi han de sortir:
        // si no, no hi hauria manera de treure'l.
        $ids = array_unique(array_merge(array_keys($detectades), $ajustos->keys()->all()));

        $consulta = MovimentCompteCorrent::whereIn('categoria_id', $ids)
            ->selectRaw('categoria_id, count(*) as total')
            ->groupBy('categoria_id');

        if ($any !== null) {
            $consulta->whereYear('data_moviment', $any);
        }

        $moviments = $consulta->pluck('total', 'categoria_id');

        $camins = [];
        foreach ($ids as $id) {
            $cami      = $paths[$id] ?? null;
            $categoria = $categories->get($id);
            if ($cami === null || $categoria === null) {
                continue;
            }

            $info  = $detectades[$id] ?? null;
            $ajust = $ajustos->get($id);
            $actual = $camins[$cami] ?? [
                'cami'       => $cami,
                'categories' => 0,
                'moviments'  => 0,
                'comptes'    => [],
                'detectada'  => false,
                'tipus'      => null,
                'objecte'    => null,
                'poblacio'   => null,
                'companyia'  => null,
                'ajust'      => null,
            ];

            $compte = $categoria->compteCorrent;

            $camins[$cami] = [
                'cami'       => $cami,
                'categories' => $actual['categories'] + 1,
                'moviments'  => $actual['moviments'] + (int) ($moviments[$id] ?? 0),
                'comptes'    => array_merge($actual['comptes'], [[
                    'categoria_id'      => $id,
                    'compte_corrent_id' => $categoria->compte_corrent_id,
                    'compte'            => $compte?->nom,
                    'digits'            => $compte ? substr((string) $compte->compte_corrent, -4) : null,
                    'moviments'         => (int) ($moviments[$id] ?? 0),
                ]]),
                'detectada'  => $actual['detectada'] || $info !== null,
                // El resultat d'ara: el que es corregeix editant l'ajust
                'tipus'      => $actual['tipus'] ?? $info['tipus'] ?? null,
                'objecte'    => $actual['objecte'] ?? $info['objecte'] ?? null,
                'poblacio'   => $actual['poblacio'] ?? $info['poblacio_ajust'] ?? $info['poblacio'] ?? null,
                'companyia'  => $actual['companyia'] ?? $info['companyia'] ?? null,
                'ajust'      => $actual['ajust'] ?? ($ajust === null ? null : [
                    'objecte'   => $ajust->objecte,
                    'poblacio'  => $ajust->poblacio,
                    'companyia' => $ajust->companyia,
                    'tipus'     => $ajust->tipus,
                    'inclou'    => $ajust->inclou,
                    'ocult'     => $ajust->ocult,
                ]),
            ];
        }

        ksort($camins);

        return array_values(array_map(function (array $cami) {
            usort($cami['comptes'], fn (array $a, array $b) => strcmp((string) $a['compte'], (string) $b['compte']));

            return $cami;
        }, $camins));
    }

    /**
     * Camins que NO es detecten i que encaixen amb la cerca, per poder-hi
     * afegir una inclusió manual. Es cerca al servidor: l'arbre té milers de
     * categories i no val la pena enviar-lo sencer.
     *
     * @return array<int, array{cami: string, categories: int}>
     */
    private function candidats(string $cerca): array
    {
        if (mb_strlen($cerca) < 3) {
            return [];
        }

        $terme      = AssegurancesService::normalitza($cerca);
        $detectades = $this->assegurances->categoriesAsseguranca();

        $camins = [];
        foreach ($this->assegurances->pathsPerCategoria() as $id => $cami) {
            if (isset($detectades[$id]) || ! str_contains(AssegurancesService::normalitza($cami), $terme)) {
                continue;
            }

            $camins[$cami] = ($camins[$cami] ?? 0) + 1;
        }

        ksort($camins);

        return array_slice(
            array_map(fn (string $cami, int $n) => ['cami' => $cami, 'categories' => $n], array_keys($camins), $camins),
            0,
            30
        );
    }

    public function storePatro(AssegurancaPatroRequest $request)
    {
        AssegurancaPatro::create($this->dadesPatro($request));

        return back();
    }

    public function updatePatro(AssegurancaPatroRequest $request, AssegurancaPatro $patro)
    {
        $patro->update($this->dadesPatro($request));

        return back();
    }

    public function destroyPatro(AssegurancaPatro $patro)
    {
        $patro->delete();

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function dadesPatro(AssegurancaPatroRequest $request): array
    {
        return [
            'etiqueta' => $request->input('etiqueta'),
            'patro'    => $request->input('patro'),
            'actiu'    => $request->boolean('actiu', true),
            'ordre'    => $request->integer('ordre', 0),
        ];
    }

    /**
     * Desa l'ajust de totes les categories d'un camí. Un ajust sense cap valor
     * no és cap decisió: s'esborra.
     */
    public function updateAjust(AssegurancaPolissaRequest $request)
    {
        $cami = (string) $request->input('cami');
        $ids  = array_keys(array_filter(
            $this->assegurances->pathsPerCategoria(),
            fn (string $path) => $path === $cami
        ));

        if ($ids === []) {
            return back()->withErrors(['cami' => 'Ja no hi ha cap categoria amb aquest camí.']);
        }

        $dades = [
            'objecte'   => $request->input('objecte') ?: null,
            'poblacio'  => $request->input('poblacio') ?: null,
            'companyia' => $request->input('companyia') ?: null,
            'tipus'     => $request->input('tipus') ?: null,
            'inclou'    => $request->boolean('inclou'),
            'ocult'     => $request->boolean('ocult'),
        ];

        if (collect($dades)->every(fn ($valor) => $valor === null || $valor === false)) {
            AssegurancaPolissa::whereIn('categoria_id', $ids)->delete();

            return back();
        }

        foreach ($ids as $id) {
            AssegurancaPolissa::updateOrCreate(['categoria_id' => $id], $dades);
        }

        return back();
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
            // El que un ajust manual marca com a no-pòlissa no hi pinta res
            ->reject(fn (MovimentCompteCorrent $m) => $m->getAttribute('ocult_asseguranca'))
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
                    'total_previsio'  => $suma('previsio'),
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
