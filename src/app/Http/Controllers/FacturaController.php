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
        $query = $lloguer->factures()->with('linies')->orderBy('mes');

        if ($any = $request->integer('any')) {
            $query->where('any', $any);
        }

        $factures = $query->get();

        return response()->json([
            'data' => $factures,
        ]);
    }

    public function store(Request $request, Lloguer $lloguer): JsonResponse
    {
        $validated = $request->validate([
            'any'              => 'required|integer|min:2000|max:2100',
            'mes'              => 'required|integer|min:1|max:12',
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

        $contracteActiu = $lloguer->contractes()
            ->where(function ($q) {
                $q->whereNull('data_fi')->orWhere('data_fi', '>', now()->toDateString());
            })
            ->first();

        $factura = $lloguer->factures()->create([
            'contracte_id'     => $contracteActiu?->id,
            'any'              => $validated['any'],
            'mes'              => $validated['mes'],
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

        $factura->update([
            'base'             => $validated['base'],
            'iva_percentatge'  => $validated['iva_percentatge'],
            'iva_import'       => $validated['iva_import'],
            'irpf_percentatge' => $validated['irpf_percentatge'] ?? 0,
            'irpf_import'      => $validated['irpf_import'] ?? 0,
            'total'            => $validated['total'],
            'estat'            => $validated['estat'] ?? $factura->estat,
            'numero_factura'   => $validated['numero_factura'] ?? $factura->numero_factura,
            'data_emissio'     => $validated['data_emissio'] ?? $factura->data_emissio,
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
        } else {
            $factura->update([
                'moviment_id' => null,
                'estat'       => 'emesa',
            ]);
        }

        return response()->json($factura->fresh()->load('linies'));
    }
}
