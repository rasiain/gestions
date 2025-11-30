<?php

namespace App\Http\Services\ImportFiles;

use Illuminate\Support\Facades\Log;

class CaixaEnginyersParserService extends AbstractMovementParserService
{
    /**
     * Parse Caixa d'Enginyers XLS file.
     * Column structure: [0]=data operació, [1]=concepte, [2]=data valor (ignore), [3]=import, [4]=saldo
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
            if (!$headerFound) {
                $firstCell = mb_strtoupper(trim($row[0] ?? ''), 'UTF-8');
                if (str_contains($firstCell, 'DATA') || str_contains($firstCell, 'MOVIMENT')) {
                    $headerFound = true;
                }
                continue;
            }

            // Validate row has at least 5 columns
            if (count($row) < 5) {
                Log::warning('CaixaEnginyers: Row with insufficient columns', [
                    'row_index' => $index,
                    'columns' => count($row),
                ]);
                continue;
            }

            // Parse columns
            $dataMoviment = trim($row[0] ?? '');
            $concepte = trim($row[1] ?? '');
            $importStr = trim($row[3] ?? '');
            $saldoStr = trim($row[4] ?? '');

            // Skip if essential fields are empty
            if (empty($dataMoviment) || empty($importStr)) {
                continue;
            }

            try {
                $movements[] = [
                    'data_moviment' => $this->normalizeDate($dataMoviment),
                    'concepte' => $this->trimConcept($concepte),
                    'import' => $this->normalizeAmount($importStr),
                    'saldo_posterior' => !empty($saldoStr) ? $this->normalizeAmount($saldoStr) : null,
                    'notes' => null,
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
