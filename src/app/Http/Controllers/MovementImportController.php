<?php

namespace App\Http\Controllers;

use App\Http\Requests\MovementImportRequest;
use App\Http\Services\ImportFiles\FileParserService;
use App\Http\Services\ImportFiles\CaixaEnginyersParserService;
use App\Http\Services\ImportFiles\CaixaBankParserService;
use App\Http\Services\ImportFiles\KMyMoneyMovementParserService;
use App\Http\Services\MovementImportService;
use App\Models\CompteCorrent;
use App\Models\MovimentCompteCorrent;
use App\Services\SaldoRecalculationService;
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
        private MovementImportService $importService,
        private SaldoRecalculationService $saldoService
    ) {}

    /**
     * Show movement import page.
     */
    public function index(Request $request): Response
    {
        $comptesCorrents = CompteCorrent::orderBy('ordre')
            ->orderBy('entitat')
            ->get();

        return Inertia::render('Maintenance/MovementImport', [
            'comptesCorrents' => $comptesCorrents,
            'selectedCompteCorrentId' => $request->integer('compte_corrent_id') ?: null,
        ]);
    }

    /**
     * Parse uploaded file and return preview.
     */
    public function parse(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:xls,xlsx,csv,txt,qif,html|mimetypes:application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv,text/plain,text/html,application/octet-stream|max:102400',
            'compte_corrent_id' => 'required|integer|exists:g_comptes_corrents,id',
            'bank_type' => 'required|string|in:caixa_enginyers,caixabank,kmymoney',
        ]);

        try {
            $file = $request->file('file');
            $compteCorrentId = $validated['compte_corrent_id'];
            $bankType = $validated['bank_type'];

            // Determine import mode automatically based on existing movements
            $hasExistingMovements = MovimentCompteCorrent::where('compte_corrent_id', $compteCorrentId)->exists();
            $importMode = $hasExistingMovements ? 'from_last_db' : 'from_beginning';

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

            $result['preview_limited'] = false;
            $result['total_movements'] = count($result['movements']);

            return response()->json([
                'success' => true,
                'message' => 'Fitxer analitzat correctament',
                'data' => array_merge($result, [
                    'balance_warnings' => $result['balance_warnings'] ?? [],
                ]),
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

            // Determine import mode automatically based on existing movements
            $hasExistingMovements = MovimentCompteCorrent::where('compte_corrent_id', $compteCorrentId)->exists();
            $importMode = $hasExistingMovements ? 'from_last_db' : 'from_beginning';

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

            // Excloure els moviments marcats manualment per l'usuari
            $excludedHashes = $validated['excluded_hashes'] ?? [];
            if (!empty($excludedHashes)) {
                $result['movements'] = array_values(array_filter(
                    $result['movements'],
                    fn($m) => !in_array($m['hash'] ?? '', $excludedHashes, true)
                ));
                $result['to_import_count'] = count($result['movements']);
            }

            // Import to database
            $stats = $this->importService->import($result['movements'], $compteCorrentId);

            // Guardar el tipus d'importació usat
            CompteCorrent::where('id', $compteCorrentId)->update(['last_import_type' => $bankType]);

            // Recalcular saldos des del primer moviment importat
            if (!empty($result['movements'])) {
                $primeraData = collect($result['movements'])->min('data_moviment');
                if ($primeraData) {
                    $this->saldoService->recalcularDesde($compteCorrentId, $primeraData);
                }
            }

            $message = sprintf('S\'han importat %d moviments correctament', $stats['created']);
            if ($stats['skipped'] > 0) {
                $message .= sprintf(' (%d duplicats saltats)', $stats['skipped']);
            }

            // Validació del recompte: els processats han de coincidir amb els esperats del fitxer
            $countWarning = null;
            $toImportCount = $result['to_import_count'] ?? null;
            $processats = $stats['created'] + $stats['skipped'];
            if ($toImportCount !== null && $processats !== $toImportCount) {
                $countWarning = sprintf(
                    'El fitxer contenia %d moviments a importar, però se n\'han processat %d (%d creats, %d saltats).',
                    $toImportCount,
                    $processats,
                    $stats['created'],
                    $stats['skipped']
                );
                Log::warning('Movement import count mismatch', [
                    'compte_corrent_id' => $compteCorrentId,
                    'to_import_count'   => $toImportCount,
                    'processats'        => $processats,
                    'created'           => $stats['created'],
                    'skipped'           => $stats['skipped'],
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'stats'            => $stats,
                    'balance_warnings' => $result['balance_warnings'] ?? [],
                    'count_warning'    => $countWarning,
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
