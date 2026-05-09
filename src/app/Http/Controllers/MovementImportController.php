<?php

namespace App\Http\Controllers;

use App\Http\Requests\MovementImportRequest;
use App\Http\Services\ImportFiles\FileParserService;
use App\Http\Services\ImportFiles\BbvaParserService;
use App\Http\Services\ImportFiles\CaixaEnginyersParserService;
use App\Http\Services\ImportFiles\CaixaBankParserService;
use App\Http\Services\ImportFiles\KMyMoneyMovementParserService;
use App\Http\Services\MovementImportService;
use App\Models\CompteCorrent;
use App\Models\MovimentCompteCorrent;
use App\Services\SaldoRecalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class MovementImportController extends Controller
{
    public function __construct(
        private FileParserService $fileParser,
        private BbvaParserService $bbvaParser,
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
            'bank_type' => 'required|string|in:caixa_enginyers,caixabank,kmymoney,bbva',
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
                } elseif ($bankType === 'bbva') {
                    $parsedMovements = $this->bbvaParser->parse($rows, $compteCorrentId);
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
                } elseif ($bankType === 'bbva') {
                    $parsedMovements = $this->bbvaParser->parse($rows, $compteCorrentId);
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

    /**
     * Scan ~/Downloads for importable bank files.
     */
    public function scan(): JsonResponse
    {
        $dir = env('IMPORT_SCAN_PATH', getenv('HOME') . '/Downloads');
        $extensions = ['xls', 'xlsx', 'csv', 'qif'];
        $files = [];

        if (is_dir($dir)) {
            foreach (scandir($dir) as $file) {
                if ($file === '.' || $file === '..') continue;
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (in_array($ext, $extensions)) {
                    $fullPath = $dir . '/' . $file;
                    $files[] = [
                        'name' => $file,
                        'path' => $fullPath,
                        'size' => filesize($fullPath),
                        'modified' => date('Y-m-d H:i', filemtime($fullPath)),
                    ];
                }
            }
        }

        // Sort by modification time, newest first
        usort($files, fn ($a, $b) => strcmp($b['modified'], $a['modified']));

        return response()->json($files);
    }

    /**
     * Auto-detect bank type and account from a local file path, return preview.
     */
    public function autoPreview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file_path'        => 'required|string',
            'compte_corrent_id' => 'nullable|integer|exists:g_comptes_corrents,id',
        ]);

        $filePath = $validated['file_path'];

        if (!file_exists($filePath) || !is_readable($filePath)) {
            return response()->json([
                'success' => false,
                'message' => "No es pot llegir el fitxer: {$filePath}",
            ], 422);
        }

        try {
            $bankType = $this->detectBankType($filePath);
            if (!$bankType) {
                return response()->json([
                    'success' => false,
                    'message' => 'No s\'ha pogut detectar el tipus de banc del fitxer',
                ], 422);
            }

            if ($validated['compte_corrent_id'] ?? null) {
                $compte = CompteCorrent::find($validated['compte_corrent_id']);
            } else {
                $compte = $this->detectCompte($filePath, $bankType);
            }

            if (!$compte) {
                $comptes = CompteCorrent::orderBy('ordre')->get()->map(fn ($c) => [
                    'id' => $c->id, 'nom' => $c->nom, 'iban' => $c->compte_corrent, 'entitat' => $c->entitat,
                ]);
                return response()->json([
                    'success' => false,
                    'needs_compte_selection' => true,
                    'message' => 'No s\'ha pogut identificar el compte corrent automàticament',
                    'data' => ['bank_type' => $bankType, 'file_path' => $filePath, 'comptes_disponibles' => $comptes],
                ]);
            }

            $compteCorrentId = $compte->id;
            $hasExistingMovements = MovimentCompteCorrent::where('compte_corrent_id', $compteCorrentId)->exists();
            $importMode = $hasExistingMovements ? 'from_last_db' : 'from_beginning';

            $parsedMovements = $this->parseFileFromPath($filePath, $bankType, $compteCorrentId);
            $result = $this->importService->processMovements($parsedMovements, $compteCorrentId, $importMode);

            return response()->json([
                'success' => true,
                'data' => array_merge($result, [
                    'compte_corrent_id' => $compteCorrentId,
                    'compte_nom' => $compte->nom ?? $compte->entitat,
                    'compte_iban' => $compte->compte_corrent,
                    'bank_type' => $bankType,
                    'file_path' => $filePath,
                    'balance_warnings' => $result['balance_warnings'] ?? [],
                ]),
            ]);
        } catch (\Exception $e) {
            Log::error('Auto-preview error', ['error' => $e->getMessage(), 'file_path' => $filePath]);
            return response()->json([
                'success' => false,
                'message' => 'Error processant el fitxer',
                'error' => config('app.debug') ? $e->getMessage() : 'Error intern del servidor',
            ], 500);
        }
    }

    /**
     * Import from a local file path (auto-detected), then delete the file.
     */
    public function autoImport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file_path' => 'required|string',
            'compte_corrent_id' => 'required|integer|exists:g_comptes_corrents,id',
            'bank_type' => 'required|string|in:caixa_enginyers,caixabank,kmymoney,bbva',
            'excluded_hashes' => 'sometimes|array',
            'excluded_hashes.*' => 'string',
        ]);

        $filePath = $validated['file_path'];

        if (!file_exists($filePath) || !is_readable($filePath)) {
            return response()->json(['success' => false, 'message' => "No es pot llegir el fitxer: {$filePath}"], 422);
        }

        try {
            $compteCorrentId = $validated['compte_corrent_id'];
            $bankType = $validated['bank_type'];

            $hasExistingMovements = MovimentCompteCorrent::where('compte_corrent_id', $compteCorrentId)->exists();
            $importMode = $hasExistingMovements ? 'from_last_db' : 'from_beginning';

            $parsedMovements = $this->parseFileFromPath($filePath, $bankType, $compteCorrentId);
            $result = $this->importService->processMovements($parsedMovements, $compteCorrentId, $importMode);

            $excludedHashes = $validated['excluded_hashes'] ?? [];
            if (!empty($excludedHashes)) {
                $result['movements'] = array_values(array_filter(
                    $result['movements'],
                    fn ($m) => !in_array($m['hash'] ?? '', $excludedHashes, true)
                ));
            }

            $stats = $this->importService->import($result['movements'], $compteCorrentId);
            CompteCorrent::where('id', $compteCorrentId)->update(['last_import_type' => $bankType]);

            if (!empty($result['movements'])) {
                $primeraData = collect($result['movements'])->min('data_moviment');
                if ($primeraData) {
                    $this->saldoService->recalcularDesde($compteCorrentId, $primeraData);
                }
            }

            // Eliminar el fitxer després d'importació exitosa
            @unlink($filePath);

            return response()->json([
                'success' => true,
                'message' => sprintf('S\'han importat %d moviments correctament', $stats['created']),
                'data' => [
                    'stats' => $stats,
                    'balance_warnings' => $result['balance_warnings'] ?? [],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Auto-import error', ['error' => $e->getMessage(), 'file_path' => $filePath]);
            return response()->json([
                'success' => false,
                'message' => 'Error important els moviments',
                'error' => config('app.debug') ? $e->getMessage() : 'Error intern del servidor',
            ], 500);
        }
    }

    // ── Private helpers (shared with auto-* methods) ──

    private function detectBankType(string $filePath): ?string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($extension === 'qif') return 'kmymoney';

        $content = file_get_contents($filePath, false, null, 0, 32768);
        if (!$content) return null;

        if (str_starts_with($content, "PK\x03\x04") && $extension === 'xlsx') return 'bbva';
        if (str_starts_with($content, "\xD0\xCF\x11\xE0")) return 'caixabank';

        $upper = strtoupper($content);
        if (str_contains($upper, "DATA D'OPERACI") || str_contains($upper, 'DATA VALOR')) return 'caixa_enginyers';
        if (str_contains($upper, 'FECHA') || str_contains($upper, 'MOVIMIENTO')) return 'caixabank';

        return null;
    }

    private function detectCompte(string $filePath, string $bankType): ?CompteCorrent
    {
        // 1. Cerca IBAN com a text pla (funciona per CSV/XLSX descomprimit)
        $content = file_get_contents($filePath, false, null, 0, 8192);
        if ($content && preg_match('/ES\d{2}[\s]?\d{4}[\s]?\d{4}[\s]?\d{4}[\s]?\d{4}[\s]?\d{4}/', $content, $matches)) {
            $iban = preg_replace('/\s/', '', $matches[0]);
            $compte = CompteCorrent::where('compte_corrent', $iban)->first();
            if ($compte) return $compte;
        }

        // 2. Cerca per seqüència numèrica al nom del fitxer (ex: Moviments_compte_0586930.xls)
        $filename = pathinfo($filePath, PATHINFO_FILENAME);
        if (preg_match('/\d{5,}/', $filename, $matches)) {
            $partial = $matches[0];
            $compte = CompteCorrent::where('compte_corrent', 'LIKE', "%{$partial}%")->first();
            if ($compte) return $compte;
        }

        // 3. Si només hi ha un compte del tipus detectat, l'usem
        $comptes = CompteCorrent::all()->filter(fn ($c) => $c->bank_type === $bankType);
        if ($comptes->count() === 1) return $comptes->first();

        return null;
    }

    private function parseFileFromPath(string $filePath, string $bankType, int $compteCorrentId): array
    {
        if ($bankType === 'kmymoney') {
            $content = file_get_contents($filePath);
            return $this->kmymoneyParser->parse($content, $compteCorrentId);
        }

        $file = new UploadedFile($filePath, basename($filePath), mime_content_type($filePath), null, true);
        $rows = $this->fileParser->parse($file);

        return match ($bankType) {
            'caixa_enginyers' => $this->caixaEnginyersParser->parse($rows, $compteCorrentId),
            'bbva' => $this->bbvaParser->parse($rows, $compteCorrentId),
            'caixabank' => $this->caixaBankParser->parse($rows, $compteCorrentId),
        };
    }
}
