<?php

namespace App\Http\Controllers;

use App\Http\Requests\LloguerRequest;
use App\Models\CompteCorrent;
use App\Models\Immoble;
use App\Models\Llogater;
use App\Models\Lloguer;
use App\Models\MovimentCompteCorrent;
use App\Models\Proveidor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LloguerController extends Controller
{
    public function index()
    {
        $lloguers = Lloguer::with([
                'immoble',
                'compteCorrent',
                'gestoria',
                'contractes' => function ($q) {
                    $q->where(function ($q2) {
                        $q2->whereNull('data_fi')->orWhere('data_fi', '>', now()->toDateString());
                    })->orderBy('data_inici', 'desc');
                },
                'contractes.llogaters',
            ])
            ->orderBy('nom')
            ->get()
            ->map(function ($lloguer) {
                $contracteActiu = $lloguer->contractes->first();
                return [
                    'id'               => $lloguer->id,
                    'nom'              => $lloguer->nom,
                    'acronim'          => $lloguer->acronim,
                    'immoble_id'       => $lloguer->immoble_id,
                    'immoble'          => $lloguer->immoble ? [
                        'id'    => $lloguer->immoble->id,
                        'adreca' => $lloguer->immoble->adreca,
                    ] : null,
                    'compte_corrent_id' => $lloguer->compte_corrent_id,
                    'compte_corrent'   => $lloguer->compteCorrent ? [
                        'id'  => $lloguer->compteCorrent->id,
                        'nom' => $lloguer->compteCorrent->nom,
                    ] : null,
                    'base_euros'            => $lloguer->base_euros,
                    'proveidor_gestoria_id' => $lloguer->proveidor_gestoria_id,
                    'gestoria_percentatge'  => $lloguer->gestoria_percentatge,
                    'gestoria'              => $lloguer->gestoria ? [
                        'id'             => $lloguer->gestoria->id,
                        'nom_rao_social' => $lloguer->gestoria->nom_rao_social,
                    ] : null,
                    'contracte_actiu'  => $contracteActiu ? [
                        'id'          => $contracteActiu->id,
                        'lloguer_id'  => $lloguer->id,
                        'data_inici'  => $contracteActiu->data_inici?->toDateString(),
                        'data_fi'     => $contracteActiu->data_fi?->toDateString(),
                        'llogater_ids' => $contracteActiu->llogaters->pluck('id')->toArray(),
                        'llogaters'   => $contracteActiu->llogaters->map(fn($l) => [
                            'id'      => $l->id,
                            'nom'     => $l->nom,
                            'cognoms' => $l->cognoms,
                        ])->values()->toArray(),
                    ] : null,
                ];
            });

        $immobles = Immoble::orderBy('adreca')->get(['id', 'adreca']);
        $comptesCorrents = CompteCorrent::orderBy('nom')->get(['id', 'nom']);
        $llogaters = Llogater::orderBy('cognoms')->orderBy('nom')->get(['id', 'nom', 'cognoms']);
        $proveidors = Proveidor::orderBy('nom_rao_social')->get(['id', 'nom_rao_social']);

        return Inertia::render('Lloguers/Index', [
            'lloguers'        => $lloguers,
            'immobles'        => $immobles,
            'comptesCorrents' => $comptesCorrents,
            'llogaters'       => $llogaters,
            'proveidors'      => $proveidors,
        ]);
    }

    public function store(LloguerRequest $request)
    {
        Lloguer::create($request->validated());

        return redirect()->route('lloguers.index')
            ->with('success', 'Lloguer creat correctament.');
    }

    public function update(LloguerRequest $request, Lloguer $lloguer)
    {
        $lloguer->update($request->validated());

        return redirect()->route('lloguers.index')
            ->with('success', 'Lloguer actualitzat correctament.');
    }

    public function destroy(Lloguer $lloguer)
    {
        $lloguer->delete();

        return redirect()->route('lloguers.index')
            ->with('success', 'Lloguer eliminat correctament.');
    }

    public function moviments(Lloguer $lloguer, Request $request): JsonResponse
    {
        $page    = max(1, $request->integer('page', 1));
        $perPage = 30;

        $query = MovimentCompteCorrent::with(['concepte', 'despesa', 'ingres.linies'])
            ->where('compte_corrent_id', $lloguer->compte_corrent_id)
            ->orderBy('data_moviment', 'desc')
            ->orderBy('id', 'desc');

        $total    = $query->count();
        $moviments = $query->skip(($page - 1) * $perPage)->take($perPage)->get()
            ->map(fn($m) => [
                'id'              => $m->id,
                'data_moviment'   => $m->data_moviment->toDateString(),
                'concepte'        => $m->concepte?->concepte ?? $m->concepte_original ?? '',
                'import'          => $m->import,
                'saldo_posterior' => $m->saldo_posterior,
                'exclou_lloguer'  => $m->exclou_lloguer,
                'despesa'         => $m->despesa ? [
                    'id'           => $m->despesa->id,
                    'lloguer_id'   => $m->despesa->lloguer_id,
                    'categoria'    => $m->despesa->categoria,
                    'proveidor_id' => $m->despesa->proveidor_id,
                    'notes'        => $m->despesa->notes,
                ] : null,
                'ingres'          => $m->ingres ? [
                    'id'              => $m->ingres->id,
                    'lloguer_id'      => $m->ingres->lloguer_id,
                    'base_lloguer'    => $m->ingres->base_lloguer,
                    'gestoria_import' => $m->ingres->gestoria_import,
                    'linies'          => $m->ingres->linies->map(fn($l) => [
                        'id'           => $l->id,
                        'tipus'        => $l->tipus,
                        'descripcio'   => $l->descripcio,
                        'import'       => $l->import,
                        'proveidor_id' => $l->proveidor_id,
                    ])->toArray(),
                ] : null,
            ]);

        return response()->json([
            'data'     => $moviments,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'has_more' => ($page * $perPage) < $total,
        ]);
    }
}
