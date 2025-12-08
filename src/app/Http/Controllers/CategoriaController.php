<?php

namespace App\Http\Controllers;

use App\Http\Requests\CategoriaRequest;
use App\Models\Categoria;
use App\Models\CompteCorrent;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CategoriaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        // Get all comptes corrents for the selector
        $comptesCorrents = CompteCorrent::orderBy('ordre')->orderBy('entitat')->get();

        // Get the selected compte_corrent_id from request, or default to first one
        $compteCorrentId = $request->input('compte_corrent_id', $comptesCorrents->first()?->id);

        // Get categories filtered by compte_corrent_id
        $categories = [];
        if ($compteCorrentId) {
            // Load all categories for this compte corrent
            $allCategories = Categoria::perCompteCorrent($compteCorrentId)
                ->orderBy('nom')
                ->get();

            // Build hierarchical structure with all levels
            $categories = $this->buildCategoryTree($allCategories, null);
        }

        return Inertia::render('Categories/Index', [
            'categories' => $categories,
            'comptesCorrents' => $comptesCorrents,
            'selectedCompteCorrentId' => $compteCorrentId,
        ]);
    }

    /**
     * Build a hierarchical category tree from a flat collection.
     * This method recursively builds the entire tree structure, supporting unlimited depth.
     *
     * @param \Illuminate\Database\Eloquent\Collection $categories All categories
     * @param int|null $parentId Parent category ID (null for root categories)
     * @return array Hierarchical tree structure
     */
    private function buildCategoryTree($categories, $parentId = null)
    {
        $tree = [];

        foreach ($categories as $category) {
            if ($category->categoria_pare_id === $parentId) {
                // Convert to array to add children
                $categoryArray = $category->toArray();

                // Recursively get all children at any depth
                $categoryArray['fills'] = $this->buildCategoryTree($categories, $category->id);

                $tree[] = $categoryArray;
            }
        }

        return $tree;
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CategoriaRequest $request)
    {
        Categoria::create($request->validated());

        return redirect()->route('categories.index')
            ->with('success', 'Categoria creada correctament.');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(CategoriaRequest $request, Categoria $categoria)
    {
        $categoria->update($request->validated());

        return redirect()->route('categories.index')
            ->with('success', 'Categoria actualitzada correctament.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Categoria $categoria)
    {
        $categoria->delete();

        return redirect()->route('categories.index')
            ->with('success', 'Categoria eliminada correctament.');
    }
}
