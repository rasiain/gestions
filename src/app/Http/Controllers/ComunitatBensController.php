<?php

namespace App\Http\Controllers;

use App\Http\Requests\ComunitatBensRequest;
use App\Models\ComunitatBens;
use Inertia\Inertia;

class ComunitatBensController extends Controller
{
    public function index()
    {
        $comunitatsBens = ComunitatBens::orderBy('nom')
            ->get()
            ->map(function ($comunitat) {
                return [
                    'id'         => $comunitat->id,
                    'nom'        => $comunitat->nom,
                    'nif'        => $comunitat->nif,
                    'created_at' => $comunitat->created_at,
                    'updated_at' => $comunitat->updated_at,
                ];
            });

        return Inertia::render('ComunitatsBens/Index', [
            'comunitatsBens' => $comunitatsBens,
        ]);
    }

    public function store(ComunitatBensRequest $request)
    {
        ComunitatBens::create($request->validated());

        return redirect()->route('comunitats-bens.index')
            ->with('success', 'Comunitat de béns creada correctament.');
    }

    public function update(ComunitatBensRequest $request, ComunitatBens $comunitats_ben)
    {
        $comunitats_ben->update($request->validated());

        return redirect()->route('comunitats-bens.index')
            ->with('success', 'Comunitat de béns actualitzada correctament.');
    }

    public function destroy(ComunitatBens $comunitats_ben)
    {
        $comunitats_ben->delete();

        return redirect()->route('comunitats-bens.index')
            ->with('success', 'Comunitat de béns eliminada correctament.');
    }
}
