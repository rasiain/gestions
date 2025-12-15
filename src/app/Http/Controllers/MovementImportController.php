<?php

namespace App\Http\Controllers;

use App\Http\Requests\MovementImportRequest;
use App\Http\Services\ImportFiles\FileParserService;
use App\Http\Services\ImportFiles\CaixaEnginyersParserService;
use App\Http\Services\ImportFiles\CaixaBankParserService;
use App\Http\Services\ImportFiles\KMyMoneyMovementParserService;
use App\Http\Services\MovementImportService;
use App\Models\CompteCorrent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class MovementImportController extends Controller
{
    public function __construct(
        private FileParserService $fileParser,
        private CaixaEnginyersParserService $caixaEnginyersParser,
        private CaixaBankParserService $caixaBankParser,
        private KMyMoneyMovementParserService $kmymoneyParser,
        private MovementImportService $importService
    ) {}

    /**
     * Show movement import page.
     */
    public function index(): Response
    {
        $comptesCorrents = CompteCorrent::orderBy('ordre')
            ->orderBy('entitat')
            ->get();

        return Inertia::render('Maintenance/MovementImport', [
            'comptesCorrents' => $comptesCorrents,
        ]);
    }

    /**
     * Parse uploaded file and return preview.
     */
    public function parse(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:xls,xlsx,csv,txt,qif|mimetypes:application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv,text/plain,application/octet-stream|max:102400',
            'compte_corrent_id' => 'required|integer|exists:g_comptes_corrents,id',
            'bank_type' => 'required|string|in:caixa_enginyers,caixabank,kmymoney',
            'import_mode' => 'nullable|string|in:from_beginning,from_last_db',
        ]);

        try {
            $file = $request->file('file');
            $compteCorrentId = $validated['compte_corrent_id'];
            $bankType = $validated['bank_type'];
            $importMode = $validated['import_mode'] ?? null;

            // Parse file based on bank type
            if ($bankType === 'kmymoney') {
                // QIF format: read as string
                $content = file_get_contents($file->getRealPath());
                $parsedMovements = $this->kmymoneyParser->parse($content, $compteCorrentId);
            } else {
                // XLS/CSV format: parse to array
                $rows = $this->fileParser->parse($file);

                if ($bankType === 'caixa_enginyers') {
                    $parsedMovements = $this->caixaEnginyersParser->parse($rows, $compteCorrentId);
                } else { // caixabank
                    $parsedMovements = $this->caixaBankParser->parse($rows, $compteCorrentId);
                }
            }

            // Process movements through import service
            $result = $this->importService->processMovements($parsedMovements, $compteCorrentId, $importMode);

            // Log for debugging
            Log::info('After processMovements', [
                'to_import_count' => $result['to_import_count'],
                'movements_count' => count($result['movements']),
                'import_mode' => $importMode,
            ]);

            // Check for balance validation errors
            if (isset($result['balance_validation_failed']) && $result['balance_validation_failed']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validació: els saldos no coincideixen',
                    'data' => [
                        'errors' => $result['errors'],
                    ],
                ], 422);
            }

            // Limit movements in preview to avoid memory/timeout issues with large files
            // Show last 100 movements (most recent) for preview
            $totalMovements = count($result['movements']);
            $previewLimit = 100;

            if ($totalMovements > $previewLimit) {
                // Reverse to show most recent first, then take the limit
                $result['movements'] = array_slice(array_reverse($result['movements']), 0, $previewLimit);
                $result['preview_limited'] = true;
                $result['total_movements'] = $totalMovements;
            } else {
                // Reverse to show most recent first
                $result['movements'] = array_reverse($result['movements']);
                $result['preview_limited'] = false;
                $result['total_movements'] = $totalMovements;
            }

            return response()->json([
                'success' => true,
                'message' => 'Fitxer analitzat correctament',
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error('Error parsing movement file', [
                'error' => $e->getMessage(),
                'file' => $request->file('file')?->getClientOriginalName(),
                'bank_type' => $request->input('bank_type'),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error processant el fitxer',
                'error' => config('app.debug') ? $e->getMessage() : 'Error intern del servidor',
            ], 500);
        }
    }

    /**
     * Import movements to database.
     */
    public function import(MovementImportRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $file = $request->file('file');
            $compteCorrentId = $validated['compte_corrent_id'];
            $bankType = $validated['bank_type'];
            $importMode = $validated['import_mode'] ?? null;
            $editedMovements = $validated['edited_movements'] ?? [];

            // Parse file (same as parse endpoint)
            if ($bankType === 'kmymoney') {
                $content = file_get_contents($file->getRealPath());
                $parsedMovements = $this->kmymoneyParser->parse($content, $compteCorrentId);
            } else {
                $rows = $this->fileParser->parse($file);

                if ($bankType === 'caixa_enginyers') {
                    $parsedMovements = $this->caixaEnginyersParser->parse($rows, $compteCorrentId);
                } else {
                    $parsedMovements = $this->caixaBankParser->parse($rows, $compteCorrentId);
                }
            }

            // Process movements
            $result = $this->importService->processMovements($parsedMovements, $compteCorrentId, $importMode);

            // Check for balance validation errors
            if (isset($result['balance_validation_failed']) && $result['balance_validation_failed']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validació: els saldos no coincideixen',
                    'data' => [
                        'errors' => $result['errors'],
                    ],
                ], 422);
            }

            // Apply user edits to movements
            if (!empty($editedMovements)) {
                foreach ($editedMovements as $index => $edits) {
                    if (isset($result['movements'][$index])) {
                        if (isset($edits['data_moviment'])) {
                            $result['movements'][$index]['data_moviment'] = $edits['data_moviment'];
                        }
                        if (isset($edits['concepte'])) {
                            $result['movements'][$index]['concepte'] = $edits['concepte'];
                        }
                        if (isset($edits['categoria_id'])) {
                            $result['movements'][$index]['categoria_id'] = $edits['categoria_id'];
                        }
                    }
                }
            }

            // Import to database
            $stats = $this->importService->import($result['movements'], $compteCorrentId);

            $message = sprintf('S\'han importat %d moviments correctament', $stats['created']);
            if ($stats['skipped'] > 0) {
                $message .= sprintf(' (%d duplicats saltats)', $stats['skipped']);
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'stats' => $stats,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error importing movements', [
                'error' => $e->getMessage(),
                'file' => $request->file('file')?->getClientOriginalName(),
                'bank_type' => $request->input('bank_type'),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error important els moviments',
                'error' => config('app.debug') ? $e->getMessage() : 'Error intern del servidor',
            ], 500);
        }
    }
}
