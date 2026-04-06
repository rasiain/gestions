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
        $compteCorrentId = $request->integer('compte_corrent_id') ?: $comptesCorrents->first()?->id;

        // Get filters
        $ordre = in_array($request->input('ordre'), ['asc', 'desc']) ? $request->input('ordre') : 'desc';
        $filters = [
            'search' => $request->input('search'),
            'categoria_id' => $request->input('categoria_id'),
            'data_inici' => $request->input('data_inici'),
            'data_fi' => $request->input('data_fi'),
            'tipus' => $request->input('tipus'), // 'ingressos', 'despeses', or null for all
            'ordre' => $ordre,
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

        $moviments = $query->orderBy('data_moviment', $ordre)
            ->orderBy('id', $ordre)
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
     * Bulk edit multiple movements (partial update: only apply provided fields).
     */
    public function bulkEdit(Request $request): JsonResponse
    {
        $request->validate([
            'moviment_ids'  => ['required', 'array', 'min:1'],
            'moviment_ids.*' => ['integer'],
            'concepte'      => ['nullable', 'string', 'max:255'],
            'notes'         => ['nullable', 'string', 'max:500'],
            'categoria_id'  => ['nullable', 'integer', 'exists:g_categories,id'],
        ]);

        $movimentIds = $request->input('moviment_ids');
        $hasConcepte   = $request->filled('concepte');
        $hasNotes      = $request->has('notes');
        $hasCategoria  = $request->has('categoria_id');

        if (!$hasConcepte && !$hasNotes && !$hasCategoria) {
            return response()->json(['updated' => 0]);
        }

        $moviments = MovimentCompteCorrent::whereIn('id', $movimentIds)
            ->where('exclou_lloguer', false)
            ->get();

        DB::beginTransaction();
        try {
            foreach ($moviments as $moviment) {
                $fields = [];

                if ($hasConcepte) {
                    $concepteModel = MovimentConcepte::findOrCreateByConcepte($request->input('concepte'));
                    $fields['concepte_id'] = $concepteModel->id;
                }

                if ($hasNotes) {
                    $fields['notes'] = $request->input('notes');
                }

                if ($hasCategoria) {
                    $fields['categoria_id'] = $request->input('categoria_id');
                }

                if (!empty($fields)) {
                    $moviment->update($fields);
                }
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }

        return response()->json(['updated' => $moviments->count()]);
    }

    /**
     * Verify saldo consistency for filtered movements.
     * Returns discrepancies between stored saldo_posterior and the one calculated
     * by applying each import sequentially from the oldest movement.
     */
    public function verificaSaldos(Request $request): JsonResponse
    {
        $compteCorrentId = $request->integer('compte_corrent_id');
        if (!$compteCorrentId) {
            return response()->json(['error' => 'compte_corrent_id és obligatori.'], 422);
        }

        $query = MovimentCompteCorrent::with('concepte')
            ->where('compte_corrent_id', $compteCorrentId);

        if ($request->filled('search')) {
            $query->whereHas('concepte', fn($q) => $q->where('concepte', 'LIKE', '%' . $request->input('search') . '%'));
        }
        if ($request->filled('categoria_id')) {
            $v = $request->input('categoria_id');
            $v === 'none' ? $query->whereNull('categoria_id') : $query->where('categoria_id', $v);
        }
        if ($request->filled('data_inici')) {
            $query->whereDate('data_moviment', '>=', $request->input('data_inici'));
        }
        if ($request->filled('data_fi')) {
            $query->whereDate('data_moviment', '<=', $request->input('data_fi'));
        }
        if ($request->input('tipus') === 'ingressos') {
            $query->where('import', '>', 0);
        } elseif ($request->input('tipus') === 'despeses') {
            $query->where('import', '<', 0);
        }

        $moviments = $query->orderBy('data_moviment', 'asc')->orderBy('id', 'asc')->get();

        if ($moviments->isEmpty()) {
            return response()->json(['total' => 0, 'errors' => [], 'sense_saldo' => []]);
        }

        $errors = [];
        $senseSaldo = [];
        $saldoActual = null;

        foreach ($moviments as $i => $mov) {
            if ($mov->saldo_posterior === null) {
                $senseSaldo[] = [
                    'id'            => $mov->id,
                    'data_moviment' => $mov->data_moviment->format('Y-m-d'),
                    'concepte'      => $mov->concepte?->concepte,
                    'import'        => (float) $mov->import,
                ];
                // No podem continuar la cadena si no hi ha saldo
                $saldoActual = null;
                continue;
            }

            if ($i === 0 || $saldoActual === null) {
                // Punt de partida: confiem en el primer saldo disponible
                $saldoActual = (float) $mov->saldo_posterior;
                continue;
            }

            $saldoEsperat = round($saldoActual + (float) $mov->import, 2);
            $saldoDesat   = round((float) $mov->saldo_posterior, 2);

            if (abs($saldoEsperat - $saldoDesat) > 0.001) {
                $errors[] = [
                    'id'             => $mov->id,
                    'data_moviment'  => $mov->data_moviment->format('Y-m-d'),
                    'concepte'       => $mov->concepte?->concepte,
                    'import'         => (float) $mov->import,
                    'saldo_esperat'  => $saldoEsperat,
                    'saldo_desat'    => $saldoDesat,
                    'diferencia'     => round($saldoDesat - $saldoEsperat, 2),
                ];
            }

            $saldoActual = (float) $mov->saldo_posterior;
        }

        return response()->json([
            'total'       => $moviments->count(),
            'errors'      => $errors,
            'sense_saldo' => $senseSaldo,
        ]);
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
