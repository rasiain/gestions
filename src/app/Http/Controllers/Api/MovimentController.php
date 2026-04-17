<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MovimentCompteCorrent;
use App\Models\Categoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MovimentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'compte_corrent_id' => 'required|integer|exists:g_comptes_corrents,id',
            'sense_categoria' => 'sometimes|boolean',
            'limit' => 'sometimes|integer|min:1|max:1000',
            'data_inici' => 'sometimes|date',
            'data_fi' => 'sometimes|date',
        ]);

        $query = MovimentCompteCorrent::with(['concepte', 'categoria'])
            ->where('compte_corrent_id', $validated['compte_corrent_id'])
            ->orderByDesc('data_moviment')
            ->orderByDesc('id');

        if (!empty($validated['sense_categoria'])) {
            $query->senseCategoria();
        }

        if (!empty($validated['data_inici']) && !empty($validated['data_fi'])) {
            $query->betweenDates($validated['data_inici'], $validated['data_fi']);
        } elseif (!empty($validated['data_inici'])) {
            $query->where('data_moviment', '>=', $validated['data_inici']);
        } elseif (!empty($validated['data_fi'])) {
            $query->where('data_moviment', '<=', $validated['data_fi']);
        }

        $limit = $validated['limit'] ?? 100;
        $moviments = $query->limit($limit)->get();

        $data = $moviments->map(fn (MovimentCompteCorrent $m) => [
            'id' => $m->id,
            'data_moviment' => $m->data_moviment->format('Y-m-d'),
            'concepte' => $m->concepte?->concepte,
            'concepte_original' => $m->concepte_original,
            'import' => (float) $m->import,
            'saldo_posterior' => (float) $m->saldo_posterior,
            'categoria_id' => $m->categoria_id,
            'categoria' => $m->categoria?->nom,
            'conciliat' => $m->conciliat,
            'notes' => $m->notes,
        ]);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function updateCategoria(Request $request, int $movimentId): JsonResponse
    {
        $validated = $request->validate([
            'categoria_id' => 'required|integer|exists:g_categories,id',
        ]);

        $moviment = MovimentCompteCorrent::findOrFail($movimentId);
        $moviment->update(['categoria_id' => $validated['categoria_id']]);

        return response()->json([
            'success' => true,
            'message' => 'Categoria actualitzada',
        ]);
    }

    public function bulkClassifica(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'moviment_ids' => 'required|array|min:1',
            'moviment_ids.*' => 'integer|exists:g_moviments_comptes_corrents,id',
            'categoria_id' => 'required|integer|exists:g_categories,id',
        ]);

        $updated = MovimentCompteCorrent::whereIn('id', $validated['moviment_ids'])
            ->update(['categoria_id' => $validated['categoria_id']]);

        return response()->json([
            'success' => true,
            'data' => ['updated' => $updated],
        ]);
    }
}
