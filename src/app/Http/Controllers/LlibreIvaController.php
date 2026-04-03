<?php

namespace App\Http\Controllers;

use App\Models\Factura;
use App\Models\Lloguer;
use App\Models\MovimentLloguerDespesa;
use App\Models\Persona;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LlibreIvaController extends Controller
{
    public function exportar(Lloguer $lloguer, Request $request): StreamedResponse
    {
        $any = $request->integer('any') ?: date('Y');

        // Carreguem el lloguer amb les relacions necessàries
        $lloguer->load(['immoble', 'contractes.llogaters', 'contractes.arrendador.arrendadorable']);

        // Determinem l'inici i fi de l'any
        $iniciAny = "{$any}-01-01";
        $fiAny    = "{$any}-12-31";

        // Contracte actiu: el primer on data_inici <= fi_any i (data_fi IS NULL o data_fi >= inici_any)
        $contracte = $lloguer->contractes->first(function ($c) use ($iniciAny, $fiAny) {
            $dataInici = $c->data_inici?->toDateString();
            $dataFi    = $c->data_fi?->toDateString();
            return $dataInici <= $fiAny && ($dataFi === null || $dataFi >= $iniciAny);
        });

        // Nom i NIF de l'arrendador
        $nomEmpresa = '';
        $nifEmpresa = '';
        if ($contracte && $contracte->arrendador && $contracte->arrendador->arrendadorable) {
            $arrendadorable = $contracte->arrendador->arrendadorable;
            if ($arrendadorable instanceof Persona) {
                $nomEmpresa = trim($arrendadorable->nom . ' ' . $arrendadorable->cognoms);
                $nifEmpresa = $arrendadorable->nif ?? '';
            } else {
                // ComunitatBens
                $nomEmpresa = $arrendadorable->nom;
                $nifEmpresa = $arrendadorable->nif ?? '';
            }
        }

        // Factures de l'any
        $factures = Factura::where('lloguer_id', $lloguer->id)
            ->where('any', $any)
            ->orderBy('mes')
            ->get();

        // Despeses de l'any
        $despeses = MovimentLloguerDespesa::where('lloguer_id', $lloguer->id)
            ->whereHas('moviment', fn($q) => $q->whereYear('data_moviment', $any))
            ->with(['moviment', 'proveidor', 'tipusDespesaFiscal'])
            ->join('g_moviments_comptes_corrents', 'g_moviments_comptes_corrents.id', '=', 'g_moviment_lloguer_despesa.moviment_id')
            ->orderBy('g_moviments_comptes_corrents.data_moviment')
            ->select('g_moviment_lloguer_despesa.*')
            ->get();

        $filename = sprintf('llibre-iva-%s-%s.xlsx', $lloguer->acronim ?? $lloguer->id, $any);

        $spreadsheet = new Spreadsheet();
        $euroFormat  = '#,##0.00';

        $headerStyle = [
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4B5563']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ];
        $totalStyle = [
            'font'    => ['bold' => true],
            'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN]],
        ];

        // ─── Pestanya 1: Llibre factures emeses ────────────────────
        $sheet1 = $spreadsheet->getActiveSheet();
        $sheet1->setTitle('Llibre factures emeses');

        $sheet1->mergeCells('A1:N1');
        $sheet1->setCellValue('A1', 'Llibre de factures emeses');
        $sheet1->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        $sheet1->setCellValue('A2', "Empresa: {$nomEmpresa}");
        $sheet1->setCellValue('A3', "NIF: {$nifEmpresa}");
        $sheet1->setCellValue('A4', "Any {$any}");

        $sheet1->mergeCells('M6:N6');
        $sheet1->setCellValue('M6', 'RECEPTOR');
        $sheet1->getStyle('M6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Capçaleres fila 7
        $sheet1->setCellValue('A7', 'NÚM. REG.');
        $sheet1->setCellValue('B7', 'NÚMERO');
        $sheet1->setCellValue('C7', 'DATA');
        $sheet1->setCellValue('D7', 'CONCEPTE');
        $sheet1->setCellValue('E7', '% IMPUTACIÓ');
        $sheet1->setCellValue('F7', 'BASE IMPOSABLE IMPUTABLE');
        $sheet1->setCellValue('G7', '% RETENCIÓ');
        $sheet1->setCellValue('H7', 'RETENCIÓ');
        $sheet1->setCellValue('I7', 'TIPUS IMPOST');
        $sheet1->setCellValue('J7', 'IMPOST IMPUTABLE');
        $sheet1->setCellValue('K7', 'TOTAL FRA.');
        $sheet1->setCellValue('L7', 'TIPUS OPERACIÓ');
        $sheet1->setCellValue('M7', 'NOM O RAÓ SOCIAL');
        $sheet1->setCellValue('N7', 'NIF');
        $sheet1->getStyle('A7:N7')->applyFromArray($headerStyle);

        $row = 8;
        $idx = 1;
        foreach ($factures as $factura) {
            // Llogater del contracte actiu a la data de la factura
            $dataFra = $factura->data_emissio?->toDateString() ?? "{$any}-{$factura->mes}-01";
            $contracteFra = $lloguer->contractes->first(function ($c) use ($dataFra) {
                $dataInici = $c->data_inici?->toDateString();
                $dataFi    = $c->data_fi?->toDateString();
                return $dataInici <= $dataFra && ($dataFi === null || $dataFi >= $dataFra);
            });
            $llogaterNom = '';
            $llogaterNif = '';
            if ($contracteFra) {
                $llogater = $contracteFra->llogaters->first();
                if ($llogater) {
                    $llogaterNom = trim($llogater->nom . ' ' . $llogater->cognoms);
                    $llogaterNif = $llogater->identificador ?? '';
                }
            }

            $dataFormatada = $factura->data_emissio
                ? $factura->data_emissio->format('d/m/Y')
                : '';

            $tipoImpost = $factura->iva_percentatge
                ? number_format((float) $factura->iva_percentatge, 0) . '% I.V.A.'
                : '21% I.V.A.';

            $adrecaImmoble = $lloguer->immoble?->adreca ?? '';

            $sheet1->setCellValue("A{$row}", $idx);
            $sheet1->setCellValue("B{$row}", $factura->numero_factura ?? '');
            $sheet1->setCellValue("C{$row}", $dataFormatada);
            $sheet1->setCellValue("D{$row}", "Lloguer mensual del local {$adrecaImmoble}");
            $sheet1->setCellValue("E{$row}", 1);
            $sheet1->setCellValue("F{$row}", (float) $factura->base);
            $sheet1->setCellValue("G{$row}", $factura->irpf_percentatge ? (float) $factura->irpf_percentatge / 100 : '');
            $sheet1->setCellValue("H{$row}", (float) $factura->irpf_import);
            $sheet1->setCellValue("I{$row}", $tipoImpost);
            $sheet1->setCellValue("J{$row}", (float) $factura->iva_import);
            $sheet1->setCellValue("K{$row}", (float) $factura->total);
            $sheet1->setCellValue("L{$row}", 'Nacional');
            $sheet1->setCellValue("M{$row}", $llogaterNom);
            $sheet1->setCellValue("N{$row}", $llogaterNif);

            $row++;
            $idx++;
        }

        // Fila totals
        $totalBase      = $factures->sum(fn($f) => (float) $f->base);
        $totalRetencio  = $factures->sum(fn($f) => (float) $f->irpf_import);
        $totalIva       = $factures->sum(fn($f) => (float) $f->iva_import);
        $totalTotal     = $factures->sum(fn($f) => (float) $f->total);

        $sheet1->setCellValue("A{$row}", 'TOTALS');
        $sheet1->setCellValue("F{$row}", $totalBase);
        $sheet1->setCellValue("H{$row}", $totalRetencio);
        $sheet1->setCellValue("J{$row}", $totalIva);
        $sheet1->setCellValue("K{$row}", $totalTotal);
        $sheet1->getStyle("A{$row}:N{$row}")->applyFromArray($totalStyle);

        if ($row > 8) {
            $sheet1->getStyle("F8:K{$row}")->getNumberFormat()->setFormatCode($euroFormat);
        }

        $sheet1->getColumnDimension('A')->setWidth(10);
        $sheet1->getColumnDimension('B')->setWidth(18);
        $sheet1->getColumnDimension('C')->setWidth(12);
        $sheet1->getColumnDimension('D')->setWidth(40);
        $sheet1->getColumnDimension('E')->setWidth(13);
        $sheet1->getColumnDimension('F')->setWidth(24);
        $sheet1->getColumnDimension('G')->setWidth(13);
        $sheet1->getColumnDimension('H')->setWidth(13);
        $sheet1->getColumnDimension('I')->setWidth(15);
        $sheet1->getColumnDimension('J')->setWidth(18);
        $sheet1->getColumnDimension('K')->setWidth(14);
        $sheet1->getColumnDimension('L')->setWidth(16);
        $sheet1->getColumnDimension('M')->setWidth(30);
        $sheet1->getColumnDimension('N')->setWidth(15);

        // ─── Pestanya 2: Llibre factures rebudes ───────────────────
        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('Llibre factures rebudes');

        $sheet2->setCellValue('A1', "Empresa: {$nomEmpresa}");
        $sheet2->setCellValue('A2', "NIF: {$nifEmpresa}");
        $sheet2->setCellValue('A3', "Any {$any}");

        $sheet2->mergeCells('I6:J6');
        $sheet2->setCellValue('I6', 'EMISSOR');
        $sheet2->getStyle('I6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet2->setCellValue('A7', 'NÚM. REG.');
        $sheet2->setCellValue('B7', 'NÚMERO');
        $sheet2->setCellValue('C7', 'DATA');
        $sheet2->setCellValue('D7', '% IMPUTACIÓ');
        $sheet2->setCellValue('E7', 'BASE IMPOSABLE IMPUTABLE');
        $sheet2->setCellValue('F7', 'TIPUS IMPOST');
        $sheet2->setCellValue('G7', 'IMPOST IMPUTABLE');
        $sheet2->setCellValue('H7', 'TIPUS OPERACIÓ');
        $sheet2->setCellValue('I7', 'NOM O RAÓ SOCIAL');
        $sheet2->setCellValue('J7', 'NIF');
        $sheet2->setCellValue('K7', 'CRITERI DE CAIXA');
        $sheet2->getStyle('A7:K7')->applyFromArray($headerStyle);

        // Despeses amb IVA (factures rebudes)
        $despesesIva = $despeses->filter(
            fn($d) => $d->base_imposable !== null && $d->iva_import !== null && (float) $d->iva_import > 0
        );

        $row2 = 8;
        $idx2 = 1;
        foreach ($despesesIva as $despesa) {
            $moviment  = $despesa->moviment;
            $proveidor = $despesa->proveidor;

            $dataMoviment = $moviment?->data_moviment
                ? \Carbon\Carbon::parse($moviment->data_moviment)->format('d/m/Y')
                : '';

            $base  = (float) $despesa->base_imposable;
            $iva   = (float) $despesa->iva_import;
            $pct   = $despesa->iva_percentatge
                ? number_format((float) $despesa->iva_percentatge, 0) . '% I.V.A.'
                : ($base > 0 ? number_format(round($iva / $base * 100), 0) . '% I.V.A.' : '21% I.V.A.');

            $sheet2->setCellValue("A{$row2}", $idx2);
            $sheet2->setCellValue("B{$row2}", $despesa->numero_factura ?? '');
            $sheet2->setCellValue("C{$row2}", $dataMoviment);
            $sheet2->setCellValue("D{$row2}", 1);
            $sheet2->setCellValue("E{$row2}", $base);
            $sheet2->setCellValue("F{$row2}", $pct);
            $sheet2->setCellValue("G{$row2}", $iva);
            $sheet2->setCellValue("H{$row2}", 'Nacional');
            $sheet2->setCellValue("I{$row2}", $proveidor?->nom_rao_social ?? '');
            $sheet2->setCellValue("J{$row2}", $proveidor?->nif_cif ?? '');
            $sheet2->setCellValue("K{$row2}", '');

            $row2++;
            $idx2++;
        }

        // Totals factures rebudes
        $totalBase2 = $despesesIva->sum(fn($d) => (float) $d->base_imposable);
        $totalIva2  = $despesesIva->sum(fn($d) => (float) $d->iva_import);

        $sheet2->setCellValue("A{$row2}", 'TOTALS');
        $sheet2->setCellValue("E{$row2}", $totalBase2);
        $sheet2->setCellValue("G{$row2}", $totalIva2);
        $sheet2->getStyle("A{$row2}:K{$row2}")->applyFromArray($totalStyle);

        if ($row2 > 8) {
            $sheet2->getStyle("E8:G{$row2}")->getNumberFormat()->setFormatCode($euroFormat);
        }

        $sheet2->getColumnDimension('A')->setWidth(10);
        $sheet2->getColumnDimension('B')->setWidth(18);
        $sheet2->getColumnDimension('C')->setWidth(12);
        $sheet2->getColumnDimension('D')->setWidth(13);
        $sheet2->getColumnDimension('E')->setWidth(24);
        $sheet2->getColumnDimension('F')->setWidth(18);
        $sheet2->getColumnDimension('G')->setWidth(18);
        $sheet2->getColumnDimension('H')->setWidth(16);
        $sheet2->getColumnDimension('I')->setWidth(30);
        $sheet2->getColumnDimension('J')->setWidth(15);
        $sheet2->getColumnDimension('K')->setWidth(16);

        // ─── Pestanya 3: Despeses ──────────────────────────────────
        $sheet3 = $spreadsheet->createSheet();
        $sheet3->setTitle('Despeses');

        // Fila 3: EMISSOR (merge sobre columnes I-P)
        $sheet3->mergeCells('I3:P3');
        $sheet3->setCellValue('I3', 'EMISSOR');
        $sheet3->getStyle('I3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Capçaleres fila 4
        $sheet3->setCellValue('A4', 'NÚM. REG.');
        $sheet3->setCellValue('B4', 'NÚMERO');
        $sheet3->setCellValue('C4', 'DATA');
        $sheet3->setCellValue('D4', 'CONCEPTE');
        $sheet3->setCellValue('E4', 'IMPORT');
        $sheet3->setCellValue('F4', '% IMPUTABLE');
        $sheet3->setCellValue('G4', 'TIPUS DESPESA');
        $sheet3->setCellValue('H4', 'DESC. TIPUS DESPESA');
        $sheet3->setCellValue('I4', 'NOM O RAÓ SOCIAL');
        $sheet3->setCellValue('J4', 'NIF');
        $sheet3->setCellValue('K4', 'ADREÇA');
        $sheet3->setCellValue('L4', 'CODI POSTAL');
        $sheet3->setCellValue('M4', 'POBLACIÓ');
        $sheet3->setCellValue('N4', 'PROVÍNCIA');
        $sheet3->setCellValue('O4', 'PAÍS');
        $sheet3->setCellValue('P4', 'TELÈFON');
        $sheet3->setCellValue('Q4', 'NOTES');
        $sheet3->getStyle('A4:Q4')->applyFromArray($headerStyle);

        $row = 5;
        $idx = 1;
        foreach ($despeses as $despesa) {
            $moviment    = $despesa->moviment;
            $proveidor   = $despesa->proveidor;
            $tipusFiscal = $despesa->tipusDespesaFiscal;

            $dataMoviment = $moviment?->data_moviment
                ? \Carbon\Carbon::parse($moviment->data_moviment)->format('d/m/Y')
                : '';
            $concepte = $despesa->concepte ?? $moviment?->concepte_original ?? '';
            $import   = $moviment ? abs((float) $moviment->import) : 0;

            $sheet3->setCellValue("A{$row}", $idx);
            $sheet3->setCellValue("B{$row}", '');
            $sheet3->setCellValue("C{$row}", $dataMoviment);
            $sheet3->setCellValue("D{$row}", $concepte);
            $sheet3->setCellValue("E{$row}", $import);
            $sheet3->setCellValue("F{$row}", 1);
            $sheet3->setCellValue("G{$row}", $tipusFiscal?->codi ?? '');
            $sheet3->setCellValue("H{$row}", $tipusFiscal?->descripcio ?? '');
            $sheet3->setCellValue("I{$row}", $proveidor?->nom_rao_social ?? '');
            $sheet3->setCellValue("J{$row}", $proveidor?->nif_cif ?? '');
            $sheet3->setCellValue("K{$row}", $proveidor?->adreca ?? '');
            $sheet3->setCellValue("L{$row}", $proveidor?->codi_postal ?? '');
            $sheet3->setCellValue("M{$row}", $proveidor?->poblacio ?? '');
            $sheet3->setCellValue("N{$row}", $proveidor?->provincia ?? '');
            $sheet3->setCellValue("O{$row}", $proveidor?->pais ?? '');
            $sheet3->setCellValue("P{$row}", $proveidor?->telefons ?? '');
            $sheet3->setCellValue("Q{$row}", $despesa->notes ?? '');

            $row++;
            $idx++;
        }

        // Fila totals
        $totalDespeses = $despeses->sum(function ($d) {
            return abs((float) ($d->moviment?->import ?? 0));
        });

        $sheet3->setCellValue("A{$row}", 'TOTALS');
        $sheet3->setCellValue("E{$row}", $totalDespeses);
        $sheet3->getStyle("A{$row}:Q{$row}")->applyFromArray($totalStyle);

        if ($row > 5) {
            $sheet3->getStyle("E5:E{$row}")->getNumberFormat()->setFormatCode($euroFormat);
        }

        $sheet3->getColumnDimension('A')->setWidth(10);
        $sheet3->getColumnDimension('B')->setWidth(10);
        $sheet3->getColumnDimension('C')->setWidth(12);
        $sheet3->getColumnDimension('D')->setWidth(35);
        $sheet3->getColumnDimension('E')->setWidth(14);
        $sheet3->getColumnDimension('F')->setWidth(13);
        $sheet3->getColumnDimension('G')->setWidth(13);
        $sheet3->getColumnDimension('H')->setWidth(35);
        $sheet3->getColumnDimension('I')->setWidth(30);
        $sheet3->getColumnDimension('J')->setWidth(15);
        $sheet3->getColumnDimension('K')->setWidth(30);
        $sheet3->getColumnDimension('L')->setWidth(14);
        $sheet3->getColumnDimension('M')->setWidth(20);
        $sheet3->getColumnDimension('N')->setWidth(20);
        $sheet3->getColumnDimension('O')->setWidth(15);
        $sheet3->getColumnDimension('P')->setWidth(15);
        $sheet3->getColumnDimension('Q')->setWidth(30);

        // ─── Pestanya 4: Llibre d'amortitzacions ───────────────────
        $sheet4 = $spreadsheet->createSheet();
        $sheet4->setTitle("Llibre d'amortitzacions");

        $sheet4->setCellValue('A1', "Empresa: {$nomEmpresa}");
        $sheet4->setCellValue('A2', "NIF: {$nifEmpresa}");
        $sheet4->setCellValue('A3', "Any {$any}");

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
