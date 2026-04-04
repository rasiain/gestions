<?php

namespace App\Http\Controllers;

use App\Models\Lloguer;
use App\Models\MovimentCompteCorrent;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ImpostosIrpfController extends Controller
{
    public function index(Request $request)
    {
        $any = $request->integer('any', (int) date('Y'));

        $lloguers = Lloguer::with(['immoble', 'contractes.llogaters.persona'])
            ->where('es_habitatge', true)
            ->orderBy('nom')
            ->get();

        $categories = ['comunitat', 'taxes', 'assegurança', 'compres', 'reparacions', 'gestoria', 'comissions', 'altres'];

        $totals = [
            'total_ingressos' => 0,
            'total_despeses' => 0,
            'despeses_per_categoria' => array_fill_keys($categories, 0),
            'resultat_net' => 0,
        ];

        $lloguersDades = $lloguers->map(function ($lloguer) use ($any, $categories, &$totals) {
            $moviments = MovimentCompteCorrent::where('compte_corrent_id', $lloguer->compte_corrent_id)
                ->whereYear('data_moviment', $any)
                ->where('exclou_lloguer', false)
                ->with(['ingres.linies', 'despesa'])
                ->get();

            $totalIngressos = 0;
            $totalDespeses = 0;
            $despesesPerCategoria = array_fill_keys($categories, 0);
            $movimentsIngressos = [];
            $movimentsDespeses = array_fill_keys($categories, []);

            foreach ($moviments as $moviment) {
                if ($moviment->ingres && $moviment->ingres->lloguer_id === $lloguer->id) {
                    // L'ingrés comptable és la base_lloguer (import brut)
                    $baseLloguer = (float) $moviment->ingres->base_lloguer;
                    $totalIngressos += $baseLloguer;
                    $movimentsIngressos[] = [
                        'data' => $moviment->data_moviment->toDateString(),
                        'import' => $baseLloguer,
                    ];

                    // Les línies d'ingrés es comptabilitzen com a despeses per categoria
                    foreach ($moviment->ingres->linies as $linia) {
                        $cat = $linia->tipus ?? 'altres';
                        if (!isset($despesesPerCategoria[$cat])) {
                            $cat = 'altres';
                        }
                        $importLinia = (float) $linia->import;
                        $despesesPerCategoria[$cat] -= $importLinia;
                        $totalDespeses -= $importLinia;
                        $movimentsDespeses[$cat][] = [
                            'data' => $moviment->data_moviment->toDateString(),
                            'import' => -$importLinia,
                        ];
                    }
                }

                if ($moviment->despesa && $moviment->despesa->lloguer_id === $lloguer->id) {
                    $cat = $moviment->despesa->categoria ?? 'altres';
                    if (!isset($despesesPerCategoria[$cat])) {
                        $cat = 'altres';
                    }
                    $despesesPerCategoria[$cat] += (float) $moviment->import;
                    $totalDespeses += (float) $moviment->import;
                    $movimentsDespeses[$cat][] = [
                        'data' => $moviment->data_moviment->toDateString(),
                        'import' => (float) $moviment->import,
                    ];
                }
            }

            $totals['total_ingressos'] += $totalIngressos;
            $totals['total_despeses'] += $totalDespeses;
            foreach ($categories as $cat) {
                $totals['despeses_per_categoria'][$cat] += $despesesPerCategoria[$cat];
            }

            return [
                'id' => $lloguer->id,
                'nom' => $lloguer->nom,
                'immoble_adreca' => $lloguer->immoble?->adreca,
                'total_ingressos' => $totalIngressos,
                'total_despeses' => $totalDespeses,
                'despeses_per_categoria' => $despesesPerCategoria,
                'resultat_net' => $totalIngressos + $totalDespeses,
                'moviments_ingressos' => $movimentsIngressos,
                'moviments_despeses' => $movimentsDespeses,
                'contractes' => $lloguer->contractes->map(fn ($c) => [
                    'id' => $c->id,
                    'data_inici' => $c->data_inici->toDateString(),
                    'data_fi' => $c->data_fi?->toDateString(),
                    'llogaters' => $c->llogaters->map(fn ($l) => [
                        'nom' => $l->tipus === 'persona'
                            ? trim(($l->persona?->nom ?? '') . ' ' . ($l->persona?->cognoms ?? ''))
                            : ($l->nom_rao_social ?? ''),
                        'identificador' => $l->tipus === 'persona'
                            ? ($l->persona?->nif ?? '')
                            : ($l->nif ?? ''),
                    ])->values(),
                ])->values(),
            ];
        });

        $totals['resultat_net'] = $totals['total_ingressos'] + $totals['total_despeses'];

        return Inertia::render('Impostos/Irpf', [
            'any' => $any,
            'lloguers' => $lloguersDades,
            'totals' => $totals,
        ]);
    }
}
