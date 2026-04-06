<?php

namespace App\Http\Services\ImportFiles;

use Illuminate\Support\Facades\Log;

class BbvaParserService extends AbstractMovementParserService
{
    /**
     * Parse BBVA XLSX file.
     * Column structure (0-based):
     *   [0]=buit, [1]=D. valor, [2]=Data, [3]=Concepte, [4]=Moviment, [5]=Import, [6]=Divisa, [7]=Disponible, [8]=Divisa, [9]=Observacions
     * Header row contains "D. valor" or "CONCEPTE".
     * Data starts from the row after the header.
     *
     * @param array $rows Parsed XLSX rows
     * @param int $compteCorrentId
     * @return array
     */
    public function parse($rows, int $compteCorrentId): array
    {
        $movements = [];
        $headerFound = false;

        foreach ($rows as $index => $row) {
            if (empty(array_filter($row))) {
                continue;
            }

            if (!$headerFound) {
                if ($this->isHeaderRow($row)) {
                    $headerFound = true;
                }
                continue;
            }

            if (!$this->hasMinimumColumns($row, $index)) {
                continue;
            }

            $dataMoviment = $row[2] ?? null;
            $concepteTipus = trim($row[3] ?? '');
            $moviment     = trim($row[4] ?? '');
            $import       = $row[5] ?? null;
            $disponible   = $row[7] ?? null;
            $observacions = trim($row[9] ?? '');

            if (empty($dataMoviment) || $import === null || $import === '') {
                continue;
            }

            // Normalitzar data (pot arribar com a string o com a float d'Excel)
            $dataStr = is_numeric($dataMoviment)
                ? $this->excelDateToString((float) $dataMoviment)
                : $this->normalizeDate((string) $dataMoviment);

            // Normalitzar import (pot arribar ja com a float o com a string)
            $importFloat = is_numeric($import)
                ? (float) $import
                : $this->normalizeAmount((string) $import);

            $saldoFloat = null;
            if ($disponible !== null && $disponible !== '') {
                $saldoFloat = is_numeric($disponible)
                    ? (float) $disponible
                    : $this->normalizeAmount((string) $disponible);
            }

            $fullConcepte = $this->buildFullConcept($concepteTipus, $moviment);

            try {
                $movements[] = [
                    'data_moviment'  => $dataStr,
                    'concepte'       => $this->trimConcept($fullConcepte),
                    'import'         => $importFloat,
                    'saldo_posterior' => $saldoFloat,
                    'notes'          => $observacions !== '' ? $observacions : null,
                    'categoria_path' => null,
                ];
            } catch (\Exception $e) {
                Log::error('BBVA: Error parsing row', [
                    'row_index' => $index,
                    'error'     => $e->getMessage(),
                    'data'      => $row,
                ]);
                continue;
            }
        }

        return $movements;
    }

    public function supports(string $bankType): bool
    {
        return $bankType === 'bbva';
    }

    private function isHeaderRow(array $row): bool
    {
        foreach ($row as $cell) {
            $cell = mb_strtoupper(trim((string) ($cell ?? '')), 'UTF-8');
            if (str_contains($cell, 'D. VALOR') || $cell === 'CONCEPTE' || $cell === 'IMPORT') {
                return true;
            }
        }
        return false;
    }

    private function hasMinimumColumns(array $row, int $rowIndex): bool
    {
        if (count($row) < 8) {
            Log::warning('BBVA: Row with insufficient columns', [
                'row_index' => $rowIndex,
                'columns'   => count($row),
            ]);
            return false;
        }
        return true;
    }

    private function buildFullConcept(string $tipus, string $moviment): string
    {
        if ($tipus !== '' && $moviment !== '') {
            return $tipus . ' - ' . $moviment;
        }
        return $moviment !== '' ? $moviment : $tipus;
    }

    /**
     * Convert Excel serial date (float) to Y-m-d string.
     */
    private function excelDateToString(float $serial): string
    {
        // Excel epoch: 1899-12-30 (with the Lotus 1-2-3 bug for 1900-02-29)
        $timestamp = ($serial - 25569) * 86400;
        return date('Y-m-d', (int) $timestamp);
    }
}
