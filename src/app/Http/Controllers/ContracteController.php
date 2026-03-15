<?php

namespace App\Http\Controllers;

use App\Http\Requests\ContracteRequest;
use App\Models\Contracte;

class ContracteController extends Controller
{
    public function store(ContracteRequest $request)
    {
        $validated = $request->validated();
        $llogaterIds = $validated['llogater_ids'] ?? [];
        unset($validated['llogater_ids']);

        $contracte = Contracte::create($validated);
        $contracte->llogaters()->sync($llogaterIds);

        return redirect()->route('lloguers.index')
            ->with('success', 'Contracte creat correctament.');
    }

    public function update(ContracteRequest $request, Contracte $contracte)
    {
        $validated = $request->validated();
        $llogaterIds = $validated['llogater_ids'] ?? [];
        unset($validated['llogater_ids']);

        $contracte->update($validated);
        $contracte->llogaters()->sync($llogaterIds);

        return redirect()->route('lloguers.index')
            ->with('success', 'Contracte actualitzat correctament.');
    }

    public function destroy(Contracte $contracte)
    {
        $contracte->llogaters()->detach();
        $contracte->delete();

        return redirect()->route('lloguers.index')
            ->with('success', 'Contracte eliminat correctament.');
    }
}
