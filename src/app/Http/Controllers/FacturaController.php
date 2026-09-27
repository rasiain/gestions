<?php

namespace App\Http\Controllers;

use App\Models\Factura;
use App\Models\FacturaLinia;
use App\Models\Lloguer;
use App\Services\FacturaCorreuService;
use App\Services\FacturaPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FacturaController extends Controller
{
    public function index(Lloguer $lloguer, Request $request): JsonResponse
    {
        $query = $lloguer->factures()
            ->with(['linies', 'moviment:id,data_moviment,import']);

        if ($lloguer->es_habitatge) {
            // Sense numeració: els mensuals per mes i, al final, els puntuals.
            $query->orderByRaw("CASE WHEN tipus = 'mensual' THEN 0 ELSE 1 END")
                ->orderBy('mes')
                ->orderBy('data_emissio');
        } else {
            // El número de factura és la seqüència d'emissió: una factura puntual
            // va entre les mensuals del seu mes, no al final de la llista.
            // Les que encara no en tenen (esborranys) van al final.
            $query->orderByRaw("CASE WHEN numero_factura IS NULL OR numero_factura = '' THEN 1 ELSE 0 END")
                ->orderBy('numero_factura')
                ->orderByRaw("CASE WHEN tipus = 'mensual' THEN 0 ELSE 1 END")
                ->orderBy('mes')
                ->orderBy('data_emissio');
        }

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

    /**
     * Les factures d'un rang de mesos: la renda de cada mes i, si l'any té
     * escombraries configurades, les seves fraccions —com a línia de la mensual o
     * com a factura a banda, segons el local.
     */
    public function generar(Lloguer $lloguer, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'any'       => 'required|integer|min:2000|max:2100',
            'mes_inici' => 'required|integer|min:1|max:12',
            'mes_fi'    => 'required|integer|min:1|max:12|gte:mes_inici',
        ]);

        $any = $validated['any'];

        $contracteActiu = $lloguer->contractes()
            ->where(function ($q) {
                $q->whereNull('data_fi')->orWhere('data_fi', '>', now()->toDateString());
            })
            ->first();

        $base = (float) $lloguer->base_euros;
        $ivaPerc = (float) $lloguer->iva_percentatge;
        $irpfPerc = $lloguer->retencio_irpf ? (float) $lloguer->irpf_percentatge : 0;

        $numero = $this->darrerNumero($lloguer, $any);

        $escombraries = $lloguer->escombrariesDe($any);
        $fraccions = $escombraries?->fraccions ?? collect();
        $totalFraccions = $fraccions->count();

        $creades = [];

        for ($mes = $validated['mes_inici']; $mes <= $validated['mes_fi']; $mes++) {
            $mensual = $lloguer->factures()
                ->where('tipus', 'mensual')
                ->where('any', $any)
                ->where('mes', $mes)
                ->first();

            if (!$mensual) {
                $numero++;

                $mensual = $lloguer->factures()->create([
                    'contracte_id'     => $contracteActiu?->id,
                    'any'              => $any,
                    'mes'              => $mes,
                    'tipus'            => 'mensual',
                    'numero_factura'   => sprintf('%d%02d', $any, $numero),
                    'base'             => $base,
                    'iva_percentatge'  => $ivaPerc,
                    'irpf_percentatge' => $irpfPerc,
                    'iva_import'       => 0,
                    'irpf_import'      => 0,
                    'total'            => 0,
                    'estat'            => 'esborrany',
                    'data_emissio'     => sprintf('%d-%02d-01', $any, $mes),
                ]);

                $mensual->linies()->create([
                    'concepte'    => 'lloguer_base',
                    'descripcio'  => 'Lloguer base',
                    'base'        => $base,
                    'iva_import'  => round($base * $ivaPerc / 100, 2),
                    'irpf_import' => round($base * $irpfPerc / 100, 2),
                ]);

                $this->recalcula($mensual);
                $creades[] = $mensual->load('linies');
            }

            $fraccio = $fraccions->firstWhere('mes', $mes);

            if (!$fraccio) {
                continue;
            }

            $descripcio = $fraccio->descripcio($totalFraccions);

            if ($escombraries->factura_separada) {
                $puntual = $this->facturaEscombraries($lloguer, $any, $mes, $descripcio, $contracteActiu?->id, $fraccio, $numero + 1);

                if ($puntual) {
                    $numero++;
                    $creades[] = $puntual;
                }

                continue;
            }

            // Com a línia de la mensual: només si encara no la porta.
            if ($mensual->linies()->where('concepte', 'escombraries')->exists()) {
                continue;
            }

            $mensual->linies()->create([
                'concepte'    => 'escombraries',
                'descripcio'  => $descripcio,
                'base'        => $fraccio->import,
                'iva_import'  => round((float) $fraccio->import * $ivaPerc / 100, 2),
                'irpf_import' => round((float) $fraccio->import * $irpfPerc / 100, 2),
            ]);

            $this->recalcula($mensual);
        }

        return response()->json([
            'creades' => count($creades),
            'data'    => $creades,
        ]);
    }

    /** La fracció d'escombraries en una factura pròpia, com a Juli Garreta. */
    private function facturaEscombraries(
        Lloguer $lloguer,
        int $any,
        int $mes,
        string $descripcio,
        ?int $contracteId,
        $fraccio,
        int $numero,
    ): ?Factura {
        $jaHiEs = $lloguer->factures()
            ->where('tipus', 'puntual')
            ->where('any', $any)
            ->where('mes', $mes)
            ->whereHas('linies', fn ($q) => $q->where('concepte', 'escombraries'))
            ->exists();

        if ($jaHiEs) {
            return null;
        }

        $ivaPerc = (float) $lloguer->iva_percentatge;
        $irpfPerc = $lloguer->retencio_irpf ? (float) $lloguer->irpf_percentatge : 0;

        $factura = $lloguer->factures()->create([
            'contracte_id'     => $contracteId,
            'any'              => $any,
            'mes'              => $mes,
            'tipus'            => 'puntual',
            'numero_factura'   => sprintf('%d%02d', $any, $numero),
            'base'             => $fraccio->import,
            'iva_percentatge'  => $ivaPerc,
            'irpf_percentatge' => $irpfPerc,
            'iva_import'       => 0,
            'irpf_import'      => 0,
            'total'            => 0,
            'estat'            => 'esborrany',
            'data_emissio'     => sprintf('%d-%02d-01', $any, $mes),
        ]);

        $factura->linies()->create([
            'concepte'    => 'escombraries',
            'descripcio'  => $descripcio,
            'base'        => $fraccio->import,
            'iva_import'  => round((float) $fraccio->import * $ivaPerc / 100, 2),
            'irpf_import' => round((float) $fraccio->import * $irpfPerc / 100, 2),
        ]);

        $this->recalcula($factura);

        return $factura->load('linies');
    }

    /**
     * L'últim número de l'any, que és des d'on continua la seqüència. A Joan
     * Maragall coincideix amb el mes perquè no hi ha res intercalat; a Juli Garreta,
     * no.
     */
    private function darrerNumero(Lloguer $lloguer, int $any): int
    {
        $darrer = $lloguer->factures()
            ->where('any', $any)
            ->whereNotNull('numero_factura')
            ->where('numero_factura', 'like', $any . '%')
            ->max('numero_factura');

        return $darrer ? (int) substr((string) $darrer, -2) : 0;
    }

    /** Els totals de la factura són sempre la suma de les seves línies. */
    private function recalcula(Factura $factura): void
    {
        $linies = $factura->linies()->get();

        $base = round($linies->sum(fn ($l) => (float) $l->base), 2);
        $iva = round($linies->sum(fn ($l) => (float) $l->iva_import), 2);
        $irpf = round($linies->sum(fn ($l) => (float) $l->irpf_import), 2);

        $factura->update([
            'base'        => $base,
            'iva_import'  => $iva,
            'irpf_import' => $irpf,
            'total'       => round($base + $iva - $irpf, 2),
        ]);
    }

    /** El PDF de la factura, per veure'l al navegador o baixar-lo. */
    public function pdf(Factura $factura, FacturaPdfService $servei, Request $request)
    {
        $pdf = $servei->pdf($factura);
        $nom = $servei->nomFitxer($factura);

        return $request->boolean('baixa')
            ? $pdf->download($nom)
            : $pdf->stream($nom);
    }

    /**
     * Desa el PDF a la carpeta de l'any de l'immoble, com fa el llibre d'IVA: si el
     * fitxer ja hi és, es demana confirmació abans de trepitjar-lo.
     */
    public function desar(Factura $factura, FacturaPdfService $servei, Request $request): JsonResponse
    {
        $dir = $servei->directori($factura);

        if (!$dir) {
            return response()->json([
                'error' => "Aquest lloguer no té carpeta de factures: s'indica a la seva fitxa.",
            ], 422);
        }

        $nom = $servei->nomFitxer($factura);
        $cami = $dir . DIRECTORY_SEPARATOR . $nom;

        if (file_exists($cami) && !$request->boolean('force')) {
            return response()->json(['exists' => true, 'filename' => $nom]);
        }

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        file_put_contents($cami, $servei->pdf($factura)->output());

        // Desar-la és emetre-la; si ja s'ha cobrat, l'estat no es toca.
        if ($factura->estat === 'esborrany') {
            $factura->update(['estat' => 'emesa']);
        }

        return response()->json(['saved' => true, 'filename' => $nom, 'directori' => $dir]);
    }

    /** Totes les factures d'un any de cop, per no haver-les de desar una per una. */
    public function desarAny(Lloguer $lloguer, FacturaPdfService $servei, Request $request): JsonResponse
    {
        $any = $request->integer('any') ?: (int) date('Y');

        $factures = $lloguer->factures()
            ->with('linies')
            ->where(function ($q) use ($any) {
                $q->where('any', $any)
                    ->orWhere(fn ($q2) => $q2->whereNull('any')->whereYear('data_emissio', $any));
            })
            ->get();

        $desades = [];
        $existents = [];

        foreach ($factures as $factura) {
            $dir = $servei->directori($factura);

            if (!$dir) {
                return response()->json([
                    'error' => "Aquest lloguer no té carpeta de factures: s'indica a la seva fitxa.",
                ], 422);
            }

            $nom = $servei->nomFitxer($factura);
            $cami = $dir . DIRECTORY_SEPARATOR . $nom;

            if (file_exists($cami) && !$request->boolean('force')) {
                $existents[] = $nom;

                continue;
            }

            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            file_put_contents($cami, $servei->pdf($factura)->output());

            if ($factura->estat === 'esborrany') {
                $factura->update(['estat' => 'emesa']);
            }

            $desades[] = $nom;
        }

        return response()->json([
            'desades'   => $desades,
            'existents' => $existents,
        ]);
    }

    /** Les dades del correu i l'enllaç que n'obre l'esborrany a Gmail. */
    public function correu(Factura $factura, FacturaCorreuService $servei): JsonResponse
    {
        return response()->json($servei->dades($factura) + [
            'url' => $servei->enllacGmail($factura),
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
