<?php

namespace App\Http\Services\ImportFiles;

use Illuminate\Support\Facades\Log;

class CaixaEnginyersParserService extends AbstractMovementParserService
{
    /**
     * Parse Caixa d'Enginyers XLS file.
     * Column structure: [0]=null, [1]=data operació, [2]=concepte, [3]=data valor (ignore), [4]=import, [5]=saldo
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

            // Detect header row (contains "Data" or "Moviment" or similar)
            // Check index 1 since index 0 is always null
            if (!$headerFound) {
                $secondCell = mb_strtoupper(trim($row[1] ?? ''), 'UTF-8');
                if (str_contains($secondCell, 'DATA') || str_contains($secondCell, 'OPERACIÓ')) {
                    $headerFound = true;
                }
                continue;
            }

            // Validate row has at least 6 columns (index 0-5)
            if (count($row) < 6) {
                Log::warning('CaixaEnginyers: Row with insufficient columns', [
                    'row_index' => $index,
                    'columns' => count($row),
                ]);
                continue;
            }

            // Parse columns (shifted by 1 due to null at index 0)
            $dataMoviment = trim($row[1] ?? '');
            $concepte = trim($row[2] ?? '');
            $importStr = trim($row[4] ?? '');
            $saldoStr = trim($row[5] ?? '');

            // Skip if essential fields are empty
            if (empty($dataMoviment) || empty($importStr)) {
                continue;
            }

            // Validate saldo
            if (!$this->isValidSaldo($saldoStr, $index)) {
                continue;
            }

            try {
                $trimmedConcept = $this->trimConcept($concepte);
                $movements[] = [
                    'data_moviment' => $this->normalizeDate($dataMoviment),
                    'concepte' => $trimmedConcept,
                    'import' => $this->normalizeAmount($importStr),
                    'saldo_posterior' => !empty($saldoStr) ? $this->normalizeAmount($saldoStr) : null,
                    'notes' => $trimmedConcept, // Default notes to concept
                    'categoria_path' => null,
                ];
            } catch (\Exception $e) {
                Log::error('CaixaEnginyers: Error parsing row', [
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
     * Validate that saldo is a valid positive number.
     *
     * @param string $saldoStr
     * @param int $rowIndex
     * @return bool
     */
    private function isValidSaldo(string $saldoStr, int $rowIndex): bool
    {
        // Empty saldo is valid (optional field)
        if (empty($saldoStr)) {
            return true;
        }

        if (!$this->conteNumeros($saldoStr)){
            return false;
        }

        try {
            $saldoValue = $this->normalizeAmount($saldoStr);
            if ($saldoValue < 0) {
                Log::warning('CaixaEnginyers: Invalid saldo (negative)', [
                    'row_index' => $rowIndex,
                    'saldo' => $saldoStr,
                ]);
                return false;
            }
            return true;
        } catch (\Exception $e) {
            Log::warning('CaixaEnginyers: Invalid saldo format', [
                'row_index' => $rowIndex,
                'saldo' => $saldoStr,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Check if this parser supports the given bank type.
     *
     * @param string $bankType
     * @return bool
     */
    public function supports(string $bankType): bool
    {
        return $bankType === 'caixa_enginyers';
    }
}
