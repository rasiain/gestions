<?php

namespace App\Http\Controllers;

use App\Models\Factura;
use App\Models\FacturaLinia;
use App\Models\Lloguer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FacturaController extends Controller
{
    public function index(Lloguer $lloguer, Request $request): JsonResponse
    {
        $query = $lloguer->factures()
            ->with(['linies', 'moviment:id,data_moviment,import'])
            ->orderByRaw("CASE WHEN tipus = 'mensual' THEN 0 ELSE 1 END")
            ->orderBy('mes')
            ->orderBy('data_emissio');

        if ($any = $request->integer('any')) {
            $query->where(function ($q) use ($any) {
                $q->where('any', $any)
                    ->orWhere(function ($q2) use ($any) {
                        $q2->whereNull('any')->whereYear('data_emissio', $any);
                    });
            });
        }

        $factures = $query->get();

        return response()->json([
            'data' => $factures,
        ]);
    }

    public function store(Request $request, Lloguer $lloguer): JsonResponse
    {
        $validated = $request->validate([
            'tipus'            => 'sometimes|string|in:mensual,puntual',
            'any'              => 'nullable|integer|min:2000|max:2100|required_if:tipus,mensual',
            'mes'              => 'nullable|integer|min:1|max:12|required_if:tipus,mensual',
            'base'             => 'required|numeric|min:0',
            'iva_percentatge'  => 'required|numeric|min:0|max:100',
            'iva_import'       => 'required|numeric',
            'irpf_percentatge' => 'nullable|numeric|min:0|max:100',
            'irpf_import'      => 'nullable|numeric',
            'total'            => 'required|numeric',
            'estat'            => 'nullable|string|in:esborrany,emesa,cobrada',
            'numero_factura'   => 'nullable|string|max:50',
            'data_emissio'     => 'nullable|date',
            'notes'            => 'nullable|string',
            'linies'           => 'nullable|array',
            'linies.*.concepte'    => 'required|string|max:30',
            'linies.*.descripcio'  => 'nullable|string|max:200',
            'linies.*.base'        => 'required|numeric',
            'linies.*.iva_import'  => 'nullable|numeric',
            'linies.*.irpf_import' => 'nullable|numeric',
        ]);

        $tipus = $validated['tipus'] ?? 'mensual';
        $any = $validated['any'] ?? null;
        $mes = $validated['mes'] ?? null;

        if ($tipus === 'puntual' && ($any === null || $mes === null)) {
            $dataEmissio = $validated['data_emissio'] ?? null;
            $data = $dataEmissio ? \Carbon\Carbon::parse($dataEmissio) : now();
            $any = $any ?? $data->year;
            $mes = $mes ?? $data->month;
        }

        if ($tipus === 'mensual') {
            $existeix = $lloguer->factures()
                ->where('tipus', 'mensual')
                ->where('any', $any)
                ->where('mes', $mes)
                ->exists();

            if ($existeix) {
                return response()->json([
                    'message' => 'Ja existeix una factura mensual per a aquest any i mes.',
                    'errors'  => ['mes' => ['Ja existeix una factura mensual per a aquest any i mes.']],
                ], 422);
            }
        }

        $contracteActiu = $lloguer->contractes()
            ->where(function ($q) {
                $q->whereNull('data_fi')->orWhere('data_fi', '>', now()->toDateString());
            })
            ->first();

        $factura = $lloguer->factures()->create([
            'contracte_id'     => $contracteActiu?->id,
            'any'              => $any,
            'mes'              => $mes,
            'tipus'            => $tipus,
            'base'             => $validated['base'],
            'iva_percentatge'  => $validated['iva_percentatge'],
            'iva_import'       => $validated['iva_import'],
            'irpf_percentatge' => $validated['irpf_percentatge'] ?? 0,
            'irpf_import'      => $validated['irpf_import'] ?? 0,
            'total'            => $validated['total'],
            'estat'            => $validated['estat'] ?? 'esborrany',
            'numero_factura'   => $validated['numero_factura'] ?? null,
            'data_emissio'     => $validated['data_emissio'] ?? null,
            'notes'            => $validated['notes'] ?? null,
        ]);

        if (!empty($validated['linies'])) {
            foreach ($validated['linies'] as $linia) {
                $factura->linies()->create([
                    'concepte'   => $linia['concepte'],
                    'descripcio' => $linia['descripcio'] ?? null,
                    'base'       => $linia['base'],
                    'iva_import' => $linia['iva_import'] ?? 0,
                    'irpf_import' => $linia['irpf_import'] ?? 0,
                ]);
            }
        }

        return response()->json($factura->load('linies'), 201);
    }

    public function update(Request $request, Factura $factura): JsonResponse
    {
        $validated = $request->validate([
            'any'              => 'nullable|integer|min:2000|max:2100',
            'mes'              => 'nullable|integer|min:1|max:12',
            'base'             => 'required|numeric|min:0',
            'iva_percentatge'  => 'required|numeric|min:0|max:100',
            'iva_import'       => 'required|numeric',
            'irpf_percentatge' => 'nullable|numeric|min:0|max:100',
            'irpf_import'      => 'nullable|numeric',
            'total'            => 'required|numeric',
            'estat'            => 'nullable|string|in:esborrany,emesa,cobrada',
            'numero_factura'   => 'nullable|string|max:50',
            'data_emissio'     => 'nullable|date',
            'notes'            => 'nullable|string',
            'linies'           => 'nullable|array',
            'linies.*.concepte'    => 'required|string|max:30',
            'linies.*.descripcio'  => 'nullable|string|max:200',
            'linies.*.base'        => 'required|numeric',
            'linies.*.iva_import'  => 'nullable|numeric',
            'linies.*.irpf_import' => 'nullable|numeric',
        ]);

        $any = $validated['any'] ?? $factura->any;
        $mes = $validated['mes'] ?? $factura->mes;
        $dataEmissio = $validated['data_emissio'] ?? $factura->data_emissio;

        // Per a puntuals, si canvia data_emissio i no s'envien any/mes explicitament, re-derivar-los
        if (
            $factura->tipus === 'puntual'
            && !array_key_exists('any', $validated)
            && !array_key_exists('mes', $validated)
            && isset($validated['data_emissio'])
        ) {
            $data = \Carbon\Carbon::parse($validated['data_emissio']);
            $any = $data->year;
            $mes = $data->month;
        }

        if ($factura->tipus === 'mensual' && ($any !== $factura->any || $mes !== $factura->mes)) {
            $existeix = $factura->lloguer->factures()
                ->where('tipus', 'mensual')
                ->where('id', '!=', $factura->id)
                ->where('any', $any)
                ->where('mes', $mes)
                ->exists();

            if ($existeix) {
                return response()->json([
                    'message' => 'Ja existeix una factura mensual per a aquest any i mes.',
                    'errors'  => ['mes' => ['Ja existeix una factura mensual per a aquest any i mes.']],
                ], 422);
            }
        }

        $factura->update([
            'any'              => $any,
            'mes'              => $mes,
            'base'             => $validated['base'],
            'iva_percentatge'  => $validated['iva_percentatge'],
            'iva_import'       => $validated['iva_import'],
            'irpf_percentatge' => $validated['irpf_percentatge'] ?? 0,
            'irpf_import'      => $validated['irpf_import'] ?? 0,
            'total'            => $validated['total'],
            'estat'            => $validated['estat'] ?? $factura->estat,
            'numero_factura'   => $validated['numero_factura'] ?? $factura->numero_factura,
            'data_emissio'     => $dataEmissio,
            'notes'            => $validated['notes'] ?? $factura->notes,
        ]);

        // Replace lines
        if (isset($validated['linies'])) {
            $factura->linies()->delete();
            foreach ($validated['linies'] as $linia) {
                $factura->linies()->create([
                    'concepte'   => $linia['concepte'],
                    'descripcio' => $linia['descripcio'] ?? null,
                    'base'       => $linia['base'],
                    'iva_import' => $linia['iva_import'] ?? 0,
                    'irpf_import' => $linia['irpf_import'] ?? 0,
                ]);
            }
        }

        return response()->json($factura->fresh()->load('linies'));
    }

    public function destroy(Factura $factura): JsonResponse
    {
        if ($factura->estat !== 'esborrany') {
            return response()->json(['error' => 'Nomes es poden eliminar factures en esborrany.'], 422);
        }

        $factura->delete();

        return response()->json(['ok' => true]);
    }

    public function generar(Lloguer $lloguer, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'any'       => 'required|integer|min:2000|max:2100',
            'mes_inici' => 'required|integer|min:1|max:12',
            'mes_fi'    => 'required|integer|min:1|max:12|gte:mes_inici',
        ]);

        $contracteActiu = $lloguer->contractes()
            ->where(function ($q) {
                $q->whereNull('data_fi')->orWhere('data_fi', '>', now()->toDateString());
            })
            ->first();

        $base = (float) $lloguer->base_euros;
        $ivaPerc = (float) $lloguer->iva_percentatge;
        $irpfPerc = $lloguer->retencio_irpf ? (float) $lloguer->irpf_percentatge : 0;

        $creades = [];
        for ($mes = $validated['mes_inici']; $mes <= $validated['mes_fi']; $mes++) {
            // Skip if already exists
            $exists = $lloguer->factures()
                ->where('tipus', 'mensual')
                ->where('any', $validated['any'])
                ->where('mes', $mes)
                ->exists();

            if ($exists) continue;

            $ivaImport = round($base * $ivaPerc / 100, 2);
            $irpfImport = round($base * $irpfPerc / 100, 2);
            $total = round($base + $ivaImport - $irpfImport, 2);

            $factura = $lloguer->factures()->create([
                'contracte_id'     => $contracteActiu?->id,
                'any'              => $validated['any'],
                'mes'              => $mes,
                'base'             => $base,
                'iva_percentatge'  => $ivaPerc,
                'iva_import'       => $ivaImport,
                'irpf_percentatge' => $irpfPerc,
                'irpf_import'      => $irpfImport,
                'total'            => $total,
                'estat'            => 'esborrany',
                'data_emissio'     => sprintf('%d-%02d-01', $validated['any'], $mes),
            ]);

            $factura->linies()->create([
                'concepte'   => 'lloguer_base',
                'descripcio' => 'Lloguer base',
                'base'       => $base,
                'iva_import' => $ivaImport,
                'irpf_import' => $irpfImport,
            ]);

            $creades[] = $factura->load('linies');
        }

        return response()->json([
            'creades' => count($creades),
            'data'    => $creades,
        ]);
    }

    public function vincularMoviment(Factura $factura, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'moviment_id' => 'nullable|integer|exists:g_moviments_comptes_corrents,id',
        ]);

        if ($validated['moviment_id']) {
            $factura->update([
                'moviment_id' => $validated['moviment_id'],
                'estat'       => 'cobrada',
            ]);
            // Auto-conciliat: vincular una factura a un moviment implica que ja ha estat revisat
            \App\Models\MovimentCompteCorrent::where('id', $validated['moviment_id'])
                ->update(['conciliat' => true]);
        } else {
            $factura->update([
                'moviment_id' => null,
                'estat'       => 'emesa',
            ]);
        }

        return response()->json($factura->fresh()->load('linies'));
    }
}
