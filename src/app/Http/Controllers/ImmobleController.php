<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImmobleRequest;
use App\Models\Immoble;
use App\Models\Persona;
use Inertia\Inertia;

class ImmobleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $immobles = Immoble::with(['propietaris', 'administrador'])
            ->orderBy('adreca')
            ->get()
            ->map(function ($immoble) {
                return [
                    'id' => $immoble->id,
                    'referencia_cadastral' => $immoble->referencia_cadastral,
                    'adreca' => $immoble->adreca,
                    'superficie_construida' => $immoble->superficie_construida,
                    'superficie_parcela' => $immoble->superficie_parcela,
                    'us' => $immoble->us,
                    'valor_sol' => $immoble->valor_sol,
                    'valor_construccio' => $immoble->valor_construccio,
                    'valor_cadastral' => $immoble->valor_cadastral,
                    'valor_adquisicio' => $immoble->valor_adquisicio,
                    'referencia_administracio' => $immoble->referencia_administracio,
                    'administrador_id' => $immoble->administrador_id,
                    'administrador' => $immoble->administrador,
                    'propietaris' => $immoble->propietaris,
                    'created_at' => $immoble->created_at,
                    'updated_at' => $immoble->updated_at,
                ];
            });

        $persones = Persona::orderBy('cognoms')
            ->orderBy('nom')
            ->get();

        $proveidors = \App\Models\Proveidor::orderBy('nom_rao_social')
            ->get();

        return Inertia::render('Immobles/Index', [
            'immobles' => $immobles,
            'persones' => $persones,
            'proveidors' => $proveidors,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(ImmobleRequest $request)
    {
        $validated = $request->validated();

        // Extract propietari data
        $propietariIds = $validated['propietari_ids'] ?? [];
        $propietariDataInici = $validated['propietari_data_inici'] ?? [];
        $propietariDataFi = $validated['propietari_data_fi'] ?? [];

        unset($validated['propietari_ids'], $validated['propietari_data_inici'], $validated['propietari_data_fi']);

        $immoble = Immoble::create($validated);

        // Sync propietaris with pivot data
        $syncData = [];
        foreach ($propietariIds as $index => $personaId) {
            $syncData[$personaId] = [
                'data_inici' => $propietariDataInici[$index] ?? now()->toDateString(),
                'data_fi' => $propietariDataFi[$index] ?? null,
            ];
        }

        $immoble->propietaris()->sync($syncData);

        return redirect()->route('immobles.index')
            ->with('success', 'Immoble creat correctament.');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(ImmobleRequest $request, Immoble $immoble)
    {
        $validated = $request->validated();

        // Extract propietari data
        $propietariIds = $validated['propietari_ids'] ?? [];
        $propietariDataInici = $validated['propietari_data_inici'] ?? [];
        $propietariDataFi = $validated['propietari_data_fi'] ?? [];

        unset($validated['propietari_ids'], $validated['propietari_data_inici'], $validated['propietari_data_fi']);

        $immoble->update($validated);

        // Sync propietaris with pivot data
        $syncData = [];
        foreach ($propietariIds as $index => $personaId) {
            $syncData[$personaId] = [
                'data_inici' => $propietariDataInici[$index] ?? now()->toDateString(),
                'data_fi' => $propietariDataFi[$index] ?? null,
            ];
        }

        $immoble->propietaris()->sync($syncData);

        return redirect()->route('immobles.index')
            ->with('success', 'Immoble actualitzat correctament.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Immoble $immoble)
    {
        $immoble->delete();

        return redirect()->route('immobles.index')
            ->with('success', 'Immoble eliminat correctament.');
    }
}
