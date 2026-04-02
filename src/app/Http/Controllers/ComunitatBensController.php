<?php

namespace App\Http\Controllers;

use App\Http\Requests\ComunitatBensRequest;
use App\Models\ComunitatBens;
use App\Models\Persona;
use Inertia\Inertia;

class ComunitatBensController extends Controller
{
    public function index()
    {
        $comunitatsBens = ComunitatBens::with('comuners')
            ->orderBy('nom')
            ->get()
            ->map(fn($c) => [
                'id'              => $c->id,
                'nom'             => $c->nom,
                'nif'             => $c->nif,
                'adreca'          => $c->adreca,
                'activitat'       => $c->activitat,
                'codi_activitat'  => $c->codi_activitat,
                'epigraf_iae'     => $c->epigraf_iae,
                'comuner_ids'     => $c->comuners->pluck('id')->toArray(),
                'comuners'        => $c->comuners->map(fn($p) => [
                    'id'  => $p->id,
                    'nom' => $p->nom . ' ' . $p->cognoms,
                ])->values()->toArray(),
            ]);

        $persones = Persona::orderBy('cognoms')->orderBy('nom')
            ->get(['id', 'nom', 'cognoms'])
            ->map(fn($p) => ['id' => $p->id, 'nom' => $p->nom . ' ' . $p->cognoms]);

        return Inertia::render('ComunitatsBens/Index', [
            'comunitatsBens' => $comunitatsBens,
            'persones'       => $persones,
        ]);
    }

    public function store(ComunitatBensRequest $request)
    {
        $validated = $request->validated();
        $comunerIds = $validated['comuner_ids'] ?? [];
        unset($validated['comuner_ids']);

        $comunitat = ComunitatBens::create($validated);
        $comunitat->comuners()->sync($comunerIds);

        return redirect()->route('comunitats-bens.index')
            ->with('success', 'Comunitat de béns creada correctament.');
    }

    public function update(ComunitatBensRequest $request, ComunitatBens $comunitats_ben)
    {
        $validated = $request->validated();
        $comunerIds = $validated['comuner_ids'] ?? [];
        unset($validated['comuner_ids']);

        $comunitats_ben->update($validated);
        $comunitats_ben->comuners()->sync($comunerIds);

        return redirect()->route('comunitats-bens.index')
            ->with('success', 'Comunitat de béns actualitzada correctament.');
    }

    public function destroy(ComunitatBens $comunitats_ben)
    {
        $comunitats_ben->comuners()->detach();
        $comunitats_ben->delete();

        return redirect()->route('comunitats-bens.index')
            ->with('success', 'Comunitat de béns eliminada correctament.');
    }
}
