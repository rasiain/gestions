<?php

namespace App\Http\Controllers;

use App\Models\MovimentCompteCorrent;
use App\Models\MovimentLloguerDespesa;
use App\Models\MovimentLloguerIngres;
use App\Models\MovimentLloguerIngresLinia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MovimentClassificacioController extends Controller
{
    private function validationRules(): array
    {
        return [
            'tipus'                  => ['required', 'in:despesa,ingres'],
            'lloguer_id'             => ['required', 'integer', 'exists:g_lloguers,id'],
            'categoria'              => ['nullable', 'string', 'max:20'],
            'proveidor_id'           => ['nullable', 'integer', 'exists:g_proveidors,id'],
            'notes'                  => ['nullable', 'string', 'max:500'],
            'base_lloguer'           => ['nullable', 'numeric'],
            'gestoria_import'        => ['nullable', 'numeric'],
            'linies'                 => ['nullable', 'array'],
            'linies.*.tipus'         => ['required', 'in:comunitat,taxes,assegurança,compres,reparacions,gestoria,altres'],
            'linies.*.descripcio'    => ['required', 'string', 'max:200'],
            'linies.*.import'        => ['required', 'numeric'],
            'linies.*.proveidor_id'  => ['nullable', 'integer', 'exists:g_proveidors,id'],
        ];
    }

    public function store(Request $request, MovimentCompteCorrent $moviment): JsonResponse
    {
        $validated = $request->validate($this->validationRules());

        if ($moviment->despesa || $moviment->ingres) {
            return response()->json(['error' => 'Aquest moviment ja està classificat.'], 422);
        }

        return $this->saveClassificacio($moviment, $validated);
    }

    public function update(Request $request, MovimentCompteCorrent $moviment): JsonResponse
    {
        $validated = $request->validate($this->validationRules());

        DB::beginTransaction();
        try {
            if ($moviment->despesa) {
                $moviment->despesa->delete();
            } elseif ($moviment->ingres) {
                $moviment->ingres->linies()->delete();
                $moviment->ingres->delete();
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }

        return $this->saveClassificacio($moviment->fresh(), $validated);
    }

    public function destroy(MovimentCompteCorrent $moviment): JsonResponse
    {
        DB::beginTransaction();
        try {
            if ($moviment->despesa) {
                $moviment->despesa->delete();
            } elseif ($moviment->ingres) {
                $moviment->ingres->linies()->delete();
                $moviment->ingres->delete();
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }

        return $this->movimentResponse($moviment->fresh(['despesa', 'ingres.linies']));
    }

    private function saveClassificacio(MovimentCompteCorrent $moviment, array $data): JsonResponse
    {
        DB::beginTransaction();
        try {
            if ($data['tipus'] === 'despesa') {
                MovimentLloguerDespesa::create([
                    'moviment_id'  => $moviment->id,
                    'lloguer_id'   => $data['lloguer_id'],
                    'categoria'    => $data['categoria'] ?? null,
                    'proveidor_id' => $data['proveidor_id'] ?? null,
                    'notes'        => $data['notes'] ?? null,
                ]);
            } else {
                $ingres = MovimentLloguerIngres::create([
                    'moviment_id'     => $moviment->id,
                    'lloguer_id'      => $data['lloguer_id'],
                    'base_lloguer'    => $data['base_lloguer'],
                    'gestoria_import' => $data['gestoria_import'] ?? null,
                    'notes'           => $data['notes'] ?? null,
                ]);
                foreach ($data['linies'] ?? [] as $linia) {
                    MovimentLloguerIngresLinia::create([
                        'ingres_id'    => $ingres->id,
                        'tipus'        => $linia['tipus'],
                        'descripcio'   => $linia['descripcio'],
                        'import'       => $linia['import'],
                        'proveidor_id' => $linia['proveidor_id'] ?? null,
                    ]);
                }
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }

        return $this->movimentResponse($moviment->fresh(['despesa', 'ingres.linies']));
    }

    private function movimentResponse(MovimentCompteCorrent $moviment): JsonResponse
    {
        return response()->json([
            'despesa' => $moviment->despesa ? [
                'id'           => $moviment->despesa->id,
                'lloguer_id'   => $moviment->despesa->lloguer_id,
                'categoria'    => $moviment->despesa->categoria,
                'proveidor_id' => $moviment->despesa->proveidor_id,
                'notes'        => $moviment->despesa->notes,
            ] : null,
            'ingres' => $moviment->ingres ? [
                'id'              => $moviment->ingres->id,
                'lloguer_id'      => $moviment->ingres->lloguer_id,
                'base_lloguer'    => $moviment->ingres->base_lloguer,
                'gestoria_import' => $moviment->ingres->gestoria_import,
                'notes'           => $moviment->ingres->notes,
                'linies'          => $moviment->ingres->linies->map(fn($l) => [
                    'id'           => $l->id,
                    'tipus'        => $l->tipus,
                    'descripcio'   => $l->descripcio,
                    'import'       => $l->import,
                    'proveidor_id' => $l->proveidor_id,
                ])->toArray(),
            ] : null,
        ]);
    }
}
