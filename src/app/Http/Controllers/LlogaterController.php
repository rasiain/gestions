<?php

namespace App\Http\Controllers;

use App\Http\Requests\LlogaterRequest;
use App\Models\Llogater;
use App\Models\Persona;
use Inertia\Inertia;

class LlogaterController extends Controller
{
    public function index()
    {
        $llogaters = Llogater::with('persona')
            ->get()
            ->map(fn($l) => [
                'id'             => $l->id,
                'tipus'          => $l->tipus,
                'persona_id'     => $l->persona_id,
                'persona'        => $l->persona ? [
                    'id'      => $l->persona->id,
                    'nom'     => $l->persona->nom . ' ' . $l->persona->cognoms,
                    'cognoms' => $l->persona->cognoms,
                    'nif'     => $l->persona->nif,
                ] : null,
                'nom_rao_social' => $l->nom_rao_social,
                'nif'            => $l->nif,
                'adreca'         => $l->adreca,
                'codi_postal'    => $l->codi_postal,
                'poblacio'       => $l->poblacio,
            ])
            ->sortBy(fn($l) => $l['tipus'] === 'persona'
                ? ($l['persona']['cognoms'] ?? '')
                : ($l['nom_rao_social'] ?? ''))
            ->values();

        $persones = Persona::orderBy('cognoms')->orderBy('nom')
            ->get(['id', 'nom', 'cognoms'])
            ->map(fn($p) => ['id' => $p->id, 'nom' => $p->cognoms . ', ' . $p->nom]);

        return Inertia::render('Llogaters/Index', [
            'llogaters' => $llogaters,
            'persones'  => $persones,
        ]);
    }

    public function store(LlogaterRequest $request)
    {
        $llogater = Llogater::with('persona')->create($request->validated());

        if ($request->wantsJson()) {
            return response()->json([
                'id'      => $llogater->id,
                'nom'     => $llogater->persona?->nom ?? $llogater->nom_rao_social,
                'cognoms' => $llogater->persona?->cognoms ?? '',
            ], 201);
        }

        return redirect()->route('llogaters.index')
            ->with('success', 'Llogater creat correctament.');
    }

    public function update(LlogaterRequest $request, Llogater $llogater)
    {
        $data = $request->validated();

        // Netejar camps de l'altre tipus
        if ($data['tipus'] === 'persona') {
            $data['nom_rao_social'] = null;
            $data['nif']            = null;
            $data['adreca']         = null;
            $data['codi_postal']    = null;
            $data['poblacio']       = null;
        } else {
            $data['persona_id'] = null;
        }

        $llogater->update($data);

        return redirect()->route('llogaters.index')
            ->with('success', 'Llogater actualitzat correctament.');
    }

    public function destroy(Llogater $llogater)
    {
        $llogater->delete();

        if (request()->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('llogaters.index')
            ->with('success', 'Llogater eliminat correctament.');
    }
}
