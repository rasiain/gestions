<?php

namespace App\Http\Controllers;

use App\Http\Requests\CompteCorrentRequest;
use App\Models\CompteCorrent;
use App\Models\Persona;
use Inertia\Inertia;

class CompteCorrentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $comptesCorrents = CompteCorrent::with('titulars')
            ->orderBy('ordre')
            ->orderBy('entitat')
            ->get()
            ->map(function ($compte) {
                $compte->saldo_actual = $compte->saldo_actual;
                return $compte;
            });

        $titulars = Persona::orderBy('cognoms')
            ->orderBy('nom')
            ->get();

        return Inertia::render('ComptesCorrents/Index', [
            'comptesCorrents' => $comptesCorrents,
            'titulars' => $titulars,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CompteCorrentRequest $request)
    {
        // Get validated data excluding titular_ids
        $validated = $request->validated();
        unset($validated['titular_ids']);

        $compteCorrent = CompteCorrent::create($validated);

        // Sync titulars
        $compteCorrent->titulars()->sync($request->input('titular_ids', []));

        return redirect()->route('comptes-corrents.index')
            ->with('success', 'Compte corrent creat correctament.');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(CompteCorrentRequest $request, CompteCorrent $compteCorrent)
    {
        // Get validated data excluding titular_ids
        $validated = $request->validated();
        unset($validated['titular_ids']);

        $compteCorrent->update($validated);

        // Sync titulars
        $compteCorrent->titulars()->sync($request->input('titular_ids', []));

        return redirect()->route('comptes-corrents.index')
            ->with('success', 'Compte corrent actualitzat correctament.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(CompteCorrent $compteCorrent)
    {
        $compteCorrent->delete();

        return redirect()->route('comptes-corrents.index')
            ->with('success', 'Compte corrent eliminat correctament.');
    }
}
