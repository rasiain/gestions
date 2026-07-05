<?php

namespace App\Http\Controllers;

use App\Http\Requests\AportacioPlaPensionsRequest;
use App\Http\Requests\ContractePlaPensionsRequest;
use App\Http\Requests\DespesaPlaPensionsRequest;
use App\Http\Requests\PlaPensionsRequest;
use App\Http\Requests\ValorPlaPensionsRequest;
use App\Models\AportacioPlaPensions;
use App\Models\CompteCorrent;
use App\Models\ContractePlaPensions;
use App\Models\Entitat;
use App\Models\DespesaPlaPensions;
use App\Models\PlaPensions;
use App\Models\ValorPlaPensions;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;

class PlaPensionsController extends Controller
{
    public function index()
    {
        $plans = PlaPensions::with([
                'valors',
                'contractes.compteCorrent.titulars',
                'contractes.aportacions',
                'contractes.despeses',
            ])
            ->orderBy('nom')
            ->get()
            ->map(fn($p) => $this->formatPla($p));

        $comptesPlaPensions = CompteCorrent::where('tipus', 'fons_inversio')
            ->with(['titulars', 'entitatRelacio'])
            ->orderBy('nom')
            ->get()
            ->map(fn($c) => [
                'id'       => $c->id,
                'nom'      => $c->nom ?? $c->compte_corrent,
                'entitat'  => $c->entitat,
                'titulars' => $c->titulars->map(fn($t) => $t->nom . ' ' . $t->cognoms)->join(', '),
            ]);

        $entitats = \App\Models\Entitat::orderBy('nom')->get(['id', 'nom']);
        $persones = \App\Models\Persona::orderBy('cognoms')->orderBy('nom')->get(['id', 'nom', 'cognoms']);

        return Inertia::render('PlaPensions/Index', [
            'plans'              => $plans,
            'comptesPlaPensions' => $comptesPlaPensions,
            'entitats'           => $entitats,
            'persones'           => $persones,
        ]);
    }

    // === PLANS ===

    public function store(PlaPensionsRequest $request)
    {
        PlaPensions::create($request->validated());
        return redirect()->route('plans-pensions.index');
    }

    public function update(PlaPensionsRequest $request, PlaPensions $plaPensions)
    {
        $plaPensions->update($request->validated());
        return redirect()->route('plans-pensions.index');
    }

    public function destroy(PlaPensions $plaPensions)
    {
        $plaPensions->delete();
        return redirect()->route('plans-pensions.index');
    }

    // === CONTRACTES ===

    public function storeContracte(ContractePlaPensionsRequest $request): JsonResponse
    {
        $data = $request->validated();

        if ($request->boolean('compte_nou')) {
            if (!empty($data['compte_entitat_nova_nom'])) {
                $entitat = Entitat::firstOrCreate(['nom' => trim($data['compte_entitat_nova_nom'])]);
            } else {
                $entitat = Entitat::findOrFail($data['compte_entitat_id']);
            }
            $compte = CompteCorrent::create([
                'compte_corrent' => $data['compte_referencia'],
                'nom'            => $data['compte_nom'] ?? null,
                'entitat_id'     => $entitat->id,
                'tipus'          => 'fons_inversio',
                'ordre'          => 0,
            ]);
            $compte->titulars()->sync($data['titular_ids'] ?? []);
            $compteCorrentId = $compte->id;
        } else {
            $compteCorrentId = $data['compte_corrent_id'];
        }

        $contracte = ContractePlaPensions::create([
            'pla_id'           => $data['pla_id'],
            'compte_corrent_id' => $compteCorrentId,
            'data_inici'       => $data['data_inici'],
            'data_fi'          => $data['data_fi'] ?? null,
            'notes'            => $data['notes'] ?? null,
        ]);

        $contracte->load('compteCorrent.titulars', 'aportacions', 'despeses');
        $pla = PlaPensions::with('valors')->find($data['pla_id']);
        $valorActual = $pla->valors->sortByDesc('data')->first();

        return response()->json($this->formatContracte($contracte, $valorActual));
    }

    public function updateContracte(ContractePlaPensionsRequest $request, ContractePlaPensions $contracte): JsonResponse
    {
        $data = $request->validated();

        $contracte->update([
            'data_inici' => $data['data_inici'],
            'data_fi'    => $data['data_fi'] ?? null,
            'notes'      => $data['notes'] ?? null,
        ]);

        $contracte->compteCorrent->update(['nom' => $data['compte_nom'] ?? null]);
        $contracte->compteCorrent->titulars()->sync($data['titular_ids'] ?? []);

        $contracte->load('compteCorrent.titulars', 'aportacions', 'despeses');
        $pla = PlaPensions::with('valors')->find($contracte->pla_id);
        $valorActual = $pla->valors->sortByDesc('data')->first();

        return response()->json($this->formatContracte($contracte, $valorActual));
    }

    public function destroyContracte(ContractePlaPensions $contracte): JsonResponse
    {
        $contracte->delete();
        return response()->json(['ok' => true]);
    }

    // === APORTACIONS ===

    public function storeAportacio(AportacioPlaPensionsRequest $request): JsonResponse
    {
        $aportacio = AportacioPlaPensions::create($request->validated());
        return response()->json($this->formatAportacio($aportacio));
    }

    public function updateAportacio(AportacioPlaPensionsRequest $request, AportacioPlaPensions $aportacio): JsonResponse
    {
        $aportacio->update($request->validated());
        return response()->json($this->formatAportacio($aportacio));
    }

    public function destroyAportacio(AportacioPlaPensions $aportacio): JsonResponse
    {
        $aportacio->delete();
        return response()->json(['ok' => true]);
    }

    // === VALORS ===

    public function storeValor(ValorPlaPensionsRequest $request): JsonResponse
    {
        $valor = ValorPlaPensions::updateOrCreate(
            ['pla_id' => $request->pla_id, 'data' => $request->data],
            ['valor_participacio' => $request->valor_participacio]
        );
        return response()->json($this->formatValor($valor));
    }

    public function updateValor(ValorPlaPensionsRequest $request, ValorPlaPensions $valor): JsonResponse
    {
        $valor->update($request->validated());
        return response()->json($this->formatValor($valor));
    }

    public function destroyValor(ValorPlaPensions $valor): JsonResponse
    {
        $valor->delete();
        return response()->json(['ok' => true]);
    }

    // === DESPESES ===

    public function storeDespesa(DespesaPlaPensionsRequest $request): JsonResponse
    {
        $despesa = DespesaPlaPensions::create($request->validated());
        return response()->json($this->formatDespesa($despesa));
    }

    public function updateDespesa(DespesaPlaPensionsRequest $request, DespesaPlaPensions $despesa): JsonResponse
    {
        $despesa->update($request->validated());
        return response()->json($this->formatDespesa($despesa));
    }

    public function destroyDespesa(DespesaPlaPensions $despesa): JsonResponse
    {
        $despesa->delete();
        return response()->json(['ok' => true]);
    }

    // === Formatadors ===

    private function formatPla(PlaPensions $p): array
    {
        $valorActual = $p->valors->sortByDesc('data')->first();
        $contractes  = $p->contractes->map(fn($c) => $this->formatContracte($c, $valorActual))->values()->toArray();

        $valors = $p->valors->sortByDesc('data')->map(fn($v) => $this->formatValor($v))->values()->toArray();

        $totalValor     = array_sum(array_column($contractes, 'valor_contracte_num'));
        $totalRendBruta = array_sum(array_column(array_column($contractes, 'resum'), 'rendibilitat_bruta'));
        $totalRendNeta  = array_sum(array_column(array_column($contractes, 'resum'), 'rendibilitat_neta'));

        return [
            'id'         => $p->id,
            'nom'        => $p->nom,
            'valors'     => $valors,
            'contractes' => $contractes,
            'resum_pla'  => [
                'valor_participacio' => $valorActual ? (float) $valorActual->valor_participacio : null,
                'data_valor_actual'  => $valorActual?->data->toDateString(),
                'total_valor'        => round($totalValor, 2),
                'rendibilitat_bruta' => round($totalRendBruta, 2),
                'rendibilitat_neta'  => round($totalRendNeta, 2),
            ],
        ];
    }

    private function formatContracte(ContractePlaPensions $c, ?ValorPlaPensions $valorActual): array
    {
        $valorPart      = $valorActual ? (float) $valorActual->valor_participacio : null;
        $totalPart      = $c->aportacions->sum(fn($a) => (float) $a->participacions);
        $totalInvertit  = $c->aportacions->sum(fn($a) => (float) $a->import);
        $totalDespeses  = $c->despeses->sum(fn($d) => (float) $d->import);
        $valorContracte = $valorPart !== null ? round($totalPart * $valorPart, 2) : null;
        $rendBruta      = $valorContracte !== null ? round($valorContracte - $totalInvertit, 2) : null;
        $rendNeta       = $rendBruta !== null ? round($rendBruta - $totalDespeses, 2) : null;

        $aportacions = $c->aportacions->sortBy('data')
            ->map(fn($a) => $this->formatAportacio($a, $valorPart))
            ->values()->toArray();

        $despeses = $c->despeses->sortByDesc('data')
            ->map(fn($d) => $this->formatDespesa($d))
            ->values()->toArray();

        $compte = $c->compteCorrent;
        $titularsCol = $compte?->titulars ?? collect();
        $titulars = $titularsCol->map(fn($t) => $t->nom . ' ' . $t->cognoms)->join(', ');
        $titular_ids = $titularsCol->pluck('id')->values()->toArray();

        return [
            'id'                => $c->id,
            'pla_id'            => $c->pla_id,
            'compte_corrent_id' => $c->compte_corrent_id,
            'compte_nom'        => $compte?->nom ?? $compte?->compte_corrent ?? '',
            'compte_referencia' => $compte?->compte_corrent ?? '',
            'compte_entitat'    => $compte?->entitat ?? '',
            'titulars'          => $titulars,
            'titular_ids'       => $titular_ids,
            'data_inici'        => $c->data_inici?->toDateString(),
            'data_fi'           => $c->data_fi?->toDateString(),
            'notes'             => $c->notes,
            'aportacions'       => $aportacions,
            'despeses'          => $despeses,
            'valor_contracte_num' => $valorContracte ?? 0,
            'resum' => [
                'total_participacions' => round($totalPart, 6),
                'total_invertit'       => round($totalInvertit, 2),
                'total_despeses'       => round($totalDespeses, 2),
                'valor_contracte'      => $valorContracte,
                'rendibilitat_bruta'   => $rendBruta,
                'rendibilitat_neta'    => $rendNeta,
            ],
        ];
    }

    private function formatAportacio(AportacioPlaPensions $a, ?float $valorPart = null): array
    {
        $participacions = (float) $a->participacions;
        $import         = (float) $a->import;
        $rendibilitat   = $valorPart !== null
            ? round($participacions * $valorPart - $import, 2)
            : null;

        return [
            'id'             => $a->id,
            'contracte_id'   => $a->contracte_id,
            'data'           => $a->data->toDateString(),
            'import'         => $import,
            'participacions' => $participacions,
            'notes'          => $a->notes,
            'rendibilitat'   => $rendibilitat,
        ];
    }

    private function formatValor(ValorPlaPensions $v): array
    {
        return [
            'id'                => $v->id,
            'pla_id'            => $v->pla_id,
            'data'              => $v->data->toDateString(),
            'valor_participacio' => (float) $v->valor_participacio,
        ];
    }

    private function formatDespesa(DespesaPlaPensions $d): array
    {
        return [
            'id'           => $d->id,
            'contracte_id' => $d->contracte_id,
            'data'         => $d->data->toDateString(),
            'import'       => (float) $d->import,
            'concepte'     => $d->concepte,
        ];
    }
}
