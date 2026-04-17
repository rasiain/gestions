<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Services\ImportFiles\BbvaParserService;
use App\Http\Services\ImportFiles\CaixaBankParserService;
use App\Http\Services\ImportFiles\CaixaEnginyersParserService;
use App\Http\Services\ImportFiles\FileParserService;
use App\Http\Services\ImportFiles\KMyMoneyMovementParserService;
use App\Http\Services\MovementImportService;
use App\Models\CompteCorrent;
use App\Models\MovimentCompteCorrent;
use App\Services\SaldoRecalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class ImportController extends Controller
{
    public function __construct(
        private FileParserService $fileParser,
        private BbvaParserService $bbvaParser,
        private CaixaEnginyersParserService $caixaEnginyersParser,
        private CaixaBankParserService $caixaBankParser,
        private KMyMoneyMovementParserService $kmymoneyParser,
        private MovementImportService $importService,
        private SaldoRecalculationService $saldoService,
    ) {}

    public function parse(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file_path' => 'required|string',
            'compte_corrent_id' => 'required|integer|exists:g_comptes_corrents,id',
            'bank_type' => 'required|string|in:caixa_enginyers,caixabank,kmymoney,bbva',
        ]);

        $filePath = $validated['file_path'];

        if (!file_exists($filePath) || !is_readable($filePath)) {
            return response()->json([
                'success' => false,
                'message' => "No es pot llegir el fitxer: {$filePath}",
            ], 422);
        }

        try {
            $compteCorrentId = $validated['compte_corrent_id'];
            $bankType = $validated['bank_type'];

            $hasExistingMovements = MovimentCompteCorrent::where('compte_corrent_id', $compteCorrentId)->exists();
            $importMode = $hasExistingMovements ? 'from_last_db' : 'from_beginning';

            $parsedMovements = $this->parseFile($filePath, $bankType, $compteCorrentId);

            $result = $this->importService->processMovements($parsedMovements, $compteCorrentId, $importMode);

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
            Log::error('API: Error parsing movement file', [
                'error' => $e->getMessage(),
                'file_path' => $filePath,
                'bank_type' => $validated['bank_type'],
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error processant el fitxer',
                'error' => config('app.debug') ? $e->getMessage() : 'Error intern del servidor',
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
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
            return response()->json([
                'success' => false,
                'message' => "No es pot llegir el fitxer: {$filePath}",
            ], 422);
        }

        try {
            $compteCorrentId = $validated['compte_corrent_id'];
            $bankType = $validated['bank_type'];

            $hasExistingMovements = MovimentCompteCorrent::where('compte_corrent_id', $compteCorrentId)->exists();
            $importMode = $hasExistingMovements ? 'from_last_db' : 'from_beginning';

            $parsedMovements = $this->parseFile($filePath, $bankType, $compteCorrentId);
            $result = $this->importService->processMovements($parsedMovements, $compteCorrentId, $importMode);

            $excludedHashes = $validated['excluded_hashes'] ?? [];
            if (!empty($excludedHashes)) {
                $result['movements'] = array_values(array_filter(
                    $result['movements'],
                    fn ($m) => !in_array($m['hash'] ?? '', $excludedHashes, true)
                ));
                $result['to_import_count'] = count($result['movements']);
            }

            $stats = $this->importService->import($result['movements'], $compteCorrentId);

            CompteCorrent::where('id', $compteCorrentId)->update(['last_import_type' => $bankType]);

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

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'stats' => $stats,
                    'balance_warnings' => $result['balance_warnings'] ?? [],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('API: Error importing movements', [
                'error' => $e->getMessage(),
                'file_path' => $filePath,
                'bank_type' => $validated['bank_type'],
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error important els moviments',
                'error' => config('app.debug') ? $e->getMessage() : 'Error intern del servidor',
            ], 500);
        }
    }

    /**
     * Auto-detect account and bank type from file content, then parse.
     * Single endpoint that replaces: read file + list accounts + match + parse.
     */
    public function autoParse(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file_path' => 'required|string',
        ]);

        $filePath = $validated['file_path'];

        if (!file_exists($filePath) || !is_readable($filePath)) {
            return response()->json([
                'success' => false,
                'message' => "No es pot llegir el fitxer: {$filePath}",
            ], 422);
        }

        try {
            // Step 1: Detect bank type from file content
            $bankType = $this->detectBankType($filePath);
            if (!$bankType) {
                return response()->json([
                    'success' => false,
                    'message' => 'No s\'ha pogut detectar el tipus de banc del fitxer',
                ], 422);
            }

            // Step 2: Detect account from file content (IBAN or other signals)
            $compte = $this->detectCompte($filePath, $bankType);
            if (!$compte) {
                $comptes = CompteCorrent::orderBy('ordre')->get()->map(fn ($c) => [
                    'id' => $c->id, 'nom' => $c->nom, 'iban' => $c->compte_corrent, 'bank_type' => $c->bank_type,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'No s\'ha pogut identificar el compte corrent automàticament',
                    'data' => ['bank_type_detected' => $bankType, 'comptes_disponibles' => $comptes],
                ], 422);
            }

            $compteCorrentId = $compte->id;

            // Step 3: Parse
            $hasExistingMovements = MovimentCompteCorrent::where('compte_corrent_id', $compteCorrentId)->exists();
            $importMode = $hasExistingMovements ? 'from_last_db' : 'from_beginning';

            $parsedMovements = $this->parseFile($filePath, $bankType, $compteCorrentId);
            $result = $this->importService->processMovements($parsedMovements, $compteCorrentId, $importMode);

            $result['preview_limited'] = false;
            $result['total_movements'] = count($result['movements']);

            return response()->json([
                'success' => true,
                'message' => 'Fitxer analitzat correctament',
                'data' => array_merge($result, [
                    'compte_corrent_id' => $compteCorrentId,
                    'compte_nom' => $compte->nom,
                    'compte_iban' => $compte->compte_corrent,
                    'bank_type' => $bankType,
                    'balance_warnings' => $result['balance_warnings'] ?? [],
                ]),
            ]);
        } catch (\Exception $e) {
            Log::error('API: Error in auto-parse', [
                'error' => $e->getMessage(),
                'file_path' => $filePath,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error processant el fitxer',
                'error' => config('app.debug') ? $e->getMessage() : 'Error intern del servidor',
            ], 500);
        }
    }

    private function detectBankType(string $filePath): ?string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if ($extension === 'qif') {
            return 'kmymoney';
        }

        // Read enough bytes to find table headers in HTML-XLS files (CSS can exceed 4KB)
        $content = file_get_contents($filePath, false, null, 0, 32768);
        if (!$content) {
            return null;
        }

        // BBVA XLSX files are ZIP archives with specific patterns
        if (str_starts_with($content, "PK\x03\x04")) {
            if ($extension === 'xlsx') {
                return 'bbva';
            }
        }

        // OLE2 binary XLS (CaixaBank uses this format)
        if (str_starts_with($content, "\xD0\xCF\x11\xE0")) {
            return 'caixabank';
        }

        // HTML-based XLS (Caixa Enginyers)
        $upper = strtoupper($content);
        if (str_contains($upper, "DATA D'OPERACI") || str_contains($upper, 'DATA VALOR')) {
            return 'caixa_enginyers';
        }
        if (str_contains($upper, 'FECHA') || str_contains($upper, 'MOVIMIENTO')) {
            return 'caixabank';
        }

        return null;
    }

    private function detectCompte(string $filePath, string $bankType): ?CompteCorrent
    {
        $content = file_get_contents($filePath, false, null, 0, 8192);

        // Search for IBAN pattern (ES + 22 digits, with optional spaces)
        if ($content && preg_match('/ES\d{2}[\s]?\d{4}[\s]?\d{4}[\s]?\d{4}[\s]?\d{4}[\s]?\d{4}/', $content, $matches)) {
            $iban = preg_replace('/\s/', '', $matches[0]);
            $compte = CompteCorrent::where('compte_corrent', $iban)->first();
            if ($compte) {
                return $compte;
            }
        }

        // Fallback: find account by bank_type
        $comptes = CompteCorrent::all()->filter(fn ($c) => $c->bank_type === $bankType);
        if ($comptes->count() === 1) {
            return $comptes->first();
        }

        return null;
    }

    private function parseFile(string $filePath, string $bankType, int $compteCorrentId): array
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
