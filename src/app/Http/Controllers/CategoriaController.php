<?php

namespace App\Http\Controllers;

use App\Http\Requests\CategoriaRequest;
use App\Models\Categoria;
use App\Models\CompteCorrent;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CategoriaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        // Get all comptes corrents for the selector
        $comptesCorrents = CompteCorrent::orderBy('ordre')->orderBy('entitat')->get();

        // Get the selected compte_corrent_id from request, or default to first one
        $compteCorrentId = $request->input('compte_corrent_id', $comptesCorrents->first()?->id);

        // Get categories filtered by compte_corrent_id
        // Sorting is handled automatically by the model's relationships and scopes
        $categories = [];
        if ($compteCorrentId) {
            $categories = Categoria::with('fills.fills')
                ->perCompteCorrent($compteCorrentId)
                ->arrel()
                ->get();
        }

        return Inertia::render('Categories/Index', [
            'categories' => $categories,
            'comptesCorrents' => $comptesCorrents,
            'selectedCompteCorrentId' => $compteCorrentId,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CategoriaRequest $request)
    {
        Categoria::create($request->validated());

        return redirect()->route('categories.index')
            ->with('success', 'Categoria creada correctament.');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(CategoriaRequest $request, Categoria $categoria)
    {
        $categoria->update($request->validated());

        return redirect()->route('categories.index')
            ->with('success', 'Categoria actualitzada correctament.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Categoria $categoria)
    {
        $categoria->delete();

        return redirect()->route('categories.index')
            ->with('success', 'Categoria eliminada correctament.');
    }
}
