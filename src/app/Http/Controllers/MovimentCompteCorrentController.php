<?php

namespace App\Http\Controllers;

use App\Http\Requests\MovimentCompteCorrentRequest;
use App\Models\Categoria;
use App\Models\CompteCorrent;
use App\Models\MovimentCompteCorrent;
use App\Models\MovimentConcepte;
use App\Services\SaldoRecalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class MovimentCompteCorrentController extends Controller
{
    public function __construct(
        private SaldoRecalculationService $saldoService
    ) {}

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
        $query = MovimentCompteCorrent::with(['categoria', 'compteCorrent', 'concepte'])
            ->where('compte_corrent_id', $compteCorrentId);

        // Apply filters
        if ($filters['search']) {
            $query->whereHas('concepte', function ($q) use ($filters) {
                $q->where('concepte', 'LIKE', '%' . $filters['search'] . '%');
            });
        }

        if ($filters['categoria_id']) {
            if ($filters['categoria_id'] === 'none') {
                $query->whereNull('categoria_id');
            } else {
                $query->where('categoria_id', $filters['categoria_id']);
            }
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
            ->withQueryString()
            ->through(function ($moviment) {
                // Transform concepte relation to string for frontend
                return [
                    'id' => $moviment->id,
                    'compte_corrent_id' => $moviment->compte_corrent_id,
                    'data_moviment' => $moviment->data_moviment->format('Y-m-d'),
                    'concepte' => $moviment->concepte?->concepte,
                    'concepte_original' => $moviment->concepte_original,
                    'notes' => $moviment->notes,
                    'import' => $moviment->import,
                    'saldo_posterior' => $moviment->saldo_posterior,
                    'categoria_id' => $moviment->categoria_id,
                    'hash_moviment' => $moviment->hash,
                    'created_at' => $moviment->created_at,
                    'updated_at' => $moviment->updated_at,
                    'categoria' => $moviment->categoria,
                    'compte_corrent' => $moviment->compteCorrent,
                ];
            });

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
            // Find or create concept
            $concepteText = $validated['concepte'];
            $concepteModel = MovimentConcepte::findOrCreateByConcepte($concepteText);

            // Generate hash
            $hash = hash('sha256',
                $validated['data_moviment'] . '|' .
                $validated['import'] . '|' .
                $validated['compte_corrent_id']
            );

            // Replace concepte text with concepte_id
            unset($validated['concepte']);
            $validated['concepte_id'] = $concepteModel->id;
            $validated['concepte_original'] = $concepteText;
            $validated['hash'] = $hash;

            $moviment = MovimentCompteCorrent::create($validated);

            $this->saldoService->recalcularDesde(
                $moviment->compte_corrent_id,
                $moviment->data_moviment->format('Y-m-d'),
                $moviment->id
            );

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

        // Guardar valors anteriors per recalcular saldos
        $dataAnterior = $moviment->data_moviment->format('Y-m-d');
        $compteAnteriorId = $moviment->compte_corrent_id;

        DB::beginTransaction();
        try {
            // Find or create concept
            $concepteText = $validated['concepte'];
            $concepteModel = MovimentConcepte::findOrCreateByConcepte($concepteText);

            // Replace concepte text with concepte_id
            unset($validated['concepte']);
            $validated['concepte_id'] = $concepteModel->id;

            // Update concepte_original if it changed
            if ($concepteText !== $moviment->concepte_original) {
                $validated['concepte_original'] = $concepteText;
            }

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
                $validated['hash'] = $hash;
            }

            $moviment->update($validated);

            $this->saldoService->recalcularPerUpdate($moviment, $dataAnterior, $compteAnteriorId);

            DB::commit();

            if ($request->wantsJson()) {
                $moviment->load('categoria');
                return response()->json([
                    'id'               => $moviment->id,
                    'compte_corrent_id' => $moviment->compte_corrent_id,
                    'data_moviment'    => $moviment->data_moviment->toDateString(),
                    'concepte'         => $concepteText,
                    'notes'            => $moviment->notes,
                    'import'           => $moviment->import,
                    'saldo_posterior'  => $moviment->saldo_posterior,
                    'exclou_lloguer'   => $moviment->exclou_lloguer,
                    'categoria_id'     => $moviment->categoria_id,
                    'categoria_nom'    => $moviment->categoria?->nom,
                ]);
            }

            return redirect()->back()->with('success', 'Moviment actualitzat correctament.');
        } catch (\Exception $e) {
            DB::rollBack();
            if ($request->wantsJson()) {
                return response()->json(['errors' => ['error' => $e->getMessage()]], 422);
            }
            return redirect()->back()
                ->withErrors(['error' => 'Error actualitzant el moviment: ' . $e->getMessage()])
                ->withInput();
        }
    }

    /**
     * Duplicate one or more movements with today's date.
     */
    public function duplicar(Request $request): RedirectResponse
    {
        $ids = $request->input('ids', []);
        if (empty($ids) || !is_array($ids)) {
            return redirect()->back()->withErrors(['error' => 'No s\'han indicat moviments a duplicar.']);
        }

        $avui = now()->toDateString();

        DB::beginTransaction();
        try {
            $moviments = MovimentCompteCorrent::whereIn('id', $ids)->get();

            // Recollim els comptes afectats per recalcular un cop al final
            $comptesAfectats = [];

            foreach ($moviments as $moviment) {
                $hash = hash('sha256',
                    $avui . '|' .
                    $moviment->import . '|' .
                    $moviment->compte_corrent_id . '|' .
                    $moviment->id
                );

                MovimentCompteCorrent::create([
                    'compte_corrent_id' => $moviment->compte_corrent_id,
                    'data_moviment'     => $avui,
                    'concepte_id'       => $moviment->concepte_id,
                    'concepte_original' => $moviment->concepte_original,
                    'import'            => $moviment->import,
                    'saldo_posterior'   => null,
                    'notes'             => $moviment->notes,
                    'categoria_id'      => $moviment->categoria_id,
                    'conciliat'         => false,
                    'exclou_lloguer'    => $moviment->exclou_lloguer,
                    'hash'              => $hash,
                ]);

                $comptesAfectats[$moviment->compte_corrent_id] = true;
            }

            DB::commit();

            // Recalcular saldos des d'avui per a cada compte afectat
            foreach (array_keys($comptesAfectats) as $compteId) {
                $this->saldoService->recalcularDesde($compteId, $avui);
            }

            $n = count($moviments);
            $msg = $n === 1 ? 'Moviment duplicat correctament.' : "{$n} moviments duplicats correctament.";
            return redirect()->back()->with('success', $msg);
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->withErrors(['error' => 'Error duplicant els moviments: ' . $e->getMessage()]);
        }
    }

    /**
     * Toggle exclou_lloguer flag on a movement.
     */
    public function toggleExclou(MovimentCompteCorrent $moviment): JsonResponse
    {
        $moviment->update(['exclou_lloguer' => !$moviment->exclou_lloguer]);

        return response()->json(['exclou_lloguer' => $moviment->exclou_lloguer]);
    }

    /**
     * Remove the specified movement.
     */
    public function destroy(MovimentCompteCorrent $moviment): RedirectResponse
    {
        try {
            $compteCorrentId = $moviment->compte_corrent_id;
            $dataMoviment = $moviment->data_moviment->format('Y-m-d');

            $moviment->delete();

            $this->saldoService->recalcularDesde($compteCorrentId, $dataMoviment);

            return redirect()->back()->with('success', 'Moviment eliminat correctament.');
        } catch (\Exception $e) {
            return redirect()->back()
                ->withErrors(['error' => 'Error eliminant el moviment: ' . $e->getMessage()]);
        }
    }
}
