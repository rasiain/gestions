<?php

namespace App\Http\Controllers;

use App\Models\Factura;
use App\Models\Lloguer;
use App\Models\LloguerRevisioIpc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LloguerRevisioIpcController extends Controller
{
    public function index(Lloguer $lloguer): JsonResponse
    {
        $revisions = $lloguer->revisionsIpc()
            ->orderBy('any_aplicacio', 'desc')
            ->get();

        return response()->json(['data' => $revisions]);
    }

    public function store(Request $request, Lloguer $lloguer): JsonResponse
    {
        $validated = $request->validate([
            'any_aplicacio'   => 'required|integer|min:2000|max:2100',
            'ipc_percentatge' => 'required|numeric',
            'data_efectiva'   => 'required|date',
            'regularitzar'    => 'boolean',
        ]);

        $baseAnterior = (float) $lloguer->base_euros;
        $ipcPerc = (float) $validated['ipc_percentatge'];
        $baseNova = round($baseAnterior * (1 + $ipcPerc / 100), 2);

        $revisio = $lloguer->revisionsIpc()->create([
            'any_aplicacio'   => $validated['any_aplicacio'],
            'base_anterior'   => $baseAnterior,
            'base_nova'       => $baseNova,
            'ipc_percentatge' => $ipcPerc,
            'data_efectiva'   => $validated['data_efectiva'],
        ]);

        // Update lloguer base
        $lloguer->update(['base_euros' => $baseNova]);

        // Optionally regularize existing invoices
        if ($request->boolean('regularitzar')) {
            $factures = $lloguer->factures()
                ->where('any', $validated['any_aplicacio'])
                ->where('estat', '!=', 'cobrada')
                ->get();

            $mesos = 0;
            foreach ($factures as $factura) {
                $diferencia = round($baseNova - $baseAnterior, 2);
                if ($diferencia != 0) {
                    $factura->linies()->create([
                        'concepte'    => 'regularitzacio_ipc',
                        'descripcio'  => "Regularitzacio IPC {$validated['any_aplicacio']} ({$ipcPerc}%)",
                        'base'        => $diferencia,
                        'iva_import'  => round($diferencia * (float) $factura->iva_percentatge / 100, 2),
                        'irpf_import' => round($diferencia * (float) $factura->irpf_percentatge / 100, 2),
                    ]);

                    // Recalculate factura totals
                    $totalBase = $factura->linies()->sum('base');
                    $totalIva = $factura->linies()->sum('iva_import');
                    $totalIrpf = $factura->linies()->sum('irpf_import');
                    $factura->update([
                        'base'       => $totalBase,
                        'iva_import' => $totalIva,
                        'irpf_import' => $totalIrpf,
                        'total'      => round($totalBase + $totalIva - $totalIrpf, 2),
                    ]);

                    $mesos++;
                }
            }

            $revisio->update(['mesos_regularitzats' => $mesos]);
        }

        return response()->json($revisio->fresh(), 201);
    }
}
