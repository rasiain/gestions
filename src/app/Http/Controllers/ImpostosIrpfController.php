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

        $lloguers = Lloguer::with('immoble')
            ->orderBy('nom')
            ->get();

        $categories = ['comunitat', 'taxes', 'assegurança', 'compres', 'reparacions', 'altres'];

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
                ->with(['ingres', 'despesa'])
                ->get();

            $totalIngressos = 0;
            $totalDespeses = 0;
            $despesesPerCategoria = array_fill_keys($categories, 0);
            $movimentsIngressos = [];
            $movimentsDespeses = array_fill_keys($categories, []);

            foreach ($moviments as $moviment) {
                if ($moviment->ingres && $moviment->ingres->lloguer_id === $lloguer->id) {
                    $totalIngressos += (float) $moviment->import;
                    $movimentsIngressos[] = [
                        'data' => $moviment->data_moviment->toDateString(),
                        'import' => (float) $moviment->import,
                    ];
                }

                if ($moviment->despesa && $moviment->despesa->lloguer_id === $lloguer->id) {
                    $cat = $moviment->despesa->categoria ?? 'altres';
                    $despesesPerCategoria[$cat] = ($despesesPerCategoria[$cat] ?? 0) + (float) $moviment->import;
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
