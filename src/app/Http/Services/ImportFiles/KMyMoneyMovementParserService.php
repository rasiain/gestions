<?php

namespace App\Http\Services\ImportFiles;

use Illuminate\Support\Facades\Log;

class KMyMoneyMovementParserService extends AbstractMovementParserService
{
    /**
     * Parse KMyMoney QIF file.
     * Format:
     * D - Date (DD.MM.YYYY)
     * T - Amount (with comma decimal separator)
     * P - Payee/Concept (optional)
     * M - Memo/Notes (optional)
     * L - Category path (with : separator, ignore if [brackets])
     * C - Cleared status (IGNORE)
     * ^ - Transaction separator
     *
     * @param string $content Raw file content
     * @param int $compteCorrentId
     * @return array
     */
    public function parse($content, int $compteCorrentId): array
    {
        $movements = [];
        $lines = explode("\n", $content);
        $currentTransaction = [];

        foreach ($lines as $lineNumber => $line) {
            $line = trim($line);

            // Skip empty lines
            if (empty($line)) {
                continue;
            }

            // Skip !Type:Bank header
            if (str_starts_with($line, '!Type')) {
                continue;
            }

            // Transaction separator - process accumulated transaction
            if ($line === '^') {
                if (!empty($currentTransaction)) {
                    $movement = $this->processTransaction($currentTransaction, $lineNumber);
                    if ($movement !== null) {
                        $movements[] = $movement;
                    }
                }
                $currentTransaction = [];
                continue;
            }

            // Parse transaction fields
            $fieldType = substr($line, 0, 1);
            $fieldValue = substr($line, 1);

            switch ($fieldType) {
                case 'D': // Date
                    $currentTransaction['date'] = $fieldValue;
                    break;
                case 'T': // Amount
                    $currentTransaction['amount'] = $fieldValue;
                    break;
                case 'P': // Payee/Concept
                    $currentTransaction['payee'] = $fieldValue;
                    break;
                case 'M': // Memo/Notes
                    $currentTransaction['memo'] = $fieldValue;
                    break;
                case 'L': // Category
                    $currentTransaction['category'] = $fieldValue;
                    break;
                case 'C': // Cleared status - IGNORE per requirements
                    // Do nothing
                    break;
                default:
                    // Unknown field, log but continue
                    Log::debug('KMyMoney: Unknown QIF field', [
                        'line_number' => $lineNumber,
                        'field_type' => $fieldType,
                        'line' => $line,
                    ]);
                    break;
            }
        }

        // Process last transaction if file doesn't end with ^
        if (!empty($currentTransaction)) {
            $movement = $this->processTransaction($currentTransaction, count($lines));
            if ($movement !== null) {
                $movements[] = $movement;
            }
        }

        return $movements;
    }

    /**
     * Process a single transaction array into a movement.
     *
     * @param array $transaction
     * @param int $lineNumber For logging
     * @return array|null
     */
    private function processTransaction(array $transaction, int $lineNumber): ?array
    {
        // Validate required fields
        if (!isset($transaction['date']) || !isset($transaction['amount'])) {
            Log::warning('KMyMoney: Transaction missing required fields', [
                'line_number' => $lineNumber,
                'transaction' => $transaction,
            ]);
            return null;
        }

        // Skip Opening Balance with T=0 (technical records)
        $payee = $transaction['payee'] ?? '';
        $amount = $this->normalizeAmount($transaction['amount']);
        if (str_contains($payee, 'Opening Balance') && abs($amount) < 0.01) {
            Log::debug('KMyMoney: Skipping Opening Balance with zero amount', [
                'line_number' => $lineNumber,
            ]);
            return null;
        }

        // Process category
        $categoryPath = null;
        if (isset($transaction['category'])) {
            $category = trim($transaction['category']);

            // Ignore categories with [brackets] (account names, not categories)
            if (!str_starts_with($category, '[') && !str_ends_with($category, ']')) {
                $categoryPath = $category;
            }
        }

        // Build concept from payee and/or memo
        $concept = '';
        if (!empty($transaction['payee'])) {
            $concept = $transaction['payee'];
        }
        if (!empty($transaction['memo']) && empty($concept)) {
            $concept = $transaction['memo'];
        } elseif (!empty($transaction['memo'])) {
            // If both exist, payee is more important for concept
            $concept = $transaction['payee'];
        }

        if (empty($concept)) {
            $concept = 'MOVIMENT SENSE CONCEPTE';
        }

        try {
            return [
                'data_moviment' => $this->normalizeDate($transaction['date']),
                'concepte' => $this->trimConcept($concept),
                'import' => $amount,
                'saldo_posterior' => null, // QIF doesn't have balance
                'notes' => $transaction['memo'] ?? null,
                'categoria_path' => $categoryPath,
            ];
        } catch (\Exception $e) {
            Log::error('KMyMoney: Error processing transaction', [
                'line_number' => $lineNumber,
                'error' => $e->getMessage(),
                'transaction' => $transaction,
            ]);
            return null;
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
        return $bankType === 'kmymoney';
    }
}
