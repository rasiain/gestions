<?php

namespace App\Http\Controllers;

use App\Http\Requests\PersonaRequest;
use App\Models\Persona;
use Inertia\Inertia;

class PersonaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $persones = Persona::orderBy('cognoms')
            ->orderBy('nom')
            ->get();

        return Inertia::render('Persones/Index', [
            'persones' => $persones,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(PersonaRequest $request)
    {
        Persona::create($request->validated());

        return redirect()->route('persones.index')
            ->with('success', 'Persona creada correctament.');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(PersonaRequest $request, Persona $persona)
    {
        $persona->update($request->validated());

        return redirect()->route('persones.index')
            ->with('success', 'Persona actualitzada correctament.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Persona $persona)
    {
        $persona->delete();

        return redirect()->route('persones.index')
            ->with('success', 'Persona eliminada correctament.');
    }
}
