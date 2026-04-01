<?php

namespace App\Http\Controllers;

use App\Http\Requests\ArrendadorRequest;
use App\Models\Arrendador;

class ArrendadorController extends Controller
{
    public function store(ArrendadorRequest $request)
    {
        $validated = $request->validated();

        $typeMap = [
            'persona'        => \App\Models\Persona::class,
            'comunitat_bens' => \App\Models\ComunitatBens::class,
        ];

        $arrendador = Arrendador::create([
            'arrendadorable_type' => $typeMap[$validated['arrendadorable_type']],
            'arrendadorable_id'   => $validated['arrendadorable_id'],
            'adreca'              => $validated['adreca'] ?? null,
        ]);

        if ($request->wantsJson()) {
            return response()->json(['id' => $arrendador->id], 201);
        }

        return redirect()->back()
            ->with('success', 'Arrendador creat correctament.');
    }

    public function update(ArrendadorRequest $request, Arrendador $arrendador)
    {
        $validated = $request->validated();

        $typeMap = [
            'persona'        => \App\Models\Persona::class,
            'comunitat_bens' => \App\Models\ComunitatBens::class,
        ];

        $arrendador->update([
            'arrendadorable_type' => $typeMap[$validated['arrendadorable_type']],
            'arrendadorable_id'   => $validated['arrendadorable_id'],
            'adreca'              => $validated['adreca'] ?? null,
        ]);

        if ($request->wantsJson()) {
            return response()->json(['id' => $arrendador->id]);
        }

        return redirect()->back()
            ->with('success', 'Arrendador actualitzat correctament.');
    }

    public function destroy(Arrendador $arrendador)
    {
        $arrendador->delete();

        return redirect()->back()
            ->with('success', 'Arrendador eliminat correctament.');
    }
}
