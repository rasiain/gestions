<?php

namespace App\Http\Services\DataProcess;

use Illuminate\Support\Facades\Log;

class FileAnalyzerService
{
    /**
     * Patterns to identify account numbers
     */
    private array $accountPatterns = [
        '/(?:compte|cuenta|account|ccc|iban)[:\s]*([A-Z]{2}[0-9]{2}[A-Z0-9]{4,}|[0-9]{10,24})/i',
        '/([A-Z]{2}[0-9]{2}\s?[0-9]{4}\s?[0-9]{4}\s?[0-9]{2}\s?[0-9]{10})/', // IBAN format
        '/([0-9]{4}[\s-]?[0-9]{4}[\s-]?[0-9]{2}[\s-]?[0-9]{10})/', // CCC format
    ];

    /**
     * Keywords that might indicate header information
     */
    private array $headerKeywords = [
        'compte', 'cuenta', 'account', 'entitat', 'entidad', 'entity',
        'banc', 'banco', 'bank', 'titular', 'holder', 'data', 'fecha',
        'date', 'periode', 'periodo', 'period'
    ];

    /**
     * Analyze file structure to auto-detect headers and account info
     *
     * @param array $rows
     * @return array
     */
    public function analyze(array $rows): array
    {
        $accountInfo = null;
        $headerInfo = [];
        $headerLineIndex = 0;
        $headers = [];
        $potentialHeaderRows = [];

        foreach ($rows as $rowIndex => $row) {
            if (empty($row)) {
                continue;
            }

            // Extract account info from row
            $foundAccount = $this->extractAccountInfo($row);
            if ($foundAccount !== null) {
                $accountInfo = $foundAccount;
            }

            // Extract header keywords from row
            $keywordInfo = $this->extractHeaderKeywords($row, $rowIndex);
            $headerInfo = array_merge($headerInfo, $keywordInfo);

            // Evaluate row as potential header
            $headerCandidate = $this->evaluateAsHeader($row);
            if ($headerCandidate !== null) {
                $potentialHeaderRows[$rowIndex] = $headerCandidate;
            }
        }

        // Find the best header row
        if (!empty($potentialHeaderRows)) {
            [$headerLineIndex, $headers] = $this->selectBestHeader($potentialHeaderRows);
        }

        // Clean up headers
        $headers = $this->cleanupHeaders($headers);

        // Filter header info to only include lines before data starts
        $headerInfo = array_filter($headerInfo, fn($info) => $info['line'] <= $headerLineIndex + 1);
        $headerInfo = array_values($headerInfo);

        Log::info('File structure analyzed', [
            'header_line_index' => $headerLineIndex,
            'num_headers' => count($headers),
            'account_found' => $accountInfo !== null,
            'header_info_count' => count($headerInfo)
        ]);

        return [
            'header_line_index' => $headerLineIndex,
            'headers' => $headers,
            'account_info' => $accountInfo,
            'header_info' => $headerInfo
        ];
    }

    /**
     * Convert Excel date serial numbers to proper date format
     *
     * @param mixed $value
     * @param string $header
     * @return mixed
     */
    public function convertExcelDate($value, string $header): mixed
    {
        if (!is_numeric($value) || $value <= 1000 || $value >= 100000) {
            return $value;
        }

        $dateHeaders = ['date', 'fecha', 'datum', 'data', 'transaction_date', 'payment_date'];
        $headerLower = strtolower(trim($header));

        foreach ($dateHeaders as $dateHeader) {
            if (str_contains($headerLower, $dateHeader)) {
                try {
                    $excelEpoch = new \DateTime('1900-01-01');
                    $daysToAdd = $value - 1;
                    $daysToAdd -= $daysToAdd > 59 ? 1 : 0;

                    $date = clone $excelEpoch;
                    $date->add(new \DateInterval("P{$daysToAdd}D"));

                    return $date->format('d/m/Y');
                } catch (\Exception $e) {
                    return $value;
                }
            }
        }

        return $value;
    }

    /**
     * Extract account info from a row
     *
     * @param array $row
     * @return string|null
     */
    private function extractAccountInfo(array $row): ?string
    {
        $rowText = implode(' ', array_map('strval', $row));

        foreach ($this->accountPatterns as $pattern) {
            if (preg_match($pattern, $rowText, $matches)) {
                return preg_replace('/\s+/', '', $matches[1]);
            }
        }

        return null;
    }

    /**
     * Extract header keywords from a row
     *
     * @param array $row
     * @param int $rowIndex
     * @return array
     */
    private function extractHeaderKeywords(array $row, int $rowIndex): array
    {
        $info = [];

        foreach ($row as $cell) {
            if ($cell === null) {
                continue;
            }

            $cellLower = strtolower(trim($cell));

            foreach ($this->headerKeywords as $keyword) {
                if (str_contains($cellLower, $keyword)) {
                    $info[] = [
                        'line' => $rowIndex + 1,
                        'content' => trim($cell)
                    ];
                    break;
                }
            }
        }

        return $info;
    }

    /**
     * Evaluate if a row could be a header row
     *
     * @param array $row
     * @return array|null
     */
    private function evaluateAsHeader(array $row): ?array
    {
        $nonEmptyCells = array_filter($row, fn($cell) => $cell !== null && trim($cell) !== '');
        $numCells = count($nonEmptyCells);

        if ($numCells < 3) {
            return null;
        }

        $textCells = 0;
        foreach ($nonEmptyCells as $cell) {
            if (!is_numeric($cell) && preg_match('/[a-zA-ZàáèéíòóúÀÁÈÉÍÒÓÚ]/u', $cell)) {
                $textCells++;
            }
        }

        if ($textCells < 2) {
            return null;
        }

        return [
            'num_cells' => $numCells,
            'text_cells' => $textCells,
            'row' => $row
        ];
    }

    /**
     * Select the best header row from candidates
     *
     * @param array $potentialHeaderRows
     * @return array [headerLineIndex, headers]
     */
    private function selectBestHeader(array $potentialHeaderRows): array
    {
        $bestScore = 0;
        $headerLineIndex = 0;
        $headers = [];

        foreach ($potentialHeaderRows as $rowIndex => $info) {
            $score = $info['text_cells'] * 10 + $info['num_cells'];

            if ($score > $bestScore) {
                $bestScore = $score;
                $headerLineIndex = $rowIndex;
                $headers = array_map(function ($cell) {
                    return $cell !== null ? trim($cell) : '';
                }, $info['row']);
            }
        }

        return [$headerLineIndex, $headers];
    }

    /**
     * Clean up headers - remove empty trailing cells
     *
     * @param array $headers
     * @return array
     */
    private function cleanupHeaders(array $headers): array
    {
        while (!empty($headers) && (end($headers) === '' || end($headers) === null)) {
            array_pop($headers);
        }

        return $headers;
    }
}
