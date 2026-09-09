<?php

namespace App\Http\Controllers;

use App\Http\Requests\RendaFixaContracteRequest;
use App\Http\Requests\RendaFixaTitolRequest;
use App\Models\CompteCorrent;
use App\Models\Persona;
use App\Models\RendaFixaContracte;
use App\Models\RendaFixaRendibilitat;
use App\Models\RendaFixaTitol;
use App\Models\RendaFixaValor;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RendaFixaController extends Controller
{
    public function index(Request $request)
    {
        $contractes = RendaFixaContracte::with([
                'titol',
                'compteCorrent.titulars',
                'compteRendibilitat',
                'valors',
                'rendibilitats',
            ])
            ->get()
            ->map(fn (RendaFixaContracte $c) => $this->formatContracte($c))
            ->sortBy('titol_nom')
            ->values();

        return Inertia::render('Inversions/RendaFixa', [
            'contractes'       => $contractes,
            'titols'           => RendaFixaTitol::orderBy('nom')->get(['id', 'isin', 'nom', 'emissor']),
            'totalsPerTitular' => $this->totalsPerTitular($contractes),
            // Els comptes on pot viure un títol i els comptes on poden arribar els cupons
            'comptesTitol'     => $this->comptes(['renda_fixa']),
            'comptesRendibilitat' => $this->comptes(['corrent']),
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
    private function formatContracte(RendaFixaContracte $c): array
    {
        $valor    = $c->valorAData();
        $nominal  = (float) $c->nominal;
        $titulars = $c->compteCorrent?->titulars ?? collect();

        return [
            'id'                  => $c->id,
            'titol_id'            => $c->titol_id,
            'titol_nom'           => $c->titol?->nom ?? '',
            'isin'                => $c->titol?->isin ?? '',
            'emissor'             => $c->titol?->emissor,
            'compte_corrent_id'   => $c->compte_corrent_id,
            'compte_nom'          => $c->compteCorrent?->nom ?? $c->compteCorrent?->compte_corrent ?? '',
            'compte_referencia'   => $c->compteCorrent?->compte_corrent ?? '',
            'compte_rendibilitat_id'  => $c->compte_rendibilitat_id,
            'compte_rendibilitat_nom' => $c->compteRendibilitat?->nom ?? $c->compteRendibilitat?->compte_corrent,
            'nominal'             => $nominal,
            'valor'               => $valor,
            // El que ha guanyat o perdut respecte del que es va contractar
            'diferencia'          => round($valor - $nominal, 2),
            'data_compra'         => $c->data_compra?->toDateString(),
            'data_venciment'      => $c->data_venciment?->toDateString(),
            'notes'               => $c->notes,
            'titulars'            => $titulars->map(fn ($t) => ['id' => $t->id, 'nom' => trim($t->nom . ' ' . $t->cognoms)])->values(),
            'valors'              => $c->valors->sortByDesc(fn ($v) => $v->data->timestamp)
                ->map(fn (RendaFixaValor $v) => [
                    'id'                => $v->id,
                    'data'              => $v->data->toDateString(),
                    'valor_patrimonial' => (float) $v->valor_patrimonial,
                ])->values(),
            // Els cupons cobrats, i el que han donat cada any
            'rendibilitats'       => $c->rendibilitats->sortByDesc(fn ($r) => $r->data->timestamp)
                ->map(fn (RendaFixaRendibilitat $r) => [
                    'id'     => $r->id,
                    'data'   => $r->data->toDateString(),
                    'import' => (float) $r->import,
                    'notes'  => $r->notes,
                ])->values(),
            'rendibilitat_per_any' => $c->rendibilitats
                ->groupBy(fn (RendaFixaRendibilitat $r) => $r->data->format('Y'))
                ->map(fn ($any) => round((float) $any->sum('import'), 2))
                ->sortKeysDesc(),
        ];
    }

    /**
     * El valor que té cada titular, repartit a parts iguals entre els titulars del compte
     * del contracte, com als comptes corrents i als fons.
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
                    'titol'    => $contracte['titol_nom'],
                    'compte'   => $contracte['compte_nom'],
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

    // ---- Títols ----

    public function updateTitol(RendaFixaTitolRequest $request, RendaFixaTitol $titol)
    {
        $titol->update($request->validated());

        return back();
    }

    public function destroyTitol(RendaFixaTitol $titol)
    {
        $titol->delete();

        return back();
    }

    // ---- Contractes ----

    public function storeContracte(RendaFixaContracteRequest $request)
    {
        RendaFixaContracte::create([
            ...$request->dadesDelContracte(),
            'titol_id' => $this->titolDe($request)->id,
        ]);

        return back();
    }

    public function updateContracte(RendaFixaContracteRequest $request, RendaFixaContracte $contracte)
    {
        $contracte->update([
            ...$request->dadesDelContracte(),
            'titol_id' => $this->titolDe($request)->id,
        ]);

        return back();
    }

    /**
     * El títol del contracte: el del catàleg, o el que s'ha escrit al mateix formulari.
     *
     * Un ISIN que ja hi és no és cap error —és el mateix producte, comprat un altre cop o
     * en un altre compte—: s'hi reaprofita el títol i no se'n toca el nom, que s'edita al
     * catàleg i el comparteixen tots els contractes que el tenen.
     */
    private function titolDe(RendaFixaContracteRequest $request): RendaFixaTitol
    {
        if ($request->filled('titol_id')) {
            return RendaFixaTitol::findOrFail($request->integer('titol_id'));
        }

        return RendaFixaTitol::firstOrCreate(
            ['isin' => $request->string('isin')->toString()],
            [
                'nom'     => $request->string('nom')->toString(),
                'emissor' => $request->input('emissor'),
            ],
        );
    }

    public function destroyContracte(RendaFixaContracte $contracte)
    {
        $contracte->delete();

        return back();
    }

    // ---- Valors i rendibilitats ----

    public function storeValor(Request $request)
    {
        $dades = $request->validate([
            'contracte_id'      => ['required', 'integer', 'exists:g_rf_contractes,id'],
            'data'              => ['required', 'date'],
            'valor_patrimonial' => ['required', 'numeric'],
        ]);

        // Un valor per data: tornar-hi el corregeix
        RendaFixaValor::updateOrCreate(
            ['contracte_id' => $dades['contracte_id'], 'data' => $dades['data']],
            ['valor_patrimonial' => $dades['valor_patrimonial']],
        );

        return back();
    }

    public function destroyValor(RendaFixaValor $valor)
    {
        $valor->delete();

        return back();
    }

    public function storeRendibilitat(Request $request)
    {
        RendaFixaRendibilitat::create($request->validate([
            'contracte_id' => ['required', 'integer', 'exists:g_rf_contractes,id'],
            'data'         => ['required', 'date'],
            'import'       => ['required', 'numeric'],
            'notes'        => ['nullable', 'string', 'max:500'],
        ]));

        return back();
    }

    public function destroyRendibilitat(RendaFixaRendibilitat $rendibilitat)
    {
        $rendibilitat->delete();

        return back();
    }
}
