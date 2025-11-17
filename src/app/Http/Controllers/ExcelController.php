<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\HeadingRowImport;
use App\Http\Requests\ProcessExcelTransactionsRequest;
use Illuminate\Support\Facades\Log;

class ExcelController extends Controller
{
    /**
     * Handle Excel file upload and return transaction data as JSON
     *
     * @param ProcessExcelTransactionsRequest $request
     * @return JsonResponse
     */
    public function processTransactions(ProcessExcelTransactionsRequest $request): JsonResponse
    {
        try {
            // Get the uploaded file (validation is already handled by the form request)
            $file = $request->file('excel_file');

            // Get the number of header lines to skip (default to 1 if not specified)
            $headerLinesToSkip = $request->input('header_lines', 1);
            $format = $request->input('format', 'auto');

            // Read the file into an array depending on format
            if ($format === 'html') {
                // Use PhpSpreadsheet HTML reader for HTML-based "xls" files
                $htmlContent = file_get_contents($file->getRealPath());
                if ($htmlContent === false) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unable to read uploaded file'
                    ], 400);
                }
                // Parse simple HTML table into array using DOMDocument
                $transactions = [$this->parseHtmlTableToArray($htmlContent)];
            } else {
                $transactions = Excel::toArray([], $file);
            }

            if (empty($transactions) || empty($transactions[0])) {
                return response()->json([
                    'success' => false,
                    'message' => 'No data found in the Excel file'
                ], 400);
            }

            $allRows = $transactions[0];
            $totalRows = count($allRows);

            // Validate that we have enough rows after skipping header lines
            if ($totalRows <= $headerLinesToSkip) {
                return response()->json([
                    'success' => false,
                    'message' => "File has only {$totalRows} rows, but {$headerLinesToSkip} header lines were specified to skip"
                ], 400);
            }

            // Get headers from the row after skipping header lines
            $headers = $allRows[$headerLinesToSkip] ?? [];

            // Get data rows (skip header lines + 1 for the actual header row)
            $dataRows = array_slice($allRows, $headerLinesToSkip + 1);

            // Process data rows into structured format
            $processedTransactions = [];
            foreach ($dataRows as $rowIndex => $row) {
                if (empty(array_filter($row))) {
                    continue; // Skip empty rows
                }

                $transaction = [];
                foreach ($headers as $headerIndex => $header) {
                    $header = trim($header);
                    if (!empty($header)) {
                        $value = $row[$headerIndex] ?? null;

                        // Convert Excel date serial numbers to proper date format
                        $value = $this->convertExcelDate($value, $header);

                        $transaction[$header] = $value;
                    }
                }

                if (!empty($transaction)) {
                    $processedTransactions[] = $transaction;
                }
            }

            // Log only the count of processed transactions for audit purposes
            Log::info('Excel file processed', [
                'file_name' => $file->getClientOriginalName(),
                'total_rows' => $totalRows,
                'header_lines_skipped' => $headerLinesToSkip,
                'total_transactions' => count($processedTransactions),
                'file_size' => $file->getSize(),
                'processed_at' => now()->toISOString()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Excel file processed successfully',
                'data' => [
                    'file_info' => [
                        'total_rows' => $totalRows,
                        'header_lines_skipped' => $headerLinesToSkip,
                        'data_rows_processed' => count($dataRows)
                    ],
                    'total_transactions' => count($processedTransactions),
                    'headers' => $headers,
                    'transactions' => $processedTransactions
                ]
            ], 200);

        } catch (\Exception $e) {
            // Log error without exposing sensitive data
            Log::error('Excel processing error', [
                'error' => $e->getMessage(),
                'file' => $request->file('excel_file')?->getClientOriginalName(),
                'header_lines' => $request->input('header_lines', 1),
                'timestamp' => now()->toISOString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while processing the Excel file',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get supported file formats
     *
     * @return JsonResponse
     */
    public function getSupportedFormats(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'supported_formats' => [
                'xlsx' => 'Excel 2007+ (.xlsx)',
                'xls' => 'Excel 97-2003 (.xls)',
                'csv' => 'Comma Separated Values (.csv)'
            ],
            'max_file_size' => '10MB'
        ]);
    }

    /**
     * Convert Excel date serial numbers to proper date format
     *
     * @param mixed $value
     * @param string $header
     * @return mixed
     */
    private function convertExcelDate($value, string $header): mixed
    {
        // Check if the value looks like an Excel date serial number
        if (is_numeric($value) && $value > 1000 && $value < 100000) {
            // Common Excel date patterns in headers
            $dateHeaders = ['date', 'fecha', 'datum', 'data', 'fecha', 'date', 'transaction_date', 'payment_date'];
            $headerLower = strtolower(trim($header));

            // Check if this column header suggests it's a date
            foreach ($dateHeaders as $dateHeader) {
                if (str_contains($headerLower, $dateHeader)) {
                    try {
                        // Convert Excel serial number to PHP DateTime
                        // Excel dates start from January 1, 1900 (serial number 1)
                        // But Excel incorrectly treats 1900 as a leap year
                        // So we need to adjust for dates after February 28, 1900

                        $excelEpoch = new \DateTime('1900-01-01');
                        $daysToAdd = $value - 1; // Subtract 1 because Excel starts from 1, not 0

                        // Excel's leap year bug: it treats 1900 as a leap year when it's not
                        // This affects dates after February 28, 1900
                        $daysToAdd -= $daysToAdd > 59 ? 1 : 0;

                        // Add the days to the epoch date
                        $date = clone $excelEpoch;
                        $date->add(new \DateInterval("P{$daysToAdd}D"));

                        // Format as dd/mm/yyyy
                        return $date->format('d/m/Y');
                    } catch (\Exception $e) {
                        // If conversion fails, return original value
                        return $value;
                    }
                }
            }
        }

        return $value;
    }

    /**
     * Parse a simple HTML table into an array of rows (cells as strings)
     *
     * @param string $html
     * @return array<int, array<int, string|null>>
     */
    private function parseHtmlTableToArray(string $html): array
    {
        $rows = [];
        $doc = new \DOMDocument();
        // Suppress warnings for malformed HTML from bank exports
        libxml_use_internal_errors(true);
        $doc->loadHTML($html);
        libxml_clear_errors();
        $xpath = new \DOMXPath($doc);
        // Pick the first table in the document
        $table = $xpath->query('//table')->item(0);
        if (!$table) {
            return $rows;
        }
        foreach ($xpath->query('.//tr', $table) as $tr) {
            $row = [];
            foreach ($xpath->query('./th|./td', $tr) as $cell) {
                $text = trim($cell->textContent);
                $row[] = $text === '' ? null : $text;
            }
            // keep even if empty; higher-level code will skip fully empty rows later
            $rows[] = $row;
        }
        return $rows;
    }
}
