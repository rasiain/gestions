<?php

namespace App\Http\Controllers;

use App\Http\Requests\TitularRequest;
use App\Models\Titular;
use Inertia\Inertia;

class TitularController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $titulars = Titular::orderBy('cognoms')
            ->orderBy('nom')
            ->get();

        return Inertia::render('Titulars/Index', [
            'titulars' => $titulars,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(TitularRequest $request)
    {
        Titular::create($request->validated());

        return redirect()->route('titulars.index')
            ->with('success', 'Titular creat correctament.');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(TitularRequest $request, Titular $titular)
    {
        $titular->update($request->validated());

        return redirect()->route('titulars.index')
            ->with('success', 'Titular actualitzat correctament.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Titular $titular)
    {
        $titular->delete();

        return redirect()->route('titulars.index')
            ->with('success', 'Titular eliminat correctament.');
    }
}
