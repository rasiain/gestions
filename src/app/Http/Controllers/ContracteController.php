<?php

namespace App\Http\Controllers;

use App\Http\Requests\ContracteRequest;
use App\Models\Contracte;
use Illuminate\Support\Facades\DB;

class ContracteController extends Controller
{
    public function store(ContracteRequest $request)
    {
        $validated = $request->validated();

        // Extreure camps especials abans de crear el contracte
        $tancarId     = $validated['tancar_contracte_anterior_id'] ?? null;
        $dataFiAnterior = $validated['data_fi_anterior'] ?? null;
        $llogaterIds  = $validated['llogater_ids'] ?? [];
        unset($validated['tancar_contracte_anterior_id'], $validated['data_fi_anterior'], $validated['llogater_ids']);

        // Normalitzar arrendador_id: string buida → null
        if (empty($validated['arrendador_id'])) {
            $validated['arrendador_id'] = null;
        }

        DB::beginTransaction();
        try {
            // Tancar el contracte anterior si s'ha especificat
            if ($tancarId && $dataFiAnterior) {
                Contracte::where('id', $tancarId)
                    ->where('lloguer_id', $validated['lloguer_id'])
                    ->update(['data_fi' => $dataFiAnterior]);
            }

            $contracte = Contracte::create($validated);
            $contracte->llogaters()->sync($llogaterIds);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()
                ->withErrors(['error' => 'Error creant el contracte: ' . $e->getMessage()]);
        }

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
