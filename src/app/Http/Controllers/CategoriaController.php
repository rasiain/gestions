<?php

namespace App\Http\Controllers;

use App\Http\Requests\CategoriaRequest;
use App\Models\Categoria;
use App\Models\CompteCorrent;
use App\Models\MovimentCompteCorrent;
use Illuminate\Http\JsonResponse;
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

    public function totals(Request $request, Categoria $category): JsonResponse
    {
        $dataInici = $request->input('data_inici');
        $dataFi    = $request->input('data_fi');

        $ids = $this->collectDescendantIds($category->id);

        $row = MovimentCompteCorrent::whereIn('categoria_id', $ids)
            ->when($dataInici, fn($q) => $q->whereDate('data_moviment', '>=', $dataInici))
            ->when($dataFi,    fn($q) => $q->whereDate('data_moviment', '<=', $dataFi))
            ->selectRaw('
                COALESCE(SUM(CASE WHEN import > 0 THEN import ELSE 0 END), 0) as ingressos,
                COALESCE(SUM(CASE WHEN import < 0 THEN import ELSE 0 END), 0) as despeses,
                COALESCE(SUM(import), 0) as net,
                COUNT(*) as total
            ')
            ->first();

        return response()->json([
            'ingressos' => (float) $row->ingressos,
            'despeses'  => (float) $row->despeses,
            'net'       => (float) $row->net,
            'total'     => (int)   $row->total,
        ]);
    }

    public function moviments(Request $request, Categoria $category): JsonResponse
    {
        $dataInici = $request->input('data_inici');
        $dataFi    = $request->input('data_fi');
        $perPage   = 25;

        $ids = $this->collectDescendantIds($category->id);

        $moviments = MovimentCompteCorrent::with(['concepte', 'categoria'])
            ->whereIn('categoria_id', $ids)
            ->when($dataInici, fn($q) => $q->whereDate('data_moviment', '>=', $dataInici))
            ->when($dataFi,    fn($q) => $q->whereDate('data_moviment', '<=', $dataFi))
            ->orderBy('data_moviment', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        return response()->json([
            'data' => $moviments->map(fn($m) => [
                'id'             => $m->id,
                'data_moviment'  => $m->data_moviment->format('Y-m-d'),
                'concepte'       => $m->concepte?->concepte ?? $m->concepte_original,
                'import'         => (float) $m->import,
                'categoria_nom'  => $m->categoria?->nom,
            ]),
            'total'        => $moviments->total(),
            'per_page'     => $perPage,
            'current_page' => $moviments->currentPage(),
            'last_page'    => $moviments->lastPage(),
        ]);
    }

    private function collectDescendantIds(int $categoriaId): array
    {
        $ids = [$categoriaId];
        foreach (Categoria::where('categoria_pare_id', $categoriaId)->pluck('id') as $fillId) {
            $ids = array_merge($ids, $this->collectDescendantIds($fillId));
        }
        return $ids;
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CategoriaRequest $request)
    {
        $validated = $request->validated();

        // Auto-assign ordre if not provided
        if (empty($validated['ordre'])) {
            $validated['ordre'] = Categoria::where('compte_corrent_id', $validated['compte_corrent_id'])
                ->where('categoria_pare_id', $validated['categoria_pare_id'] ?? null)
                ->max('ordre') + 1 ?? 0;
        }

        $categoria = Categoria::create($validated);

        if ($request->expectsJson()) {
            return response()->json([
                'id' => $categoria->id,
                'compte_corrent_id' => $categoria->compte_corrent_id,
                'nom' => $categoria->nom,
                'categoria_pare_id' => $categoria->categoria_pare_id,
                'ordre' => $categoria->ordre,
            ], 201);
        }

        return redirect()->route('categories.index')
            ->with('success', 'Categoria creada correctament.');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(CategoriaRequest $request, Categoria $category)
    {
        $category->update($request->validated());

        return redirect()->route('categories.index', [
            'compte_corrent_id' => $category->compte_corrent_id,
        ])->with('success', 'Categoria actualitzada correctament.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Categoria $category)
    {
        $compteCorrentId = $category->compte_corrent_id;
        $category->delete();

        return redirect()->route('categories.index', [
            'compte_corrent_id' => $compteCorrentId,
        ])->with('success', 'Categoria eliminada correctament.');
    }
}
