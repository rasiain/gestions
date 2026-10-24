<?php

namespace App\Http\Controllers;

use App\Http\Requests\PropostaRequest;
use App\Models\SortidaBici;
use App\Services\PropostesService;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;

/**
 * Propostes de sortida: voltes per la xarxa de camins de les sortides pròpies que s'acosten als km i al
 * desnivell demanats. La pàgina porta només el formulari; les propostes es demanen per JSON (`cerca`),
 * perquè triguen uns segons.
 */
class PropostaController extends Controller
{
    public function index()
    {
        return Inertia::render('Bicis/Propostes', [
            'casa' => ['lat' => PropostesService::CASA[0], 'lon' => PropostesService::CASA[1]],
        ]);
    }

    public function cerca(PropostaRequest $request, PropostesService $servei): JsonResponse
    {
        $d = $request->validated();
        $resultat = $servei->proposa((float) $d['lat'], (float) $d['lon'], (float) $d['km'], (float) $d['desnivell'], $d['tipus']);

        // Les sortides d'on surt cada proposta, amb el que en cal per enllaçar-les
        $ids = collect($resultat['propostes'])->pluck('sortides')->flatten(1)->pluck('id')->unique();
        $sortides = SortidaBici::with('vehicle:id,nom')->whereIn('id', $ids)->get(['id', 'nom', 'data_local', 'vehicle_id'])->keyBy('id');
        foreach ($resultat['propostes'] as &$p) {
            $p['sortides'] = array_map(fn ($s) => [
                'id'   => $s['id'],
                'km'   => $s['km'],
                'nom'  => $sortides[$s['id']]->nom ?? null,
                'data' => $sortides[$s['id']]?->data_local->toDateString(),
                'bici' => $sortides[$s['id']]?->vehicle?->nom,
            ], $p['sortides']);
        }

        return response()->json($resultat);
    }
}
