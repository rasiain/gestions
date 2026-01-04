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
        $this->generateHashesForMovements($parsedMovements, $compteCorrentId);

        // Step 2: Find last matching movement by hash
        $lastMatch = $this->findLastMovementIndex($parsedMovements, $compteCorrentId);

        // Step 3: Determine import range
        $result = $this->filterMovementsToImport($parsedMovements, $lastMatch, $compteCorrentId, $importMode);

        // Step 4: Validate balances if file has balance info
        $balanceResult = $this->processBalances($result, $lastMatch, $compteCorrentId);
        if ($balanceResult !== null) {
            return $balanceResult; // Return early if balance validation failed
        }

        // Step 5: Match categories (for QIF with category paths)
        $this->matchCategoriesForMovements($result['movements'], $compteCorrentId);

        return $result;
    }

    /**
     * Find the index of the last consecutive movement in the file that exists in DB.
     * Searches from the beginning (oldest) and validates that all found movements are consecutive.
     *
     * @param array $movements
     * @param int $compteCorrentId
     * @return array ['index' => int, 'movement' => Model|null, 'found' => bool, 'consecutive' => bool, 'gap_at_index' => int|null]
     */
    private function findLastMovementIndex(array $movements, int $compteCorrentId): array
    {
        // Extract all hashes from movements
        $fileHashes = array_column($movements, 'hash');

        // Get all matching movements from DB ordered by date and ID
        $dbMovements = collect();
        $chunks = array_chunk($fileHashes, 1000);

        foreach ($chunks as $chunk) {
            $results = MovimentCompteCorrent::where('compte_corrent_id', $compteCorrentId)
                ->whereIn('hash', $chunk)
                ->orderBy('data_moviment', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            $dbMovements = $dbMovements->merge($results);
        }

        // Index by hash for fast lookup
        $dbMovementsByHash = $dbMovements->keyBy('hash');

        // Search from beginning (oldest) to find consecutive matches
        $lastConsecutiveIndex = -1;
        $previousDbMovement = null;

        for ($i = 0; $i < count($movements); $i++) {
            $hash = $movements[$i]['hash'];

            // Check if this movement exists in DB
            if (isset($dbMovementsByHash[$hash])) {
                $currentDbMovement = $dbMovementsByHash[$hash];

                Log::debug('Found matching movement in DB', [
                    'file_index' => $i,
                    'file_movement' => [
                        'date' => $movements[$i]['data_moviment'],
                        'import' => $movements[$i]['import'],
                        'concepte' => $movements[$i]['concepte'],
                        'saldo' => $movements[$i]['saldo_posterior'] ?? 'N/A',
                    ],
                    'db_movement_id' => $currentDbMovement->id,
                    'hash' => $hash,
                ]);

                // If this is not the first match, validate consecutivity
                if ($previousDbMovement !== null) {
                    // Check if movements are consecutive in DB (by ID or date)
                    if (!$this->areMovementsConsecutive($previousDbMovement, $currentDbMovement)) {
                        // Found a gap - movements are not consecutive
                        Log::warning('Movement import: Non-consecutive movements detected', [
                            'file_index' => $i,
                            'previous_db_id' => $previousDbMovement->id,
                            'current_db_id' => $currentDbMovement->id,
                            'gap_detected' => true,
                        ]);

                        return [
                            'index' => $lastConsecutiveIndex,
                            'movement' => $previousDbMovement,
                            'found' => true,
                            'consecutive' => false,
                            'gap_at_index' => $i,
                        ];
                    }
                }

                // Movement is consecutive, update tracking
                $lastConsecutiveIndex = $i;
                $previousDbMovement = $currentDbMovement;
            } else {
                // Found first movement that doesn't exist in DB
                Log::debug('Found first non-existing movement', [
                    'file_index' => $i,
                    'file_movement' => [
                        'date' => $movements[$i]['data_moviment'],
                        'import' => $movements[$i]['import'],
                        'concepte' => $movements[$i]['concepte'],
                        'saldo' => $movements[$i]['saldo_posterior'] ?? 'N/A',
                    ],
                    'hash' => $hash,
                ]);
                // This is the expected case - file continues from where DB ends
                break;
            }
        }

        if ($lastConsecutiveIndex >= 0) {
            Log::info('Found last consecutive movement', [
                'index' => $lastConsecutiveIndex,
                'db_movement_id' => $previousDbMovement->id,
                'db_movement_date' => $previousDbMovement->data_moviment->format('Y-m-d'),
                'db_movement_import' => $previousDbMovement->import,
                'file_movement' => [
                    'date' => $movements[$lastConsecutiveIndex]['data_moviment'] ?? null,
                    'import' => $movements[$lastConsecutiveIndex]['import'] ?? null,
                    'concepte' => $movements[$lastConsecutiveIndex]['concepte'] ?? null,
                ],
            ]);

            return [
                'index' => $lastConsecutiveIndex,
                'movement' => $previousDbMovement,
                'found' => true,
                'consecutive' => true,
                'gap_at_index' => null,
            ];
        }

        return [
            'index' => -1,
            'movement' => null,
            'found' => false,
            'consecutive' => true,
            'gap_at_index' => null,
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
            // Check if movements are consecutive
            if (!$lastMatch['consecutive']) {
                return [
                    'movements' => [],
                    'last_hash_found' => true,
                    'last_db_movement' => $lastMatch['movement'],
                    'duplicates_skipped' => 0,
                    'to_import_count' => 0,
                    'warnings' => [],
                    'errors' => [
                        sprintf(
                            'S\'ha detectat un salt en els moviments a la posició %d del fitxer.',
                            $lastMatch['gap_at_index'] + 1
                        ),
                        'Els moviments del fitxer no són consecutius amb els de la base de dades.',
                        'Això pot indicar que falten moviments o que el fitxer no està complet.',
                        'Revisa el fitxer i assegura\'t que conté tots els moviments des de l\'inici.',
                    ],
                    'non_consecutive' => true,
                ];
            }

            // Import only movements after the last match
            $toImport = array_slice($movements, $lastMatch['index'] + 1);

            // Debug logging
            Log::info('Movement filtering details', [
                'last_match_index' => $lastMatch['index'],
                'total_movements_in_file' => count($movements),
                'movements_to_import' => count($toImport),
                'last_matched_movement' => [
                    'date' => $movements[$lastMatch['index']]['data_moviment'] ?? null,
                    'concept' => $movements[$lastMatch['index']]['concepte'] ?? null,
                    'import' => $movements[$lastMatch['index']]['import'] ?? null,
                    'saldo' => $movements[$lastMatch['index']]['saldo_posterior'] ?? null,
                    'hash' => $movements[$lastMatch['index']]['hash'] ?? null,
                ],
                'next_movement_after_match' => isset($movements[$lastMatch['index'] + 1]) ? [
                    'date' => $movements[$lastMatch['index'] + 1]['data_moviment'] ?? null,
                    'concept' => $movements[$lastMatch['index'] + 1]['concepte'] ?? null,
                    'import' => $movements[$lastMatch['index'] + 1]['import'] ?? null,
                    'saldo' => $movements[$lastMatch['index'] + 1]['saldo_posterior'] ?? null,
                    'hash' => $movements[$lastMatch['index'] + 1]['hash'] ?? null,
                ] : null,
                'first_to_import' => !empty($toImport) ? [
                    'date' => $toImport[0]['data_moviment'] ?? null,
                    'concept' => $toImport[0]['concepte'] ?? null,
                    'import' => $toImport[0]['import'] ?? null,
                    'saldo' => $toImport[0]['saldo_posterior'] ?? null,
                    'hash' => $toImport[0]['hash'] ?? null,
                ] : null,
            ]);

            $warnings = [];
            if (count($toImport) === 0) {
                $warnings[] = 'Tots els moviments del fitxer ja existeixen a la base de dades.';
            }

            return [
                'movements' => array_values($toImport),
                'last_hash_found' => true,
                'last_db_movement' => $lastMatch['movement'],
                'duplicates_skipped' => $lastMatch['index'] + 1,
                'to_import_count' => count($toImport),
                'warnings' => $warnings,
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

            // Filter out movements that already exist in DB (by hash)
            // This allows importing new movements even if they're from the same date as existing ones
            $existingHashes = MovimentCompteCorrent::where('compte_corrent_id', $compteCorrentId)
                ->pluck('hash')
                ->flip()
                ->toArray();

            $toImport = array_filter($movements, function ($mov) use ($existingHashes) {
                return !isset($existingHashes[$mov['hash']]);
            });

            Log::info('Filtering movements by hash', [
                'total_movements' => count($movements),
                'existing_hashes_count' => count($existingHashes),
                'movements_to_import' => count($toImport),
            ]);

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

        // Reverse movements array so oldest movements get lowest IDs
        // This ensures proper display order when sorted by date DESC + ID DESC
        $movements = array_reverse($movements);

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

        // Store original concept
        $concepteOriginal = $movement['concepte'];
        $concepte = $concepteOriginal;
        $categoriaId = $movement['categoria_id'] ?? null;
        $notes = $movement['notes'] ?? $concepteOriginal; // Use original concept as notes fallback

        // Search for previous movement with same original concept to reuse manual edits
        $previousMovement = MovimentCompteCorrent::where('compte_corrent_id', $compteCorrentId)
            ->where('concepte_original', $concepteOriginal)
            ->orderBy('data_moviment', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if ($previousMovement) {
            // Reuse manually edited concept and category from previous movement
            $concepte = $previousMovement->concepte;
            $categoriaId = $previousMovement->categoria_id ?? $categoriaId;

            Log::info('Movement concept matched with previous movement', [
                'concepte_original' => $concepteOriginal,
                'concepte_reused' => $concepte,
                'categoria_id' => $categoriaId,
                'previous_movement_id' => $previousMovement->id,
            ]);
        }

        MovimentCompteCorrent::create([
            'data_moviment' => $movement['data_moviment'],
            'concepte' => $concepte,
            'concepte_original' => $concepteOriginal,
            'import' => $movement['import'],
            'saldo_posterior' => $movement['saldo_posterior'],
            'hash' => $movement['hash'],
            'conciliat' => false,
            'notes' => $notes,
            'compte_corrent_id' => $compteCorrentId,
            'categoria_id' => $categoriaId,
        ]);

        return true;
    }

    /**
     * Check if two movements are consecutive in the database.
     * Two movements are consecutive if they are adjacent by ID (allowing for small gaps).
     *
     * @param MovimentCompteCorrent $previous
     * @param MovimentCompteCorrent $current
     * @return bool
     */
    private function areMovementsConsecutive(MovimentCompteCorrent $previous, MovimentCompteCorrent $current): bool
    {
        // Check if IDs are consecutive or very close (allowing small gaps from deletions)
        $idDiff = $current->id - $previous->id;

        // Allow gaps up to 10 IDs (to account for potential deletions or corrections)
        if ($idDiff > 0 && $idDiff <= 10) {
            return true;
        }

        // If ID difference is too large, check if dates are consecutive or very close
        $dateDiff = $current->data_moviment->diffInDays($previous->data_moviment, false);

        // Allow up to 90 days difference (for accounts with infrequent movements)
        if ($dateDiff >= 0 && $dateDiff <= 90) {
            return true;
        }

        // Movements are too far apart
        return false;
    }

    /**
     * Generate hashes for all movements.
     *
     * @param array $movements
     * @param int $compteCorrentId
     * @return void
     */
    private function generateHashesForMovements(array &$movements, int $compteCorrentId): void
    {
        foreach ($movements as &$movement) {
            $movement['hash'] = MovimentCompteCorrent::generateHash(
                $movement['data_moviment'],
                $movement['concepte'],
                $movement['import'],
                $compteCorrentId
            );
        }
        unset($movement); // Break reference
    }

    /**
     * Process balances: validate or calculate them.
     *
     * @param array $result
     * @param array $lastMatch
     * @param int $compteCorrentId
     * @return array|null Returns error array if validation fails, null if success
     */
    private function processBalances(array &$result, array $lastMatch, int $compteCorrentId): ?array
    {
        if (empty($result['movements'])) {
            return null;
        }

        $hasBalance = $result['movements'][0]['saldo_posterior'] !== null;

        if ($hasBalance) {
            // Balance validation disabled - different import sources may order same-day movements differently
            // Users can verify balances by reviewing the imported data
            // $balanceErrors = $this->validateBalances($result['movements'], $compteCorrentId);
            // if (!empty($balanceErrors)) {
            //     return [
            //         'movements' => [],
            //         'last_hash_found' => $lastMatch['found'],
            //         'last_db_movement' => $lastMatch['movement'],
            //         'duplicates_skipped' => 0,
            //         'to_import_count' => 0,
            //         'warnings' => [],
            //         'errors' => $balanceErrors,
            //         'balance_validation_failed' => true,
            //     ];
            // }
        } else {
            // No balance in file: calculate balances
            $result['movements'] = $this->calculateBalances($result['movements'], $compteCorrentId);
        }

        return null; // Success
    }

    /**
     * Match categories for all movements.
     *
     * @param array $movements
     * @param int $compteCorrentId
     * @return void
     */
    private function matchCategoriesForMovements(array &$movements, int $compteCorrentId): void
    {
        // Preload all categories for this compte corrent to avoid repeated DB queries
        $this->preloadCategories($compteCorrentId);

        foreach ($movements as &$movement) {
            // First try to match from categoria_path (from imported file like KMyMoney)
            if (isset($movement['categoria_path']) && $movement['categoria_path']) {
                $movement['categoria_id'] = $this->matchCategoryPath(
                    $movement['categoria_path'],
                    $compteCorrentId,
                    $movement['import']
                );
                $movement['categoria_path'] = $this->getCategoryFullPath($movement['categoria_id']);
            } else {
                // Try to match category by searching similar concepts in notes
                $categoriaId = $this->matchCategoryByNotes($movement['notes'], $compteCorrentId);

                if ($categoriaId) {
                    $movement['categoria_id'] = $categoriaId;
                    $movement['categoria_path'] = $this->getCategoryFullPath($categoriaId);
                } else {
                    $movement['categoria_id'] = null;
                }
            }
        }
        unset($movement); // Break reference
    }

    /**
     * Try to match category by searching for similar concepts in previous movements' notes.
     * Removes card prefix (e.g., "TARGETA *6563 ") before searching.
     *
     * @param string|null $notes
     * @param int $compteCorrentId
     * @return int|null
     */
    private function matchCategoryByNotes(?string $notes, int $compteCorrentId): ?int
    {
        if (empty($notes)) {
            return null;
        }

        // Extract concept without card prefix
        $searchTerm = $this->extractConceptWithoutCard($notes);

        if (empty($searchTerm) || strlen($searchTerm) < 5) {
            // Don't search for very short terms to avoid false matches
            return null;
        }

        // Search for previous movements with similar notes
        $previousMovement = MovimentCompteCorrent::where('compte_corrent_id', $compteCorrentId)
            ->whereNotNull('categoria_id')
            ->where('notes', 'LIKE', '%' . $searchTerm . '%')
            ->orderBy('data_moviment', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if ($previousMovement) {
            Log::info('Category matched by notes similarity', [
                'original_notes' => $notes,
                'search_term' => $searchTerm,
                'matched_notes' => $previousMovement->notes,
                'categoria_id' => $previousMovement->categoria_id,
                'previous_movement_id' => $previousMovement->id,
            ]);

            return $previousMovement->categoria_id;
        }

        return null;
    }

    /**
     * Extract concept without card prefix.
     * Example: "TARGETA *6563 FARMACIA L.PLA CAMA" -> "FARMACIA L.PLA CAMA"
     *
     * @param string $concept
     * @return string
     */
    private function extractConceptWithoutCard(string $concept): string
    {
        // Match pattern: TARGETA *XXXX followed by space and the actual concept
        if (preg_match('/^TARGETA\s+\*\d+\s+(.+)$/i', $concept, $matches)) {
            return trim($matches[1]);
        }

        // If no card prefix found, return original concept
        return $concept;
    }

    /**
     * Get full category path for display.
     *
     * @param int|null $categoriaId
     * @return string|null
     */
    private function getCategoryFullPath(?int $categoriaId): ?string
    {
        if (!$categoriaId) {
            return null;
        }

        $paths = [];
        $currentId = $categoriaId;

        while ($currentId) {
            $categoria = Categoria::find($currentId);
            if (!$categoria) {
                break;
            }

            array_unshift($paths, $categoria->nom);
            $currentId = $categoria->categoria_pare_id;
        }

        return !empty($paths) ? implode(' > ', $paths) : null;
    }
}
