<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use App\Http\Requests\ProcessDataFileRequest;
use App\Http\Services\DataProcess\FileParserService;
use App\Http\Services\DataProcess\FileAnalyzerService;
use Illuminate\Support\Facades\Log;

class DataFileController extends Controller
{
    public function __construct(
        private FileParserService $fileParser,
        private FileAnalyzerService $fileAnalyzer
    ) {
    }
    /**
     * Process data file upload and return data as JSON
     *
     * @param ProcessDataFileRequest $request
     * @return JsonResponse
     */
    public function process(ProcessDataFileRequest $request): JsonResponse
    {
        try {
            $file = $request->file('excel_file');
            $format = $request->input('format', 'auto');

            // Parse file into rows
            $allRows = $this->fileParser->parse($file, $format);

            if (empty($allRows)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No s\'han trobat dades al fitxer'
                ], 400);
            }

            $totalRows = count($allRows);

            // Auto-detect file structure
            $analysis = $this->fileAnalyzer->analyze($allRows);

            $headerLineIndex = $analysis['header_line_index'];
            $headers = $analysis['headers'];
            $accountInfo = $analysis['account_info'];
            $headerInfo = $analysis['header_info'];

            // Get data rows (after the header row)
            $dataRows = array_slice($allRows, $headerLineIndex + 1);

            // Process data rows into structured format
            $processedRecords = $this->processDataRows($dataRows, $headers);

            // Log only the count of processed records for audit purposes
            Log::info('Data file processed', [
                'file_name' => $file->getClientOriginalName(),
                'total_rows' => $totalRows,
                'header_line_index' => $headerLineIndex,
                'total_records' => count($processedRecords),
                'file_size' => $file->getSize(),
                'processed_at' => now()->toISOString()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Fitxer processat correctament',
                'data' => [
                    'file_info' => [
                        'total_rows' => $totalRows,
                        'header_line_index' => $headerLineIndex,
                        'num_columns' => count($headers),
                        'data_rows_processed' => count($dataRows)
                    ],
                    'account_info' => $accountInfo,
                    'header_info' => $headerInfo,
                    'total_transactions' => count($processedRecords),
                    'headers' => $headers,
                    'transactions' => $processedRecords
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Data file processing error', [
                'error' => $e->getMessage(),
                'file' => $request->file('excel_file')?->getClientOriginalName(),
                'timestamp' => now()->toISOString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Hi ha hagut un error processant el fitxer',
                'error' => config('app.debug') ? $e->getMessage() : 'Error intern del servidor'
            ], 500);
        }
    }

    /**
     * Process data rows into structured format
     *
     * @param array $dataRows
     * @param array $headers
     * @return array
     */
    private function processDataRows(array $dataRows, array $headers): array
    {
        $processedRecords = [];

        foreach ($dataRows as $row) {
            if (empty(array_filter($row))) {
                continue;
            }

            $record = [];
            foreach ($headers as $headerIndex => $header) {
                $header = trim($header);
                if (!empty($header)) {
                    $value = $row[$headerIndex] ?? null;
                    $value = $this->fileAnalyzer->convertExcelDate($value, $header);
                    $record[$header] = $value;
                }
            }

            if (!empty($record)) {
                $processedRecords[] = $record;
            }
        }

        return $processedRecords;
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
                'csv' => 'Valors separats per comes (.csv)'
            ],
            'max_file_size' => '10MB'
        ]);
    }
}
