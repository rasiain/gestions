<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Categoria;

/**
 * Categories amb el path complet, per al selector d'edició de moviments de les
 * vistes derivades (taxes, assegurances), que treballen amb comptes diversos.
 */
trait CategoriesPerCompte
{
    /**
     * Categories (amb full_path) de cada compte indicat, indexades per compte_corrent_id.
     *
     * @param  array<int, int>  $compteIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function categoriesPerCompte(array $compteIds): array
    {
        if ($compteIds === []) {
            return [];
        }

        $categories = Categoria::whereIn('compte_corrent_id', $compteIds)
            ->orderBy('nom')
            ->get();

        $perId = $categories->keyBy('id');
        $resultat = [];

        foreach ($categories as $cat) {
            // full_path (arrel > ... > fulla)
            $path = [$cat->nom];
            $parentId = $cat->categoria_pare_id;
            while ($parentId && isset($perId[$parentId])) {
                $path[] = $perId[$parentId]->nom;
                $parentId = $perId[$parentId]->categoria_pare_id;
            }

            $resultat[$cat->compte_corrent_id][] = [
                'id'                => $cat->id,
                'compte_corrent_id' => $cat->compte_corrent_id,
                'nom'               => $cat->nom,
                'categoria_pare_id' => $cat->categoria_pare_id,
                'ordre'             => $cat->ordre,
                'full_path'         => implode(' > ', array_reverse($path)),
            ];
        }

        return $resultat;
    }
}
