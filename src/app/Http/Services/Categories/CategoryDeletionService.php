<?php

namespace App\Http\Services\Categories;

use App\Models\Categoria;
use Illuminate\Support\Facades\DB;

class CategoryDeletionService
{
    /**
     * Delete imported categories for a specific compte corrent.
     *
     * Preserves root categories "Ingressos" and "Despeses".
     *
     * @param int $compteCorrentId
     * @return array ['deleted_count' => int]
     */
    public function deleteForCompteCorrent(int $compteCorrentId): array
    {
        // Get root categories to preserve
        $rootCategories = Categoria::where('compte_corrent_id', $compteCorrentId)
            ->whereNull('categoria_pare_id')
            ->whereIn('nom', ['Ingressos', 'Despeses'])
            ->pluck('id');

        // Delete all categories except root "Ingressos" and "Despeses"
        $deleted = Categoria::where('compte_corrent_id', $compteCorrentId)
            ->where(function ($query) use ($rootCategories) {
                $query->whereNotNull('categoria_pare_id')
                    ->orWhere(function ($subQuery) use ($rootCategories) {
                        $subQuery->whereNull('categoria_pare_id')
                            ->whereNotIn('id', $rootCategories);
                    });
            })
            ->delete();

        return [
            'deleted_count' => $deleted,
        ];
    }

    /**
     * Delete all imported categories from all comptes corrents.
     *
     * Preserves root categories "Ingressos" and "Despeses" for all accounts.
     * Resets the autoincrement sequence to continue after the highest existing ID.
     *
     * @return array ['deleted_count' => int, 'autoincrement_reset_to' => int]
     */
    public function deleteAll(): array
    {
        // Get all root categories to preserve
        $rootCategories = Categoria::whereNull('categoria_pare_id')
            ->whereIn('nom', ['Ingressos', 'Despeses'])
            ->pluck('id');

        // Delete all categories except root "Ingressos" and "Despeses"
        $deleted = Categoria::where(function ($query) use ($rootCategories) {
            $query->whereNotNull('categoria_pare_id')
                ->orWhere(function ($subQuery) use ($rootCategories) {
                    $subQuery->whereNull('categoria_pare_id')
                        ->whereNotIn('id', $rootCategories);
                });
        })
        ->delete();

        // Reset autoincrement to continue after the highest existing ID
        $maxId = $this->resetAutoincrement();

        return [
            'deleted_count' => $deleted,
            'autoincrement_reset_to' => $maxId,
        ];
    }

    /**
     * Reset the autoincrement sequence to continue after the highest existing ID.
     *
     * This is SQLite-specific. For other databases, this method should be adapted.
     *
     * @return int The new autoincrement value (next ID will be maxId + 1)
     */
    private function resetAutoincrement(): int
    {
        $maxId = Categoria::max('id') ?? 0;

        // SQLite-specific: Reset autoincrement sequence
        // Setting seq to maxId means next insert will get maxId + 1
        DB::statement("DELETE FROM sqlite_sequence WHERE name='g_categories'");
        DB::statement("INSERT INTO sqlite_sequence (name, seq) VALUES ('g_categories', ?)", [$maxId]);

        return $maxId;
    }

    /**
     * Get the root categories that will be preserved during deletion.
     *
     * @param int|null $compteCorrentId If provided, only for that account. Otherwise, all accounts.
     * @return \Illuminate\Support\Collection Collection of category IDs
     */
    public function getPreservedRootCategories(?int $compteCorrentId = null)
    {
        $query = Categoria::whereNull('categoria_pare_id')
            ->whereIn('nom', ['Ingressos', 'Despeses']);

        if ($compteCorrentId !== null) {
            $query->where('compte_corrent_id', $compteCorrentId);
        }

        return $query->pluck('id');
    }

    /**
     * Count how many categories would be deleted for a given compte corrent.
     *
     * Useful for confirmation dialogs.
     *
     * @param int $compteCorrentId
     * @return int
     */
    public function countDeletableForCompteCorrent(int $compteCorrentId): int
    {
        $rootCategories = $this->getPreservedRootCategories($compteCorrentId);

        return Categoria::where('compte_corrent_id', $compteCorrentId)
            ->where(function ($query) use ($rootCategories) {
                $query->whereNotNull('categoria_pare_id')
                    ->orWhere(function ($subQuery) use ($rootCategories) {
                        $subQuery->whereNull('categoria_pare_id')
                            ->whereNotIn('id', $rootCategories);
                    });
            })
            ->count();
    }

    /**
     * Count how many categories would be deleted globally.
     *
     * Useful for confirmation dialogs.
     *
     * @return int
     */
    public function countDeletableGlobally(): int
    {
        $rootCategories = $this->getPreservedRootCategories();

        return Categoria::where(function ($query) use ($rootCategories) {
            $query->whereNotNull('categoria_pare_id')
                ->orWhere(function ($subQuery) use ($rootCategories) {
                    $subQuery->whereNull('categoria_pare_id')
                        ->whereNotIn('id', $rootCategories);
                });
        })
        ->count();
    }
}
