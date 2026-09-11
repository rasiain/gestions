<?php

namespace App\Http\Controllers;

use App\Http\Requests\CapitalSocialContracteRequest;
use App\Models\CapitalSocialContracte;
use App\Models\CapitalSocialRendiment;
use App\Models\CapitalSocialValor;
use App\Models\CompteCorrent;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

/**
 * El capital social de les cooperatives de crèdit.
 *
 * Va com la renda fixa —contractes, una sèrie de valors per data i els rendiments cobrats—
 * però el valor són títols × nominal unitari, que és com ve a l'extracte, i no hi ha
 * catàleg de productes: el capital social és de l'entitat del compte.
 */
class CapitalSocialController extends Controller
{
    public function index(Request $request)
    {
        $contractes = CapitalSocialContracte::with([
                'compteCorrent.titulars',
                'compteCorrent.entitatRelacio',
                'titularsPropis',
                'compteRendiment',
                'valors',
                'rendiments',
            ])
            ->get()
            ->map(fn (CapitalSocialContracte $c) => $this->formatContracte($c))
            ->sortBy('nom')
            ->values();

        return Inertia::render('Inversions/CapitalSocial', [
            'contractes'       => $contractes,
            'totalsPerTitular' => $this->totalsPerTitular($contractes),
            // Els comptes on pot viure el capital social i els comptes on arriben els interessos
            'comptesCapital'   => $this->comptes(['capital_social']),
            'comptesRendiment' => $this->comptes(['corrent']),
            // Per a les aportacions sense compte, que els titulars s'han de triar a mà
            'persones'         => \App\Models\Persona::orderBy('nom')->orderBy('cognoms')
                ->get()->map(fn ($p) => ['id' => $p->id, 'nom' => trim($p->nom . ' ' . $p->cognoms)]),
        ]);
    }

    /**
     * @param  array<int, string>  $tipus
     */
    private function comptes(array $tipus)
    {
        return CompteCorrent::whereIn('tipus', $tipus)
            ->with('titulars')
            ->orderBy('ordre')
            ->get()
            ->map(fn (CompteCorrent $c) => [
                'id'       => $c->id,
                'nom'      => $c->nom ?? $c->compte_corrent,
                'compte'   => $c->compte_corrent,
                'entitat'  => $c->entitat,
                'titulars' => $c->titulars->map(fn ($t) => trim($t->nom . ' ' . $t->cognoms))->join(', '),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatContracte(CapitalSocialContracte $c): array
    {
        $vigent   = $c->valorVigentA();
        $titulars = $c->titulars();

        return [
            'id'                  => $c->id,
            // El nom de la fila: el compte si en té, i si no la cooperativa que l'emet
            'nom'                 => $c->compteCorrent?->nom ?? $c->compteCorrent?->compte_corrent ?? $c->emissor ?? 'Capital social',
            'emissor'             => $c->emissor,
            'nif'                 => $c->nif,
            'compte_corrent_id'   => $c->compte_corrent_id,
            'compte_nom'          => $c->compteCorrent?->nom ?? $c->compteCorrent?->compte_corrent ?? '',
            'compte_referencia'   => $c->compteCorrent?->compte_corrent ?? '',
            'entitat'             => $c->emissor_visible,
            'compte_rendiment_id'  => $c->compte_rendiment_id,
            'compte_rendiment_nom' => $c->compteRendiment?->nom ?? $c->compteRendiment?->compte_corrent,
            // El darrer estat conegut: els números que l'extracte dona a 31/12
            'titols'              => $vigent?->titols,
            'valor_unitari'       => $vigent === null ? null : (float) $vigent->valor_unitari,
            'valor'               => $c->valorAData(),
            'data_valor'          => $vigent?->data->toDateString(),
            'data_alta'           => $c->data_alta?->toDateString(),
            'notes'               => $c->notes,
            'titulars'            => $titulars->map(fn ($t) => ['id' => $t->id, 'nom' => trim($t->nom . ' ' . $t->cognoms)])->values(),
            'titulars_propis'     => $c->titularsPropis->pluck('id')->values(),
            'valors'              => $this->valorsAmbVariacio($c),
            // Els interessos cobrats, i el que han donat cada any
            'rendiments'          => $c->rendiments->sortByDesc(fn ($r) => $r->data->timestamp)
                ->map(fn (CapitalSocialRendiment $r) => [
                    'id'       => $r->id,
                    'data'     => $r->data->toDateString(),
                    'import'   => (float) $r->import,
                    'retencio' => $r->retencio === null ? null : (float) $r->retencio,
                    'net'      => $r->net,
                    'notes'    => $r->notes,
                ])->values(),
            'rendiment_per_any'   => $c->rendiments
                ->groupBy(fn (CapitalSocialRendiment $r) => $r->data->format('Y'))
                ->map(fn ($any) => round((float) $any->sum('import'), 2))
                ->sortKeysDesc(),
        ];
    }

    /**
     * Els valors del contracte, del més recent al més antic, amb d'on ve cada canvi.
     *
     * Entre dos valors seguits el total pot canviar per dues raons ben diferents: perquè hi
     * ha **títols nous** (una aportació) o perquè els que hi havia **valen més** (una
     * revaloració, que al banc arriba com un moviment «REVALORACIO TITOLS»). Es dedueixen
     * dels dos valors i no es desen enlloc, com el total: 11 títols que passen de 100 a 102
     * són 22 € de revaloració, i no cal apuntar-ho a part per saber-ho.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function valorsAmbVariacio(CapitalSocialContracte $c)
    {
        $anterior = null;

        return $c->valors
            ->sortBy(fn (CapitalSocialValor $v) => $v->data->timestamp)
            ->map(function (CapitalSocialValor $v) use (&$anterior) {
                $fila = [
                    'id'            => $v->id,
                    'data'          => $v->data->toDateString(),
                    'titols'        => $v->titols,
                    'valor_unitari' => $v->valor_unitari === null ? null : (float) $v->valor_unitari,
                    'total'         => $v->total,
                    // Del primer valor no se'n pot dir res: no hi ha res amb què comparar-lo
                    'aportacio'     => null,
                    'revaloracio'   => null,
                ];

                // Sense títols no es pot dir d'on ve el canvi: només se sap el saldo
                if ($anterior !== null && $v->titols !== null && $anterior->titols !== null) {
                    $unitariAnterior = (float) $anterior->valor_unitari;

                    // Els títols nous, al preu que hi havia; i el canvi de preu, a tots els títols
                    $fila['aportacio']   = round(($v->titols - $anterior->titols) * $unitariAnterior, 2);
                    $fila['revaloracio'] = round($v->titols * ((float) $v->valor_unitari - $unitariAnterior), 2);
                }

                $anterior = $v;

                return $fila;
            })
            ->sortByDesc('data')
            ->values();
    }

    /**
     * El valor que té cada titular, repartit a parts iguals entre els titulars del compte
     * del contracte, com als comptes corrents, als fons i a la renda fixa.
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $contractes
     * @return array<int, array<string, mixed>>
     */
    private function totalsPerTitular($contractes): array
    {
        $perTitular = [];

        foreach ($contractes as $contracte) {
            $valor    = (float) $contracte['valor'];
            $titulars = $contracte['titulars']->all() ?: [['id' => null, 'nom' => 'Sense titular']];
            $parts    = count($titulars);
            $repartit = 0.0;

            foreach ($titulars as $i => $titular) {
                // L'últim s'endú el residu: la suma de les parts ha de donar el valor
                $part = $i === $parts - 1
                    ? round($valor - $repartit, 2)
                    : round($valor / $parts, 2);
                $repartit += $part;

                $clau = $titular['id'] ?? 'sense';

                $perTitular[$clau] ??= [
                    'id'         => $titular['id'],
                    'nom'        => $titular['nom'],
                    'total'      => 0.0,
                    'contractes' => [],
                ];

                $perTitular[$clau]['total'] += $part;
                $perTitular[$clau]['contractes'][] = [
                    'compte'   => $contracte['nom'],
                    'valor'    => $valor,
                    'titulars' => $parts,
                    'part'     => $part,
                ];
            }
        }

        return collect($perTitular)
            ->map(fn (array $t) => ['total' => round($t['total'], 2)] + $t)
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    // ---- Contractes ----

    public function storeContracte(CapitalSocialContracteRequest $request)
    {
        $contracte = CapitalSocialContracte::create($request->dadesDelContracte());
        $contracte->titularsPropis()->sync($request->titularsPropis());

        return back();
    }

    public function updateContracte(CapitalSocialContracteRequest $request, CapitalSocialContracte $contracte)
    {
        $contracte->update($request->dadesDelContracte());
        $contracte->titularsPropis()->sync($request->titularsPropis());

        return back();
    }

    public function destroyContracte(CapitalSocialContracte $contracte)
    {
        $contracte->delete();

        return back();
    }

    // ---- Valors i rendiments ----

    public function storeValor(Request $request)
    {
        // O el desglossament en títols, o el saldo tal qual: n'hi ha d'haver un dels dos
        $dades = $request->validate([
            'contracte_id'  => ['required', 'integer', 'exists:g_cs_contractes,id'],
            'data'          => ['required', 'date'],
            'titols'        => ['nullable', 'integer', 'min:0', 'required_with:valor_unitari'],
            'valor_unitari' => ['nullable', 'numeric', 'min:0', 'required_with:titols'],
            'import'        => ['nullable', 'numeric', 'required_without:titols'],
        ], [
            'import.required_without' => 'Escriu el saldo, o bé els títols i el nominal unitari.',
        ]);

        // Un valor per data: tornar-hi el corregeix. La data va normalitzada perquè el
        // cast la desa a mitjanit i buscar-la en sec no trobaria la que ja hi és.
        CapitalSocialValor::updateOrCreate(
            ['contracte_id' => $dades['contracte_id'], 'data' => Carbon::parse($dades['data'])->startOfDay()],
            [
                'titols'        => $dades['titols'] ?? null,
                'valor_unitari' => $dades['valor_unitari'] ?? null,
                // Amb títols el total es multiplica, i desar-lo a més seria una dada que els pot contradir
                'import'        => isset($dades['titols']) ? null : $dades['import'],
            ],
        );

        return back();
    }

    public function destroyValor(CapitalSocialValor $valor)
    {
        $valor->delete();

        return back();
    }

    public function storeRendiment(Request $request)
    {
        CapitalSocialRendiment::create($request->validate([
            'contracte_id' => ['required', 'integer', 'exists:g_cs_contractes,id'],
            'data'         => ['required', 'date'],
            // El brut, i a part el que se n'han quedat: així ve al certificat
            'import'       => ['required', 'numeric'],
            'retencio'     => ['nullable', 'numeric'],
            'notes'        => ['nullable', 'string', 'max:500'],
        ]));

        return back();
    }

    public function destroyRendiment(CapitalSocialRendiment $rendiment)
    {
        $rendiment->delete();

        return back();
    }
}
