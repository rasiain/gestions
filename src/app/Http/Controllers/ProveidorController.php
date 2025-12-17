<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProveidorRequest;
use App\Models\Proveidor;
use Inertia\Inertia;

class ProveidorController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $proveidors = Proveidor::orderBy('nom_rao_social')
            ->get()
            ->map(function ($proveidor) {
                return [
                    'id' => $proveidor->id,
                    'nom_rao_social' => $proveidor->nom_rao_social,
                    'nif_cif' => $proveidor->nif_cif,
                    'adreca' => $proveidor->adreca,
                    'correu_electronic' => $proveidor->correu_electronic,
                    'telefons' => $proveidor->telefons,
                    'created_at' => $proveidor->created_at,
                    'updated_at' => $proveidor->updated_at,
                ];
            });

        return Inertia::render('Proveidors/Index', [
            'proveidors' => $proveidors,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(ProveidorRequest $request)
    {
        $validated = $request->validated();

        Proveidor::create($validated);

        return redirect()->route('proveidors.index')
            ->with('success', 'Proveïdor creat correctament.');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(ProveidorRequest $request, Proveidor $proveidor)
    {
        $validated = $request->validated();

        $proveidor->update($validated);

        return redirect()->route('proveidors.index')
            ->with('success', 'Proveïdor actualitzat correctament.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Proveidor $proveidor)
    {
        $proveidor->delete();

        return redirect()->route('proveidors.index')
            ->with('success', 'Proveïdor eliminat correctament.');
    }
}
