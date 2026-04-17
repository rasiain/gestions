<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoriaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'compte_corrent_id' => 'required|integer|exists:g_comptes_corrents,id',
        ]);

        $categories = Categoria::where('compte_corrent_id', $validated['compte_corrent_id'])
            ->orderBy('nom')
            ->get();

        $data = $categories->map(fn (Categoria $cat) => [
            'id' => $cat->id,
            'nom' => $cat->nom,
            'categoria_pare_id' => $cat->categoria_pare_id,
            'full_path' => $this->buildFullPath($cat, $categories),
        ]);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nom' => 'required|string|max:255',
            'compte_corrent_id' => 'required|integer|exists:g_comptes_corrents,id',
            'categoria_pare_id' => 'sometimes|nullable|integer|exists:g_categories,id',
        ]);

        $categoria = Categoria::create($validated);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $categoria->id,
                'nom' => $categoria->nom,
                'categoria_pare_id' => $categoria->categoria_pare_id,
            ],
        ], 201);
    }

    private function buildFullPath(Categoria $categoria, $allCategories): string
    {
        $path = [$categoria->nom];
        $current = $categoria;

        while ($current->categoria_pare_id) {
            $current = $allCategories->firstWhere('id', $current->categoria_pare_id);
            if (!$current) {
                break;
            }
            array_unshift($path, $current->nom);
        }

        return implode(' > ', $path);
    }
}
