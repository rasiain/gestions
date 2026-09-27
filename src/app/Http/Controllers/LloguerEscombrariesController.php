<?php

namespace App\Http\Controllers;

use App\Models\Lloguer;
use App\Models\LloguerEscombraries;
use App\Models\TaxaRebut;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * La configuració anual de les escombraries industrials d'un local: quant se'n
 * repercuteix, en quins mesos i si va en una factura a banda.
 */
class LloguerEscombrariesController extends Controller
{
    public function index(Lloguer $lloguer, Request $request): JsonResponse
    {
        $any = $request->integer('any') ?: (int) date('Y');

        return response()->json([
            'data'     => $lloguer->escombrariesDe($any),
            'proposta' => $this->proposta($lloguer, $any),
        ]);
    }

    public function store(Lloguer $lloguer, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'any'                  => 'required|integer|min:2000|max:2100',
            'import_total'         => 'nullable|numeric|min:0',
            'factura_separada'     => 'boolean',
            'fraccions'            => 'nullable|array',
            'fraccions.*.mes'      => 'required|integer|min:1|max:12',
            'fraccions.*.import'   => 'required|numeric',
        ]);

        $escombraries = DB::transaction(function () use ($lloguer, $validated) {
            $escombraries = LloguerEscombraries::updateOrCreate(
                ['lloguer_id' => $lloguer->id, 'any' => $validated['any']],
                [
                    'import_total'     => $validated['import_total'] ?? null,
                    'factura_separada' => $validated['factura_separada'] ?? false,
                ],
            );

            // Les fraccions es reemplacen senceres: l'ordre és el de la llista, que
            // és el que surt a la factura («1 de 3»).
            $escombraries->fraccions()->delete();

            foreach (array_values($validated['fraccions'] ?? []) as $i => $fraccio) {
                $escombraries->fraccions()->create([
                    'ordre'  => $i + 1,
                    'mes'    => $fraccio['mes'],
                    'import' => $fraccio['import'],
                ]);
            }

            return $escombraries;
        });

        return response()->json($escombraries->load('fraccions'));
    }

    public function destroy(Lloguer $lloguer, Request $request): JsonResponse
    {
        $any = $request->integer('any') ?: (int) date('Y');

        $lloguer->escombraries()->where('any', $any)->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * El rebut de l'ajuntament diu el total i els terminis, i d'aquí surt la
     * proposta; el que es repercuteix, però, és el que s'ha pactat, i es desa a part.
     */
    private function proposta(Lloguer $lloguer, int $any): ?array
    {
        if (!$lloguer->immoble_id) {
            return null;
        }

        $rebut = TaxaRebut::where('immoble_id', $lloguer->immoble_id)
            ->where('any', $any)
            ->where('repercutible', true)
            ->where('concepte_repercussio', 'escombraries')
            ->first();

        if (!$rebut) {
            return null;
        }

        $terminis = $rebut->terminis_previstos ?: 1;

        return [
            'import_total' => (float) $rebut->import_total,
            'terminis'     => $terminis,
            'import'       => round((float) $rebut->import_total / $terminis, 2),
        ];
    }
}
