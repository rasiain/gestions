<?php

namespace App\Http\Services;

use App\Models\MovimentCompteCorrent;
use App\Models\Categoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MovementImportService
{
    /**
     * Cache for category path lookups during import.
     */
    private array $categoryCache = [];

    /**
     * Preloaded categories for the current compte corrent.
     */
    private array $preloadedCategories = [];

    /**
     * Process parsed movements: generate hashes, detect duplicates, calculate balances, match categories.
     *
     * @param array $parsedMovements
     * @param int $compteCorrentId
     * @param string|null $importMode 'from_beginning' or 'from_last_db'
     * @return array
     */
    public function processMovements(array $parsedMovements, int $compteCorrentId, ?string $importMode = null): array
    {
        // Step 1: Generate hashes for all movements
        foreach ($parsedMovements as &$movement) {
            $movement['hash'] = MovimentCompteCorrent::generateHash(
                $movement['data_moviment'],
                $movement['concepte'],
                $movement['import'],
                $compteCorrentId
            );
        }
        unset($movement); // Break reference

        // Step 2: Find last matching movement by hash
        $lastMatch = $this->findLastMovementIndex($parsedMovements, $compteCorrentId);

        // Step 3: Determine import range
        $result = $this->filterMovementsToImport($parsedMovements, $lastMatch, $compteCorrentId, $importMode);

        // Step 4: Validate balances if file has balance info
        if (!empty($result['movements'])) {
            $hasBalance = $result['movements'][0]['saldo_posterior'] !== null;
            if ($hasBalance) {
                $balanceErrors = $this->validateBalances($result['movements'], $compteCorrentId);
                if (!empty($balanceErrors)) {
                    return [
                        'movements' => [],
                        'last_hash_found' => $lastMatch['found'],
                        'last_db_movement' => $lastMatch['movement'],
                        'duplicates_skipped' => 0,
                        'to_import_count' => 0,
                        'warnings' => [],
                        'errors' => $balanceErrors,
                        'balance_validation_failed' => true,
                    ];
                }
            } else {
                // No balance in file: calculate balances
                $result['movements'] = $this->calculateBalances($result['movements'], $compteCorrentId);
            }
        }

        // Step 5: Match categories (for QIF with category paths)
        // Preload all categories for this compte corrent to avoid repeated DB queries
        $this->preloadCategories($compteCorrentId);

        foreach ($result['movements'] as &$movement) {
            if (isset($movement['categoria_path']) && $movement['categoria_path']) {
                $movement['categoria_id'] = $this->matchCategoryPath(
                    $movement['categoria_path'],
                    $compteCorrentId,
                    $movement['import']
                );
            } else {
                $movement['categoria_id'] = null;
            }
        }
        unset($movement); // Break reference

        return $result;
    }

    /**
     * Find the index of the last movement in the file that exists in DB.
     *
     * @param array $movements
     * @param int $compteCorrentId
     * @return array ['index' => int, 'movement' => Model|null, 'found' => bool]
     */
    private function findLastMovementIndex(array $movements, int $compteCorrentId): array
    {
        // Extract all hashes from movements
        $fileHashes = array_column($movements, 'hash');

        // Get all matching movements from DB
        // For large files, split into chunks to avoid SQL limits
        $dbMovements = collect();
        $chunks = array_chunk($fileHashes, 1000);

        foreach ($chunks as $chunk) {
            $results = MovimentCompteCorrent::where('compte_corrent_id', $compteCorrentId)
                ->whereIn('hash', $chunk)
                ->get();

            $dbMovements = $dbMovements->merge($results);
        }

        // Index by hash for fast lookup
        $dbMovements = $dbMovements->keyBy('hash');

        // Search from end to beginning for the last match
        for ($i = count($movements) - 1; $i >= 0; $i--) {
            $hash = $movements[$i]['hash'];

            if (isset($dbMovements[$hash])) {
                return [
                    'index' => $i,
                    'movement' => $dbMovements[$hash],
                    'found' => true,
                ];
            }
        }

        return [
            'index' => -1,
            'movement' => null,
            'found' => false,
        ];
    }

    /**
     * Filter movements to determine which ones to import.
     *
     * @param array $movements
     * @param array $lastMatch Result from findLastMovementIndex
     * @param int $compteCorrentId
     * @param string|null $importMode
     * @return array
     */
    private function filterMovementsToImport(array $movements, array $lastMatch, int $compteCorrentId, ?string $importMode): array
    {
        if ($lastMatch['found']) {
            // Import only movements after the last match
            $toImport = array_slice($movements, $lastMatch['index'] + 1);

            return [
                'movements' => array_values($toImport),
                'last_hash_found' => true,
                'last_db_movement' => $lastMatch['movement'],
                'duplicates_skipped' => $lastMatch['index'] + 1,
                'to_import_count' => count($toImport),
                'warnings' => [],
            ];
        }

        // No match found - require user decision
        if (!$importMode) {
            return [
                'movements' => [],
                'last_hash_found' => false,
                'last_db_movement' => null,
                'duplicates_skipped' => 0,
                'to_import_count' => 0,
                'warnings' => [
                    'No s\'ha trobat cap moviment coincident a la base de dades.',
                    'Els moviments s\'afegiran a continuació de l\'últim registre sense poder verificar la coherència.',
                    'Comprova que les dates i imports siguin correctes abans d\'importar.',
                ],
                'requires_import_mode_selection' => true,
            ];
        }

        if ($importMode === 'from_beginning') {
            return [
                'movements' => $movements,
                'last_hash_found' => false,
                'last_db_movement' => null,
                'duplicates_skipped' => 0,
                'to_import_count' => count($movements),
                'warnings' => ['Importació des del principi del fitxer.'],
            ];
        }

        if ($importMode === 'from_last_db') {
            // Get last movement from DB by date
            $lastDbMovement = MovimentCompteCorrent::where('compte_corrent_id', $compteCorrentId)
                ->orderBy('data_moviment', 'desc')
                ->orderBy('id', 'desc')
                ->first();

            if (!$lastDbMovement) {
                // No movements in DB, import all
                return [
                    'movements' => $movements,
                    'last_hash_found' => false,
                    'last_db_movement' => null,
                    'duplicates_skipped' => 0,
                    'to_import_count' => count($movements),
                    'warnings' => ['Cap moviment a la BD. S\'importaran tots els moviments.'],
                ];
            }

            // Filter movements after last DB date
            $toImport = array_filter($movements, function ($mov) use ($lastDbMovement) {
                return $mov['data_moviment'] > $lastDbMovement->data_moviment->format('Y-m-d');
            });

            return [
                'movements' => array_values($toImport),
                'last_hash_found' => false,
                'last_db_movement' => $lastDbMovement,
                'duplicates_skipped' => count($movements) - count($toImport),
                'to_import_count' => count($toImport),
                'warnings' => [
                    'Importació des de l\'última data a la BD: ' .
                    $lastDbMovement->data_moviment->format('d/m/Y'),
                ],
            ];
        }

        return [
            'movements' => [],
            'warnings' => ['Mode d\'importació no vàlid.'],
        ];
    }

    /**
     * Validate that calculated balances match file balances.
     * Returns array of errors, empty if all valid.
     *
     * @param array $movements
     * @param int $compteCorrentId
     * @return array
     */
    private function validateBalances(array $movements, int $compteCorrentId): array
    {
        $errors = [];

        // Get last balance from DB
        $lastMovement = MovimentCompteCorrent::where('compte_corrent_id', $compteCorrentId)
            ->whereNotNull('saldo_posterior')
            ->orderBy('data_moviment', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        $previousBalance = $lastMovement?->saldo_posterior ?? 0;

        foreach ($movements as $index => $movement) {
            $expectedBalance = $previousBalance + $movement['import'];
            $fileBalance = $movement['saldo_posterior'];

            // Tolerance of 1 cent for rounding
            if (abs($expectedBalance - $fileBalance) > 0.01) {
                $errors[] = sprintf(
                    'Moviment %d (%s - %s): Saldo esperat %.2f€, fitxer indica %.2f€',
                    $index + 1,
                    $movement['data_moviment'],
                    $movement['concepte'],
                    $expectedBalance,
                    $fileBalance
                );
            }

            $previousBalance = $fileBalance; // Use file balance as next previous balance
        }

        return $errors;
    }

    /**
     * Calculate balances for movements that don't have balance info (QIF).
     *
     * @param array $movements
     * @param int $compteCorrentId
     * @return array
     */
    private function calculateBalances(array $movements, int $compteCorrentId): array
    {
        // Get last balance from DB
        $lastMovement = MovimentCompteCorrent::where('compte_corrent_id', $compteCorrentId)
            ->whereNotNull('saldo_posterior')
            ->orderBy('data_moviment', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        $currentBalance = $lastMovement?->saldo_posterior ?? 0;

        foreach ($movements as &$movement) {
            $currentBalance += $movement['import'];
            $movement['saldo_posterior'] = $currentBalance;
        }
        unset($movement); // Break reference

        return $movements;
    }

    /**
     * Preload all categories for a compte corrent into memory.
     *
     * @param int $compteCorrentId
     * @return void
     */
    private function preloadCategories(int $compteCorrentId): void
    {
        if (isset($this->preloadedCategories[$compteCorrentId])) {
            return; // Already preloaded
        }

        $categories = Categoria::where('compte_corrent_id', $compteCorrentId)
            ->get(['id', 'nom', 'categoria_pare_id'])
            ->map(function ($cat) {
                return [
                    'id' => $cat->id,
                    'nom' => mb_strtoupper($cat->nom, 'UTF-8'),
                    'categoria_pare_id' => $cat->categoria_pare_id,
                ];
            })
            ->toArray();

        $this->preloadedCategories[$compteCorrentId] = $categories;
    }

    /**
     * Match category path to existing category ID.
     * Uses caching to avoid repeated DB queries.
     *
     * @param string $categoryPath
     * @param int $compteCorrentId
     * @param float $import Movement amount (negative for expenses, positive for income)
     * @return int|null
     */
    public function matchCategoryPath(string $categoryPath, int $compteCorrentId, float $import): ?int
    {
        $cacheKey = "{$compteCorrentId}:{$categoryPath}";
        if (isset($this->categoryCache[$cacheKey])) {
            return $this->categoryCache[$cacheKey];
        }

        $result = $this->performCategoryLookup($categoryPath, $compteCorrentId, $import);
        $this->categoryCache[$cacheKey] = $result;
        return $result;
    }

    /**
     * Perform actual category lookup by navigating hierarchy.
     *
     * @param string $categoryPath
     * @param int $compteCorrentId
     * @param float $import Movement amount (negative for expenses, positive for income)
     * @return int|null
     */
    private function performCategoryLookup(string $categoryPath, int $compteCorrentId, float $import): ?int
    {
        if (empty($categoryPath)) {
            return null;
        }

        // Remove leading/trailing colons
        $categoryPath = trim($categoryPath, ':');

        // Try multiple strategies to find the category
        // 1. Try as-is (exact path from QIF) - search only, don't create
        $result = $this->traverseCategoryPath($categoryPath, $compteCorrentId, false);
        if ($result !== null) {
            return $result;
        }

        // 2. Try prefixing based on movement amount - create if needed
        // Negative amounts = DESPESES, Positive amounts = INGRESSOS
        if ($import < 0) {
            // Try DESPESES first for negative amounts (expenses) - create if needed
            $result = $this->traverseCategoryPath('DESPESES:' . $categoryPath, $compteCorrentId, true);
            if ($result !== null) {
                return $result;
            }

            // Fallback: try INGRESSOS (in case of refunds or corrections) - create if needed
            $result = $this->traverseCategoryPath('INGRESSOS:' . $categoryPath, $compteCorrentId, true);
            if ($result !== null) {
                return $result;
            }
        } else {
            // Try INGRESSOS first for positive amounts (income) - create if needed
            $result = $this->traverseCategoryPath('INGRESSOS:' . $categoryPath, $compteCorrentId, true);
            if ($result !== null) {
                return $result;
            }

            // Fallback: try DESPESES (in case of corrections) - create if needed
            $result = $this->traverseCategoryPath('DESPESES:' . $categoryPath, $compteCorrentId, true);
            if ($result !== null) {
                return $result;
            }
        }

        // If all strategies fail, log warning
        Log::warning('Category not found after trying all strategies', [
            'original_path' => $categoryPath,
            'compte_corrent_id' => $compteCorrentId,
            'import' => $import,
        ]);

        return null;
    }

    /**
     * Traverse category path and return the final category ID.
     * Creates categories automatically if they don't exist (when $createIfNotExists is true).
     *
     * @param string $categoryPath
     * @param int $compteCorrentId
     * @param bool $createIfNotExists Whether to create categories if they don't exist
     * @return int|null
     */
    private function traverseCategoryPath(string $categoryPath, int $compteCorrentId, bool $createIfNotExists = false): ?int
    {
        $parts = explode(':', $categoryPath);

        // Use preloaded categories if available
        $categories = $this->preloadedCategories[$compteCorrentId] ?? [];

        $currentParentId = null;

        foreach ($parts as $name) {
            $name = trim($name);
            if (empty($name)) {
                continue;
            }

            $nameUpper = mb_strtoupper($name, 'UTF-8');

            // Search in preloaded categories
            $category = null;
            foreach ($categories as $cat) {
                if ($cat['nom'] === $nameUpper && $cat['categoria_pare_id'] === $currentParentId) {
                    $category = $cat;
                    break;
                }
            }

            // If category doesn't exist
            if (!$category) {
                // Return null if we shouldn't create it
                if (!$createIfNotExists) {
                    return null;
                }

                // Get max ordre for siblings
                $maxOrdre = Categoria::where('compte_corrent_id', $compteCorrentId)
                    ->where('categoria_pare_id', $currentParentId)
                    ->max('ordre') ?? -1;

                // Create new category
                $newCategory = Categoria::create([
                    'compte_corrent_id' => $compteCorrentId,
                    'nom' => $nameUpper,
                    'categoria_pare_id' => $currentParentId,
                    'ordre' => $maxOrdre + 1,
                ]);

                Log::info('Created category automatically during movement import', [
                    'categoria_id' => $newCategory->id,
                    'nom' => $nameUpper,
                    'pare_id' => $currentParentId,
                    'path' => $categoryPath,
                ]);

                // Add to preloaded cache
                $categories[] = [
                    'id' => $newCategory->id,
                    'nom' => $nameUpper,
                    'categoria_pare_id' => $currentParentId,
                ];
                $this->preloadedCategories[$compteCorrentId] = $categories;

                $currentParentId = $newCategory->id;
            } else {
                $currentParentId = $category['id'];
            }
        }

        return $currentParentId;
    }

    /**
     * Import movements to database.
     *
     * @param array $movements
     * @param int $compteCorrentId
     * @return array Statistics
     */
    public function import(array $movements, int $compteCorrentId): array
    {
        $created = 0;
        $skipped = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            // For large files, use chunked inserts
            if (count($movements) > 500) {
                $chunks = array_chunk($movements, 100);
                foreach ($chunks as $chunk) {
                    foreach ($chunk as $movement) {
                        if ($this->createMovement($movement, $compteCorrentId)) {
                            $created++;
                        } else {
                            $skipped++;
                        }
                    }
                }
            } else {
                // For smaller files, insert one by one for better error handling
                foreach ($movements as $movement) {
                    if ($this->createMovement($movement, $compteCorrentId)) {
                        $created++;
                    } else {
                        $skipped++;
                    }
                }
            }

            DB::commit();

            Log::info('Movement import completed', [
                'compte_corrent_id' => $compteCorrentId,
                'created' => $created,
                'skipped' => $skipped,
            ]);

            return [
                'created' => $created,
                'skipped' => $skipped,
                'errors' => $errors,
            ];
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Movement import failed', [
                'compte_corrent_id' => $compteCorrentId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Create a single movement record.
     *
     * @param array $movement
     * @param int $compteCorrentId
     * @return bool True if created, false if skipped (duplicate)
     */
    private function createMovement(array $movement, int $compteCorrentId): bool
    {
        // Check if movement already exists
        $exists = MovimentCompteCorrent::where('hash', $movement['hash'])
            ->where('compte_corrent_id', $compteCorrentId)
            ->exists();

        if ($exists) {
            return false; // Skip duplicate
        }

        MovimentCompteCorrent::create([
            'data_moviment' => $movement['data_moviment'],
            'concepte' => $movement['concepte'],
            'import' => $movement['import'],
            'saldo_posterior' => $movement['saldo_posterior'],
            'hash' => $movement['hash'],
            'conciliat' => false,
            'notes' => $movement['notes'] ?? null,
            'compte_corrent_id' => $compteCorrentId,
            'categoria_id' => $movement['categoria_id'] ?? null,
        ]);

        return true;
    }
}
