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
            'tipus_despesa_fiscal_id' => ['nullable', 'integer', 'exists:g_tipus_despesa_fiscal,id'],
            'numero_factura'         => ['nullable', 'string', 'max:50'],
            'concepte'               => ['nullable', 'string', 'max:255'],
            'notes'                  => ['nullable', 'string', 'max:500'],
            'base_imposable'         => ['nullable', 'numeric'],
            'iva_percentatge'        => ['nullable', 'numeric', 'min:0', 'max:100'],
            'iva_import'             => ['nullable', 'numeric'],
            'base_lloguer'           => ['nullable', 'numeric'],
            'linies'                 => ['nullable', 'array'],
            'linies.*.tipus'         => ['required', 'in:comunitat,taxes,assegurança,compres,reparacions,gestoria,comissions,altres'],
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

    public function bulkUpdate(Request $request): JsonResponse
    {
        $request->validate([
            'lloguer_id'              => ['required', 'integer', 'exists:g_lloguers,id'],
            'moviment_ids'            => ['required', 'array', 'min:1'],
            'moviment_ids.*'          => ['integer'],
            'categoria'               => ['nullable', 'string', 'max:20'],
            'proveidor_id'            => ['nullable', 'integer', 'exists:g_proveidors,id'],
            'tipus_despesa_fiscal_id' => ['nullable', 'integer', 'exists:g_tipus_despesa_fiscal,id'],
            'numero_factura'          => ['nullable', 'string', 'max:50'],
            'concepte'                => ['nullable', 'string', 'max:255'],
            'notes'                   => ['nullable', 'string', 'max:500'],
            'base_imposable'          => ['nullable', 'numeric'],
            'iva_percentatge'         => ['nullable', 'numeric', 'min:0', 'max:100'],
            'iva_import'              => ['nullable', 'numeric'],
        ]);

        $lloguerId   = $request->integer('lloguer_id');
        $movimentIds = $request->input('moviment_ids');

        // Only fields explicitly present in the request (not null by default) are applied
        $fields = array_filter([
            'categoria'               => $request->input('categoria'),
            'proveidor_id'            => $request->input('proveidor_id'),
            'tipus_despesa_fiscal_id' => $request->input('tipus_despesa_fiscal_id'),
            'numero_factura'          => $request->input('numero_factura'),
            'concepte'                => $request->input('concepte'),
            'notes'                   => $request->input('notes'),
            'base_imposable'          => $request->input('base_imposable'),
            'iva_percentatge'         => $request->input('iva_percentatge'),
            'iva_import'              => $request->input('iva_import'),
        ], fn($v) => $v !== null);

        $moviments = MovimentCompteCorrent::with(['despesa', 'ingres'])
            ->whereIn('id', $movimentIds)
            ->where('exclou_lloguer', false)
            ->get();

        $updated          = 0;
        $created          = 0;
        $skippedIngressos = 0;

        DB::beginTransaction();
        try {
            foreach ($moviments as $moviment) {
                if ($moviment->ingres) {
                    $skippedIngressos++;
                    continue;
                }

                if ($moviment->despesa) {
                    if (!empty($fields)) {
                        $moviment->despesa->update($fields);
                        $updated++;
                    }
                } else {
                    // Only create if at least categoria is provided
                    if (!empty($request->input('categoria'))) {
                        MovimentLloguerDespesa::create(array_merge([
                            'moviment_id' => $moviment->id,
                            'lloguer_id'  => $lloguerId,
                        ], $fields));
                        $created++;
                    }
                }
                // Auto-conciliat: classificar als lloguers implica que el moviment ha estat revisat
                $moviment->update(['conciliat' => true]);
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }

        return response()->json([
            'updated'           => $updated,
            'created'           => $created,
            'skipped_ingressos' => $skippedIngressos,
        ]);
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
                    'moviment_id'             => $moviment->id,
                    'lloguer_id'              => $data['lloguer_id'],
                    'numero_factura'          => $data['numero_factura'] ?? null,
                    'concepte'                => $data['concepte'] ?? null,
                    'categoria'               => $data['categoria'] ?? null,
                    'proveidor_id'            => $data['proveidor_id'] ?? null,
                    'tipus_despesa_fiscal_id' => $data['tipus_despesa_fiscal_id'] ?? null,
                    'notes'                   => $data['notes'] ?? null,
                    'base_imposable'          => $data['base_imposable'] ?? null,
                    'iva_percentatge'         => $data['iva_percentatge'] ?? null,
                    'iva_import'              => $data['iva_import'] ?? null,
                ]);
            } else {
                $ingres = MovimentLloguerIngres::create([
                    'moviment_id'     => $moviment->id,
                    'lloguer_id'      => $data['lloguer_id'],
                    'base_lloguer'    => $data['base_lloguer'],
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
            // Auto-conciliat: classificar un moviment als lloguers implica que ja ha estat revisat
            $moviment->update(['conciliat' => true]);
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
                'id'                      => $moviment->despesa->id,
                'lloguer_id'              => $moviment->despesa->lloguer_id,
                'numero_factura'          => $moviment->despesa->numero_factura,
                'concepte'                => $moviment->despesa->concepte,
                'categoria'               => $moviment->despesa->categoria,
                'proveidor_id'            => $moviment->despesa->proveidor_id,
                'tipus_despesa_fiscal_id' => $moviment->despesa->tipus_despesa_fiscal_id,
                'notes'                   => $moviment->despesa->notes,
                'base_imposable'          => $moviment->despesa->base_imposable,
                'iva_percentatge'         => $moviment->despesa->iva_percentatge,
                'iva_import'              => $moviment->despesa->iva_import,
            ] : null,
            'ingres' => $moviment->ingres ? [
                'id'              => $moviment->ingres->id,
                'lloguer_id'      => $moviment->ingres->lloguer_id,
                'base_lloguer'    => $moviment->ingres->base_lloguer,
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
