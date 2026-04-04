<?php

namespace App\Http\Controllers;

use App\Models\Factura;
use App\Models\Lloguer;
use App\Models\MovimentLloguerDespesa;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ImpostosIvaController extends Controller
{
    public function index(Request $request)
    {
        $any = $request->integer('any', (int) date('Y'));

        $lloguers = Lloguer::where('es_habitatge', false)
            ->with([
                'immoble',
                'contractes' => function ($q) {
                    $q->where(function ($q2) {
                        $q2->whereNull('data_fi')->orWhere('data_fi', '>', now()->toDateString());
                    })->orderBy('data_inici', 'desc');
                },
                'contractes.arrendador.arrendadorable',
            ])
            ->orderBy('nom')
            ->get();

        $totals = [
            'trimestres' => [
                1 => ['base' => 0, 'iva_repercutit' => 0, 'iva_suportat' => 0, 'resultat' => 0],
                2 => ['base' => 0, 'iva_repercutit' => 0, 'iva_suportat' => 0, 'resultat' => 0],
                3 => ['base' => 0, 'iva_repercutit' => 0, 'iva_suportat' => 0, 'resultat' => 0],
                4 => ['base' => 0, 'iva_repercutit' => 0, 'iva_suportat' => 0, 'resultat' => 0],
            ],
            'total_base'          => 0,
            'total_iva_repercutit' => 0,
            'total_iva_suportat'  => 0,
            'total_resultat'      => 0,
        ];

        $lloguersDades = $lloguers->map(function ($lloguer) use ($any, &$totals) {
            $factures = Factura::where('lloguer_id', $lloguer->id)
                ->where('any', $any)
                ->get()
                ->groupBy(fn ($f) => (int) ceil($f->mes / 3));

            $despeses = MovimentLloguerDespesa::where('lloguer_id', $lloguer->id)
                ->whereNotNull('iva_import')
                ->whereHas('moviment', fn ($q) => $q->whereYear('data_moviment', $any))
                ->with('moviment')
                ->get()
                ->groupBy(fn ($d) => (int) ceil($d->moviment->data_moviment->month / 3));

            $trimestres = [];
            $totalBase          = 0;
            $totalIvaRepercutit = 0;
            $totalIvaSuportat   = 0;

            for ($t = 1; $t <= 4; $t++) {
                $facturesTrimestre = $factures->get($t, collect());
                $despesesTrimestre = $despeses->get($t, collect());

                $base          = (float) $facturesTrimestre->sum('base');
                $ivaRepercutit = (float) $facturesTrimestre->sum('iva_import');
                $ivaSuportat   = (float) $despesesTrimestre->sum('iva_import');
                $resultat      = $ivaRepercutit - $ivaSuportat;

                $trimestres[$t] = [
                    'base'          => $base,
                    'iva_repercutit' => $ivaRepercutit,
                    'iva_suportat'  => $ivaSuportat,
                    'resultat'      => $resultat,
                    'factures'      => $facturesTrimestre->map(fn ($f) => [
                        'data'    => $f->data_emissio?->toDateString() ?? "{$any}-" . str_pad($f->mes, 2, '0', STR_PAD_LEFT) . "-01",
                        'base'    => (float) $f->base,
                        'iva'     => (float) $f->iva_import,
                        'numero'  => $f->numero_factura,
                    ])->values()->toArray(),
                    'despeses_iva'  => $despesesTrimestre->map(fn ($d) => [
                        'data'           => $d->moviment->data_moviment->toDateString(),
                        'base_imposable' => (float) $d->base_imposable,
                        'iva_import'     => (float) $d->iva_import,
                        'categoria'      => $d->categoria,
                        'notes'          => $d->notes,
                    ])->values()->toArray(),
                ];

                $totalBase          += $base;
                $totalIvaRepercutit += $ivaRepercutit;
                $totalIvaSuportat   += $ivaSuportat;

                $totals['trimestres'][$t]['base']          += $base;
                $totals['trimestres'][$t]['iva_repercutit'] += $ivaRepercutit;
                $totals['trimestres'][$t]['iva_suportat']  += $ivaSuportat;
                $totals['trimestres'][$t]['resultat']      += ($ivaRepercutit - $ivaSuportat);
            }

            $totalResultat = $totalIvaRepercutit - $totalIvaSuportat;

            $totals['total_base']          += $totalBase;
            $totals['total_iva_repercutit'] += $totalIvaRepercutit;
            $totals['total_iva_suportat']  += $totalIvaSuportat;
            $totals['total_resultat']      += $totalResultat;

            $contracteActiu = $lloguer->contractes->first();
            $arrendador = $contracteActiu?->arrendador;
            $arrendadorable = $arrendador?->arrendadorable;

            return [
                'id'              => $lloguer->id,
                'nom'             => $lloguer->nom,
                'ruta_descarrega' => $lloguer->ruta_descarrega,
                'immoble_adreca'  => $lloguer->immoble?->adreca,
                'arrendador'    => $arrendadorable ? (function () use ($arrendadorable) {
                    $isPersona = $arrendadorable instanceof \App\Models\Persona;
                    $base = [
                        'nom'   => $isPersona
                            ? trim($arrendadorable->nom . ' ' . $arrendadorable->cognoms)
                            : $arrendadorable->nom,
                        'nif'   => $arrendadorable->nif,
                        'tipus' => $isPersona ? 'Persona' : 'CB',
                    ];
                    if (! $isPersona) {
                        $base['adreca']         = $arrendadorable->adreca;
                        $base['activitat']      = $arrendadorable->activitat;
                        $base['codi_activitat'] = $arrendadorable->codi_activitat;
                        $base['epigraf_iae']    = $arrendadorable->epigraf_iae;
                        $base['comuners']       = $arrendadorable->comuners()
                            ->get()
                            ->map(fn ($c) => trim($c->nom . ' ' . $c->cognoms))
                            ->values()
                            ->toArray();
                    }
                    return $base;
                })() : null,
                'trimestres'    => $trimestres,
                'total_base'    => $totalBase,
                'total_iva_repercutit' => $totalIvaRepercutit,
                'total_iva_suportat'  => $totalIvaSuportat,
                'total_resultat' => $totalResultat,
            ];
        });

        return Inertia::render('Impostos/Iva', [
            'any'      => $any,
            'lloguers' => $lloguersDades,
            'totals'   => $totals,
        ]);
    }
}
