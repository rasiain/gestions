<?php

namespace App\Http\Controllers;

use App\Http\Requests\LloguerRequest;
use App\Models\CompteCorrent;
use App\Models\Immoble;
use App\Models\Llogater;
use App\Models\Lloguer;
use App\Models\Categoria;
use App\Models\MovimentCompteCorrent;
use App\Models\Proveidor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Inertia\Inertia;

class LloguerController extends Controller
{
    public function index()
    {
        $lloguers = Lloguer::with([
                'immoble',
                'compteCorrent',
                'gestoria',
                'contractes' => function ($q) {
                    $q->where(function ($q2) {
                        $q2->whereNull('data_fi')->orWhere('data_fi', '>', now()->toDateString());
                    })->orderBy('data_inici', 'desc');
                },
                'contractes.llogaters',
            ])
            ->orderBy('nom')
            ->get()
            ->map(function ($lloguer) {
                $contracteActiu = $lloguer->contractes->first();
                return [
                    'id'               => $lloguer->id,
                    'nom'              => $lloguer->nom,
                    'acronim'          => $lloguer->acronim,
                    'immoble_id'       => $lloguer->immoble_id,
                    'immoble'          => $lloguer->immoble ? [
                        'id'    => $lloguer->immoble->id,
                        'adreca' => $lloguer->immoble->adreca,
                    ] : null,
                    'compte_corrent_id' => $lloguer->compte_corrent_id,
                    'compte_corrent'   => $lloguer->compteCorrent ? [
                        'id'  => $lloguer->compteCorrent->id,
                        'nom' => $lloguer->compteCorrent->nom,
                    ] : null,
                    'base_euros'            => $lloguer->base_euros,
                    'proveidor_gestoria_id' => $lloguer->proveidor_gestoria_id,
                    'gestoria_percentatge'  => $lloguer->gestoria_percentatge,
                    'gestoria'              => $lloguer->gestoria ? [
                        'id'             => $lloguer->gestoria->id,
                        'nom_rao_social' => $lloguer->gestoria->nom_rao_social,
                    ] : null,
                    'contracte_actiu'  => $contracteActiu ? [
                        'id'          => $contracteActiu->id,
                        'lloguer_id'  => $lloguer->id,
                        'data_inici'  => $contracteActiu->data_inici?->toDateString(),
                        'data_fi'     => $contracteActiu->data_fi?->toDateString(),
                        'llogater_ids' => $contracteActiu->llogaters->pluck('id')->toArray(),
                        'llogaters'   => $contracteActiu->llogaters->map(fn($l) => [
                            'id'      => $l->id,
                            'nom'     => $l->nom,
                            'cognoms' => $l->cognoms,
                        ])->values()->toArray(),
                    ] : null,
                ];
            });

        $immobles = Immoble::orderBy('adreca')->get(['id', 'adreca']);
        $comptesCorrents = CompteCorrent::orderBy('nom')->get(['id', 'nom']);
        $llogaters = Llogater::orderBy('cognoms')->orderBy('nom')->get(['id', 'nom', 'cognoms']);
        $proveidors = Proveidor::orderBy('nom_rao_social')->get(['id', 'nom_rao_social']);

        return Inertia::render('Lloguers/Index', [
            'lloguers'        => $lloguers,
            'immobles'        => $immobles,
            'comptesCorrents' => $comptesCorrents,
            'llogaters'       => $llogaters,
            'proveidors'      => $proveidors,
        ]);
    }

    public function store(LloguerRequest $request)
    {
        Lloguer::create($request->validated());

        return redirect()->route('lloguers.index')
            ->with('success', 'Lloguer creat correctament.');
    }

    public function update(LloguerRequest $request, Lloguer $lloguer)
    {
        $lloguer->update($request->validated());

        return redirect()->route('lloguers.index')
            ->with('success', 'Lloguer actualitzat correctament.');
    }

    public function destroy(Lloguer $lloguer)
    {
        $lloguer->delete();

        return redirect()->route('lloguers.index')
            ->with('success', 'Lloguer eliminat correctament.');
    }

    public function moviments(Lloguer $lloguer, Request $request): JsonResponse
    {
        $page    = max(1, $request->integer('page', 1));
        $perPage = 30;

        $query = MovimentCompteCorrent::with(['concepte', 'categoria', 'despesa', 'ingres.linies'])
            ->where('compte_corrent_id', $lloguer->compte_corrent_id);

        if ($any = $request->integer('any')) {
            $query->whereYear('data_moviment', $any);
        }

        if ($request->boolean('classificats')) {
            $query->where(function ($q) use ($lloguer) {
                $q->whereHas('despesa', fn($q2) => $q2->where('lloguer_id', $lloguer->id))
                  ->orWhereHas('ingres', fn($q2) => $q2->where('lloguer_id', $lloguer->id));
            });
        }

        if ($request->boolean('pendents')) {
            $query->whereDoesntHave('despesa')
                  ->whereDoesntHave('ingres')
                  ->where('exclou_lloguer', false);
        }

        $query->orderBy('data_moviment', 'desc')
              ->orderBy('id', 'desc');

        $total    = $query->count();
        $moviments = $query->skip(($page - 1) * $perPage)->take($perPage)->get()
            ->map(fn($m) => [
                'id'              => $m->id,
                'compte_corrent_id' => $m->compte_corrent_id,
                'data_moviment'   => $m->data_moviment->toDateString(),
                'concepte'        => $m->concepte?->concepte ?? $m->concepte_original ?? '',
                'notes'           => $m->notes,
                'import'          => $m->import,
                'saldo_posterior' => $m->saldo_posterior,
                'exclou_lloguer'  => $m->exclou_lloguer,
                'categoria_id'    => $m->categoria_id,
                'categoria_nom'   => $m->categoria?->nom,
                'despesa'         => $m->despesa ? [
                    'id'           => $m->despesa->id,
                    'lloguer_id'   => $m->despesa->lloguer_id,
                    'categoria'    => $m->despesa->categoria,
                    'proveidor_id' => $m->despesa->proveidor_id,
                    'notes'        => $m->despesa->notes,
                ] : null,
                'ingres'          => $m->ingres ? [
                    'id'              => $m->ingres->id,
                    'lloguer_id'      => $m->ingres->lloguer_id,
                    'base_lloguer'    => $m->ingres->base_lloguer,
                    'gestoria_import' => $m->ingres->gestoria_import,
                    'linies'          => $m->ingres->linies->map(fn($l) => [
                        'id'           => $l->id,
                        'tipus'        => $l->tipus,
                        'descripcio'   => $l->descripcio,
                        'import'       => $l->import,
                        'proveidor_id' => $l->proveidor_id,
                    ])->toArray(),
                ] : null,
            ]);

        $categories = Categoria::where('compte_corrent_id', $lloguer->compte_corrent_id)
            ->orderBy('nom')
            ->get(['id', 'nom', 'compte_corrent_id', 'categoria_pare_id', 'ordre'])
            ->toArray();

        $response = [
            'data'       => $moviments,
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $perPage,
            'has_more'   => ($page * $perPage) < $total,
            'categories' => $categories,
        ];

        if ($page === 1) {
            $response['anys'] = MovimentCompteCorrent::where('compte_corrent_id', $lloguer->compte_corrent_id)
                ->selectRaw("DISTINCT strftime('%Y', data_moviment) as any")
                ->orderBy('any', 'desc')
                ->pluck('any')
                ->map(fn($v) => (int) $v);
        }

        return response()->json($response);
    }

    public function exportar(Lloguer $lloguer, Request $request): StreamedResponse
    {
        $lloguer->load('immoble');
        $any = $request->integer('any') ?: null;

        $moviments = MovimentCompteCorrent::where('compte_corrent_id', $lloguer->compte_corrent_id)
            ->where('exclou_lloguer', false)
            ->with(['ingres.linies', 'despesa.proveidor', 'concepte'])
            ->when($any, fn($q) => $q->whereYear('data_moviment', $any))
            ->where(function ($q) use ($lloguer) {
                $q->whereHas('despesa', fn($q2) => $q2->where('lloguer_id', $lloguer->id))
                  ->orWhereHas('ingres', fn($q2) => $q2->where('lloguer_id', $lloguer->id));
            })
            ->orderBy('data_moviment')
            ->get();

        $proveidors = Proveidor::pluck('nom_rao_social', 'id');

        $ingressos = [];
        $despeses = [];
        $totalBase = 0;
        $totalGestoria = 0;
        $totalDespeses = 0;

        foreach ($moviments as $moviment) {
            if ($moviment->ingres && $moviment->ingres->lloguer_id === $lloguer->id) {
                $base = (float) $moviment->ingres->base_lloguer;
                $gestoria = (float) ($moviment->ingres->gestoria_import ?? 0);
                $totalBase += $base;
                $totalGestoria += $gestoria;

                $concepte = $moviment->concepte?->concepte ?? $moviment->concepte_original ?? '';
                $ingressos[] = [
                    'data' => $moviment->data_moviment->toDateString(),
                    'concepte' => $concepte,
                    'base' => $base,
                    'gestoria' => $gestoria > 0 ? -$gestoria : null,
                    'notes' => $moviment->ingres->notes ?? '',
                ];

                if ($gestoria > 0) {
                    $despeses[] = [
                        'data' => $moviment->data_moviment->toDateString(),
                        'categoria' => 'Gestoria',
                        'concepte' => $concepte,
                        'proveidor' => '',
                        'import' => -$gestoria,
                        'notes' => '',
                    ];
                    $totalDespeses -= $gestoria;
                }

                foreach ($moviment->ingres->linies as $linia) {
                    $importLinia = (float) $linia->import;
                    $despeses[] = [
                        'data' => $moviment->data_moviment->toDateString(),
                        'categoria' => ucfirst($linia->tipus),
                        'concepte' => $linia->descripcio,
                        'proveidor' => $linia->proveidor_id ? ($proveidors[$linia->proveidor_id] ?? '') : '',
                        'import' => -$importLinia,
                        'notes' => '',
                    ];
                    $totalDespeses -= $importLinia;
                }
            }

            if ($moviment->despesa && $moviment->despesa->lloguer_id === $lloguer->id) {
                $importDespesa = (float) $moviment->import;
                $concepte = $moviment->concepte?->concepte ?? $moviment->concepte_original ?? '';
                $despeses[] = [
                    'data' => $moviment->data_moviment->toDateString(),
                    'categoria' => ucfirst($moviment->despesa->categoria ?? 'altres'),
                    'concepte' => $concepte,
                    'proveidor' => $moviment->despesa->proveidor_id ? ($proveidors[$moviment->despesa->proveidor_id] ?? '') : '',
                    'import' => $importDespesa,
                    'notes' => $moviment->despesa->notes ?? '',
                ];
                $totalDespeses += $importDespesa;
            }
        }

        usort($despeses, fn($a, $b) => strcmp($a['data'], $b['data']));

        $anyLabel = $any ?? 'tots';
        $filename = sprintf('lloguer-%s-%s.xlsx', $lloguer->acronim ?? $lloguer->id, $anyLabel);

        $spreadsheet = new Spreadsheet();
        $euroFormat = '#,##0.00';
        $dateFormat = 'YYYY-MM-DD';

        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4B5563']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ];
        $totalStyle = [
            'font' => ['bold' => true],
            'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN]],
        ];

        // --- Pestanya 1: Resum ---
        $resum = $spreadsheet->getActiveSheet();
        $resum->setTitle('Resum');

        $resum->setCellValue('A1', 'Lloguer');
        $resum->setCellValue('B1', $lloguer->nom);
        $resum->setCellValue('A2', 'Immoble');
        $resum->setCellValue('B2', $lloguer->immoble?->adreca ?? '');
        $resum->setCellValue('A3', 'Any');
        $resum->setCellValue('B3', $any ?? 'Tots');
        $resum->getStyle('A1:A3')->getFont()->setBold(true);

        $resum->setCellValue('A5', 'Concepte');
        $resum->setCellValue('B5', 'Import');
        $resum->getStyle('A5:B5')->applyFromArray($headerStyle);

        $resum->setCellValue('A6', 'Total ingressos bruts');
        $resum->setCellValue('B6', $totalBase);
        $resum->setCellValue('A7', 'Total despeses gestoria');
        $resum->setCellValue('B7', -$totalGestoria);
        $resum->setCellValue('A8', 'Total despeses');
        $resum->setCellValue('B8', $totalDespeses);
        $resum->setCellValue('A9', 'Resultat net');
        $resum->setCellValue('B9', $totalBase + $totalDespeses);
        $resum->getStyle('A9:B9')->applyFromArray($totalStyle);
        $resum->getStyle('B6:B9')->getNumberFormat()->setFormatCode($euroFormat);

        $resum->getColumnDimension('A')->setWidth(25);
        $resum->getColumnDimension('B')->setWidth(18);

        // --- Pestanya 2: Ingressos ---
        $sheetIng = $spreadsheet->createSheet();
        $sheetIng->setTitle('Ingressos');

        $sheetIng->setCellValue('A1', 'Data');
        $sheetIng->setCellValue('B1', 'Concepte');
        $sheetIng->setCellValue('C1', 'Base lloguer');
        $sheetIng->setCellValue('D1', 'Gestoria');
        $sheetIng->setCellValue('E1', 'Notes');
        $sheetIng->getStyle('A1:E1')->applyFromArray($headerStyle);

        $row = 2;
        foreach ($ingressos as $ing) {
            $sheetIng->setCellValue("A{$row}", $ing['data']);
            $sheetIng->setCellValue("B{$row}", $ing['concepte']);
            $sheetIng->setCellValue("C{$row}", $ing['base']);
            if ($ing['gestoria'] !== null) {
                $sheetIng->setCellValue("D{$row}", $ing['gestoria']);
            }
            $sheetIng->setCellValue("E{$row}", $ing['notes']);
            $row++;
        }

        // Totals
        $sheetIng->setCellValue("A{$row}", 'TOTAL');
        $sheetIng->setCellValue("C{$row}", $totalBase);
        $sheetIng->setCellValue("D{$row}", $totalGestoria > 0 ? -$totalGestoria : null);
        $sheetIng->getStyle("A{$row}:E{$row}")->applyFromArray($totalStyle);

        $sheetIng->getStyle("C2:D{$row}")->getNumberFormat()->setFormatCode($euroFormat);
        $sheetIng->getColumnDimension('A')->setWidth(12);
        $sheetIng->getColumnDimension('B')->setWidth(35);
        $sheetIng->getColumnDimension('C')->setWidth(15);
        $sheetIng->getColumnDimension('D')->setWidth(15);
        $sheetIng->getColumnDimension('E')->setWidth(30);

        // --- Pestanya 3: Despeses ---
        $sheetDesp = $spreadsheet->createSheet();
        $sheetDesp->setTitle('Despeses');

        $sheetDesp->setCellValue('A1', 'Data');
        $sheetDesp->setCellValue('B1', 'Categoria');
        $sheetDesp->setCellValue('C1', 'Concepte');
        $sheetDesp->setCellValue('D1', 'Proveïdor');
        $sheetDesp->setCellValue('E1', 'Import');
        $sheetDesp->setCellValue('F1', 'Notes');
        $sheetDesp->getStyle('A1:F1')->applyFromArray($headerStyle);

        $row = 2;
        foreach ($despeses as $desp) {
            $sheetDesp->setCellValue("A{$row}", $desp['data']);
            $sheetDesp->setCellValue("B{$row}", $desp['categoria']);
            $sheetDesp->setCellValue("C{$row}", $desp['concepte']);
            $sheetDesp->setCellValue("D{$row}", $desp['proveidor']);
            $sheetDesp->setCellValue("E{$row}", $desp['import']);
            $sheetDesp->setCellValue("F{$row}", $desp['notes']);
            $row++;
        }

        // Totals
        $sheetDesp->setCellValue("A{$row}", 'TOTAL');
        $sheetDesp->setCellValue("E{$row}", $totalDespeses);
        $sheetDesp->getStyle("A{$row}:F{$row}")->applyFromArray($totalStyle);

        $sheetDesp->getStyle("E2:E{$row}")->getNumberFormat()->setFormatCode($euroFormat);
        $sheetDesp->getColumnDimension('A')->setWidth(12);
        $sheetDesp->getColumnDimension('B')->setWidth(15);
        $sheetDesp->getColumnDimension('C')->setWidth(35);
        $sheetDesp->getColumnDimension('D')->setWidth(25);
        $sheetDesp->getColumnDimension('E')->setWidth(15);
        $sheetDesp->getColumnDimension('F')->setWidth(30);

        // Activar la primera pestanya
        $spreadsheet->setActiveSheetIndex(0);

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
