<?php

namespace App\Http\Controllers;

use App\Http\Requests\MovimentCompteCorrentRequest;
use App\Models\Categoria;
use App\Models\CompteCorrent;
use App\Models\MovimentCompteCorrent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class MovimentCompteCorrentController extends Controller
{
    /**
     * Display a listing of movements.
     */
    public function index(Request $request): Response
    {
        // Get all comptes corrents for selector
        $comptesCorrents = CompteCorrent::orderBy('ordre')
            ->orderBy('entitat')
            ->get();

        // Get selected compte or use first one
        $compteCorrentId = $request->input('compte_corrent_id', $comptesCorrents->first()?->id);

        // Get filters
        $filters = [
            'search' => $request->input('search'),
            'categoria_id' => $request->input('categoria_id'),
            'data_inici' => $request->input('data_inici'),
            'data_fi' => $request->input('data_fi'),
            'tipus' => $request->input('tipus'), // 'ingressos', 'despeses', or null for all
        ];

        // Base query
        $query = MovimentCompteCorrent::with(['categoria', 'compteCorrent'])
            ->where('compte_corrent_id', $compteCorrentId);

        // Apply filters
        if ($filters['search']) {
            $query->where('concepte', 'LIKE', '%' . $filters['search'] . '%');
        }

        if ($filters['categoria_id']) {
            $query->where('categoria_id', $filters['categoria_id']);
        }

        if ($filters['data_inici']) {
            $query->whereDate('data_moviment', '>=', $filters['data_inici']);
        }

        if ($filters['data_fi']) {
            $query->whereDate('data_moviment', '<=', $filters['data_fi']);
        }

        if ($filters['tipus'] === 'ingressos') {
            $query->where('import', '>', 0);
        } elseif ($filters['tipus'] === 'despeses') {
            $query->where('import', '<', 0);
        }

        // Order by date descending (most recent first)
        $moviments = $query->orderBy('data_moviment', 'desc')
            ->orderBy('id', 'desc')
            ->paginate(50)
            ->withQueryString();

        // Get categories for the selected compte for the filter dropdown
        $categories = $compteCorrentId
            ? Categoria::where('compte_corrent_id', $compteCorrentId)
                ->orderBy('nom')
                ->get()
            : [];

        // Calculate statistics
        $stats = [
            'total_ingressos' => MovimentCompteCorrent::where('compte_corrent_id', $compteCorrentId)
                ->where('import', '>', 0)
                ->sum('import'),
            'total_despeses' => MovimentCompteCorrent::where('compte_corrent_id', $compteCorrentId)
                ->where('import', '<', 0)
                ->sum('import'),
            'saldo_actual' => MovimentCompteCorrent::where('compte_corrent_id', $compteCorrentId)
                ->orderBy('data_moviment', 'desc')
                ->orderBy('id', 'desc')
                ->value('saldo_posterior'),
        ];

        return Inertia::render('Moviments/Index', [
            'comptesCorrents' => $comptesCorrents,
            'selectedCompteCorrentId' => $compteCorrentId,
            'moviments' => $moviments,
            'categories' => $categories,
            'filters' => $filters,
            'stats' => $stats,
        ]);
    }

    /**
     * Store a newly created movement.
     */
    public function store(MovimentCompteCorrentRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        DB::beginTransaction();
        try {
            // Generate hash
            $hash = hash('sha256',
                $validated['data_moviment'] . '|' .
                $validated['import'] . '|' .
                $validated['compte_corrent_id']
            );

            $validated['hash_moviment'] = $hash;

            MovimentCompteCorrent::create($validated);

            DB::commit();

            return redirect()->back()->with('success', 'Moviment creat correctament.');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()
                ->withErrors(['error' => 'Error creant el moviment: ' . $e->getMessage()])
                ->withInput();
        }
    }

    /**
     * Update the specified movement.
     */
    public function update(MovimentCompteCorrentRequest $request, MovimentCompteCorrent $moviment): RedirectResponse
    {
        $validated = $request->validated();

        DB::beginTransaction();
        try {
            // Recalculate hash if critical fields changed
            if (
                $validated['data_moviment'] !== $moviment->data_moviment->format('Y-m-d') ||
                $validated['import'] != $moviment->import ||
                $validated['compte_corrent_id'] != $moviment->compte_corrent_id
            ) {
                $hash = hash('sha256',
                    $validated['data_moviment'] . '|' .
                    $validated['import'] . '|' .
                    $validated['compte_corrent_id']
                );
                $validated['hash_moviment'] = $hash;
            }

            $moviment->update($validated);

            DB::commit();

            return redirect()->back()->with('success', 'Moviment actualitzat correctament.');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()
                ->withErrors(['error' => 'Error actualitzant el moviment: ' . $e->getMessage()])
                ->withInput();
        }
    }

    /**
     * Remove the specified movement.
     */
    public function destroy(MovimentCompteCorrent $moviment): RedirectResponse
    {
        try {
            $moviment->delete();
            return redirect()->back()->with('success', 'Moviment eliminat correctament.');
        } catch (\Exception $e) {
            return redirect()->back()
                ->withErrors(['error' => 'Error eliminant el moviment: ' . $e->getMessage()]);
        }
    }
}
