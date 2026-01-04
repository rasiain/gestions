<?php

namespace App\Http\Services\ImportFiles;

use Illuminate\Support\Facades\Log;

class CaixaBankParserService extends AbstractMovementParserService
{
    /**
     * Parse CaixaBank XLS file.
     * Column structure: [0]=data operació, [1]=data valor (ignore), [2]=concepte, [3]=notes, [4]=import, [5]=saldo
     *
     * @param array $rows Parsed XLS rows
     * @param int $compteCorrentId
     * @return array
     */
    public function parse($rows, int $compteCorrentId): array
    {
        $movements = [];
        $headerFound = false;

        foreach ($rows as $index => $row) {
            // Skip empty rows
            if (empty(array_filter($row))) {
                continue;
            }

            // Detect header row
            if (!$headerFound) {
                if ($this->isHeaderRow($row)) {
                    $headerFound = true;
                }
                continue;
            }

            // Validate row has minimum required columns
            if (!$this->hasMinimumColumns($row, $index)) {
                continue;
            }

            // Parse columns
            $dataMoviment = trim($row[0] ?? '');
            $concepte = trim($row[2] ?? '');
            $notes = trim($row[3] ?? '');
            $importStr = trim($row[4] ?? '');
            $saldoStr = trim($row[5] ?? '');

            // Skip if essential fields are empty
            if (empty($dataMoviment) || empty($importStr)) {
                continue;
            }

            // Combine concept and notes
            $fullConcepte = $this->buildFullConcept($concepte, $notes);

            try {
                $movements[] = [
                    'data_moviment' => $this->normalizeDate($dataMoviment),
                    'concepte' => $this->trimConcept($fullConcepte),
                    'import' => $this->normalizeAmount($importStr),
                    'saldo_posterior' => !empty($saldoStr) ? $this->normalizeAmount($saldoStr) : null,
                    'notes' => !empty($notes) ? trim($notes) : null,
                    'categoria_path' => null,
                ];
            } catch (\Exception $e) {
                Log::error('CaixaBank: Error parsing row', [
                    'row_index' => $index,
                    'error' => $e->getMessage(),
                    'data' => $row,
                ]);
                continue;
            }
        }

        return $movements;
    }

    /**
     * Check if row is a header row.
     *
     * @param array $row
     * @return bool
     */
    private function isHeaderRow(array $row): bool
    {
        $firstCell = mb_strtoupper(trim($row[0] ?? ''), 'UTF-8');
        $thirdCell = mb_strtoupper(trim($row[2] ?? ''), 'UTF-8');

        return str_contains($firstCell, 'DATA')
            || str_contains($thirdCell, 'MOVIMENT')
            || str_contains($thirdCell, 'CONCEPTE');
    }

    /**
     * Validate row has minimum required columns.
     *
     * @param array $row
     * @param int $rowIndex
     * @return bool
     */
    private function hasMinimumColumns(array $row, int $rowIndex): bool
    {
        if (count($row) < 6) {
            Log::warning('CaixaBank: Row with insufficient columns', [
                'row_index' => $rowIndex,
                'columns' => count($row),
            ]);
            return false;
        }
        return true;
    }

    /**
     * Build full concept from concept and notes.
     *
     * @param string $concepte
     * @param string $notes
     * @return string
     */
    private function buildFullConcept(string $concepte, string $notes): string
    {
        if (!empty($notes) && !empty($concepte)) {
            return $concepte . ' - ' . $notes;
        }

        if (!empty($notes)) {
            return $notes;
        }

        return $concepte;
    }

    /**
     * Check if this parser supports the given bank type.
     *
     * @param string $bankType
     * @return bool
     */
    public function supports(string $bankType): bool
    {
        return $bankType === 'caixabank';
    }
}
