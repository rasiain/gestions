<?php

namespace App\Http\Services\Categories;

use App\Models\Categoria;
use App\Models\CompteCorrent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CategoryImportService
{
    /**
     * Ensure root categories (Ingressos and Despeses) exist for a compte corrent.
     * Creates them if they don't exist.
     *
     * @param int $compteCorrentId Compte corrent ID
     * @return void
     */
    public function ensureRootCategories(int $compteCorrentId): void
    {
        $rootCategories = ['Ingressos', 'Despeses'];

        foreach ($rootCategories as $index => $categoryName) {
            $existing = Categoria::where('compte_corrent_id', $compteCorrentId)
                ->where('nom', $categoryName)
                ->whereNull('categoria_pare_id')
                ->first();

            if (!$existing) {
                Categoria::create([
                    'compte_corrent_id' => $compteCorrentId,
                    'nom' => $categoryName,
                    'categoria_pare_id' => null,
                    'ordre' => $index,
                ]);

                Log::info("Created root category '{$categoryName}' for compte corrent {$compteCorrentId}");
            }
        }
    }

    /**
     * Validate categories before import
     *
     * @param array $parsedCategories Parsed categories from KMyMoney
     * @param int $compteCorrentId Compte corrent ID
     * @param string $rootType Root type (I or E for Ingressos or Despeses)
     * @return array Validation result with errors and warnings
     */
    public function validate(array $parsedCategories, int $compteCorrentId, string $rootType): array
    {
        $errors = [];
        $warnings = [];

        // Check if compte corrent exists
        $compteCorrent = CompteCorrent::find($compteCorrentId);
        if (!$compteCorrent) {
            $errors[] = "El compte corrent amb ID {$compteCorrentId} no existeix.";
            return ['valid' => false, 'errors' => $errors, 'warnings' => $warnings];
        }

        // Ensure root categories exist
        $this->ensureRootCategories($compteCorrentId);

        // Get root category (Ingressos or Despeses)
        $rootCategoryName = $rootType === 'I' ? 'Ingressos' : 'Despeses';
        $rootCategory = Categoria::where('compte_corrent_id', $compteCorrentId)
            ->where('nom', $rootCategoryName)
            ->whereNull('categoria_pare_id')
            ->first();

        // Load existing categories for this compte and root type
        $existingCategories = Categoria::where('compte_corrent_id', $compteCorrentId)
            ->where(function ($query) use ($rootCategory) {
                $query->where('id', $rootCategory->id)
                    ->orWhere('categoria_pare_id', $rootCategory->id)
                    ->orWhereHas('pare', function ($q) use ($rootCategory) {
                        $q->where('categoria_pare_id', $rootCategory->id);
                    });
            })
            ->get()
            ->keyBy('nom');

        // Check for duplicate siblings in parsed data
        $siblingGroups = [];
        foreach ($parsedCategories as $category) {
            $parentPath = $category['parent_path'] ?? 'root';
            if (!isset($siblingGroups[$parentPath])) {
                $siblingGroups[$parentPath] = [];
            }
            $siblingGroups[$parentPath][] = $category['name'];
        }

        foreach ($siblingGroups as $parentPath => $siblings) {
            $duplicates = array_diff_assoc($siblings, array_unique($siblings));
            if (!empty($duplicates)) {
                $warnings[] = "Categories germanes duplicades sota '{$parentPath}': " . implode(', ', array_unique($duplicates));
            }
        }

        // Check for conflicts with existing categories
        foreach ($parsedCategories as $category) {
            if (isset($existingCategories[$category['name']])) {
                $warnings[] = "La categoria '{$category['name']}' ja existeix i no es crearà de nou.";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Import categories to database
     *
     * @param array $parsedCategories Parsed categories from KMyMoney
     * @param int $compteCorrentId Compte corrent ID
     * @param string $rootType Root type (I or E)
     * @return array Import result with stats
     */
    public function import(array $parsedCategories, int $compteCorrentId, string $rootType): array
    {
        $stats = [
            'created' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        DB::beginTransaction();

        try {
            // Ensure root categories exist
            $this->ensureRootCategories($compteCorrentId);

            // Get root category
            $rootCategoryName = $rootType === 'I' ? 'Ingressos' : 'Despeses';
            $rootCategory = Categoria::where('compte_corrent_id', $compteCorrentId)
                ->where('nom', $rootCategoryName)
                ->whereNull('categoria_pare_id')
                ->first();

            // Sort categories by level to ensure parents are created before children
            usort($parsedCategories, fn($a, $b) => $a['level'] <=> $b['level']);

            // Map to store created category IDs by full path
            $createdCategories = ['root' => $rootCategory->id];

            foreach ($parsedCategories as $category) {
                // Determine parent ID
                $parentId = $rootCategory->id;
                if ($category['parent_path']) {
                    if (isset($createdCategories[$category['parent_path']])) {
                        $parentId = $createdCategories[$category['parent_path']];
                    } else {
                        // Parent not found, skip this category
                        $stats['skipped']++;
                        $stats['errors'][] = "No s'ha trobat el pare per '{$category['name']}' (pare: {$category['parent_path']})";
                        continue;
                    }
                }

                // Check if category already exists as sibling
                $existing = Categoria::where('compte_corrent_id', $compteCorrentId)
                    ->where('nom', $category['name'])
                    ->where('categoria_pare_id', $parentId)
                    ->first();

                if ($existing) {
                    $stats['skipped']++;
                    $createdCategories[$category['full_path']] = $existing->id;
                    continue;
                }

                // Get max ordre for siblings
                $maxOrdre = Categoria::where('compte_corrent_id', $compteCorrentId)
                    ->where('categoria_pare_id', $parentId)
                    ->max('ordre') ?? -1;

                // Create category
                $newCategory = Categoria::create([
                    'compte_corrent_id' => $compteCorrentId,
                    'nom' => $category['name'],
                    'categoria_pare_id' => $parentId,
                    'ordre' => $maxOrdre + 1,
                ]);

                $createdCategories[$category['full_path']] = $newCategory->id;
                $stats['created']++;
            }

            DB::commit();

            Log::info('Categories imported successfully', [
                'compte_corrent_id' => $compteCorrentId,
                'root_type' => $rootType,
                'stats' => $stats,
            ]);

            return $stats;

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error importing categories', [
                'error' => $e->getMessage(),
                'compte_corrent_id' => $compteCorrentId,
                'root_type' => $rootType,
            ]);

            throw $e;
        }
    }
}
