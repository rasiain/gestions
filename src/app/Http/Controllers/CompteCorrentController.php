<?php

namespace App\Http\Controllers;

use App\Http\Requests\CompteCorrentRequest;
use App\Models\Categoria;
use App\Models\CompteCorrent;
use App\Models\ContracteFons;
use App\Models\Entitat;
use App\Models\Lloguer;
use App\Models\MovimentCompteCorrent;
use App\Models\Persona;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CompteCorrentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $lloguersPerCompte = Lloguer::select('compte_corrent_id', 'nom', 'acronim')
            ->get()
            ->keyBy('compte_corrent_id');

        // Els comptes de fons d'inversió tenen un contracte amb el fons corresponent
        $contractesFonsPerCompte = ContracteFons::with('fons:id,nom')
            ->get()
            ->keyBy('compte_corrent_id');

        $comptesCorrents = CompteCorrent::with(['titulars', 'entitatRelacio'])
            ->orderBy('ordre')
            ->get()
            ->map(function ($compte) use ($lloguersPerCompte, $contractesFonsPerCompte) {
                $compte->saldo_actual = $compte->saldo_actual;
                $lloguer = $lloguersPerCompte->get($compte->id);
                $compte->lloguer_nom = $lloguer?->nom;
                $compte->lloguer_acronim = $lloguer?->acronim;
                $compte->fons_nom = $contractesFonsPerCompte->get($compte->id)?->fons?->nom;
                return $compte;
            });

        $titulars = Persona::orderBy('cognoms')
            ->orderBy('nom')
            ->get();

        $entitats = Entitat::orderBy('nom')->get();

        return Inertia::render('ComptesCorrents/Index', [
            'comptesCorrents' => $comptesCorrents,
            'titulars'        => $titulars,
            'entitats'        => $entitats,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CompteCorrentRequest $request)
    {
        $validated = $request->validated();
        $entitat_ids = $validated['titular_ids'] ?? [];
        unset($validated['titular_ids'], $validated['entitat_nova_nom']);

        if ($request->filled('entitat_nova_nom')) {
            $entitat = Entitat::firstOrCreate(['nom' => trim($request->input('entitat_nova_nom'))]);
            $validated['entitat_id'] = $entitat->id;
        }

        $compteCorrent = CompteCorrent::create($validated);
        $compteCorrent->titulars()->sync($entitat_ids);

        return redirect()->route('comptes-corrents.index')
            ->with('success', 'Compte corrent creat correctament.');
    }

    public function update(CompteCorrentRequest $request, CompteCorrent $compteCorrent)
    {
        $validated = $request->validated();
        $titular_ids = $validated['titular_ids'] ?? [];
        unset($validated['titular_ids'], $validated['entitat_nova_nom']);

        if ($request->filled('entitat_nova_nom')) {
            $entitat = Entitat::firstOrCreate(['nom' => trim($request->input('entitat_nova_nom'))]);
            $validated['entitat_id'] = $entitat->id;
        }

        $compteCorrent->update($validated);
        $compteCorrent->titulars()->sync($titular_ids);

        return redirect()->route('comptes-corrents.index')
            ->with('success', 'Compte corrent actualitzat correctament.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(CompteCorrent $compteCorrent)
    {
        $compteCorrent->delete();

        return redirect()->route('comptes-corrents.index')
            ->with('success', 'Compte corrent eliminat correctament.');
    }

    /**
     * Retorna el balanc (ingressos, despeses, net) per periodes i categories.
     */
    public function balanc(Request $request, CompteCorrent $compteCorrent): JsonResponse
    {
        $vista = $request->input('vista', 'mensual');
        $dataInici = $request->input('data_inici', date('Y-01-01'));
        $dataFi = $request->input('data_fi', date('Y-m-d'));

        $etiquetesMesos = ['Gen', 'Feb', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Oct', 'Nov', 'Des'];

        if ($vista === 'mensual') {
            $resultats = MovimentCompteCorrent::where('compte_corrent_id', $compteCorrent->id)
                ->whereBetween('data_moviment', [$dataInici, $dataFi])
                ->selectRaw("
                    strftime('%Y-%m', data_moviment) as periode,
                    SUM(CASE WHEN import > 0 THEN import ELSE 0 END) as ingressos,
                    SUM(CASE WHEN import < 0 THEN import ELSE 0 END) as despeses,
                    SUM(import) as net
                ")
                ->groupBy('periode')
                ->orderBy('periode')
                ->get()
                ->keyBy('periode');

            $startY = (int) substr($dataInici, 0, 4);
            $startM = (int) substr($dataInici, 5, 2);
            $endY   = (int) substr($dataFi, 0, 4);
            $endM   = (int) substr($dataFi, 5, 2);
            $multipleAnys = $startY !== $endY;

            $periodes = [];
            $y = $startY;
            $m = $startM;
            while ($y < $endY || ($y === $endY && $m <= $endM)) {
                $clau = sprintf('%04d-%02d', $y, $m);
                $fila = $resultats->get($clau);
                $etiqueta = $etiquetesMesos[$m - 1];
                if ($multipleAnys) {
                    $etiqueta .= ' ' . substr((string) $y, 2);
                }
                $periodes[] = [
                    'etiqueta' => $etiqueta,
                    'ingressos' => $fila ? (float) $fila->ingressos : 0.0,
                    'despeses'  => $fila ? (float) $fila->despeses  : 0.0,
                    'net'       => $fila ? (float) $fila->net       : 0.0,
                ];
                $m++;
                if ($m > 12) {
                    $m = 1;
                    $y++;
                }
            }
        } else {
            $resultats = MovimentCompteCorrent::where('compte_corrent_id', $compteCorrent->id)
                ->whereBetween('data_moviment', [$dataInici, $dataFi])
                ->selectRaw("
                    strftime('%Y', data_moviment) as any,
                    SUM(CASE WHEN import > 0 THEN import ELSE 0 END) as ingressos,
                    SUM(CASE WHEN import < 0 THEN import ELSE 0 END) as despeses,
                    SUM(import) as net
                ")
                ->groupBy('any')
                ->orderBy('any')
                ->get()
                ->keyBy('any');

            $startY = (int) substr($dataInici, 0, 4);
            $endY   = (int) substr($dataFi, 0, 4);

            $periodes = [];
            for ($y = $startY; $y <= $endY; $y++) {
                $fila = $resultats->get((string) $y);
                $periodes[] = [
                    'etiqueta' => (string) $y,
                    'ingressos' => $fila ? (float) $fila->ingressos : 0.0,
                    'despeses'  => $fila ? (float) $fila->despeses  : 0.0,
                    'net'       => $fila ? (float) $fila->net       : 0.0,
                ];
            }
        }

        $totals = [
            'ingressos' => array_sum(array_column($periodes, 'ingressos')),
            'despeses'  => array_sum(array_column($periodes, 'despeses')),
            'net'       => array_sum(array_column($periodes, 'net')),
        ];

        $totsCategories = Categoria::where('compte_corrent_id', $compteCorrent->id)
            ->orderBy('nom')
            ->get();

        $arrels = $totsCategories->filter(fn($c) => is_null($c->categoria_pare_id))->values();

        $categories = $arrels->map(fn($cat) => $this->calcularCategoria(
            $cat,
            $totsCategories,
            $compteCorrent->id,
            $dataInici,
            $dataFi
        ))->sortBy('net')->values()->toArray();

        return response()->json([
            'compte' => [
                'id'  => $compteCorrent->id,
                'nom' => $compteCorrent->nom ?? $compteCorrent->compte_corrent,
            ],
            'vista'      => $vista,
            'data_inici' => $dataInici,
            'data_fi'    => $dataFi,
            'periodes'   => $periodes,
            'totals'     => $totals,
            'categories' => $categories,
        ]);
    }

    /**
     * Calcula recursivament ingressos/despeses/net per a una categoria i els seus fills.
     */
    private function calcularCategoria(
        Categoria $categoria,
        $totes,
        int $compteCorrentId,
        string $dataInici,
        string $dataFi
    ): array {
        $ids = $this->collectDescendantIds($categoria->id);

        $fila = MovimentCompteCorrent::where('compte_corrent_id', $compteCorrentId)
            ->whereIn('categoria_id', $ids)
            ->whereBetween('data_moviment', [$dataInici, $dataFi])
            ->selectRaw("
                COALESCE(SUM(CASE WHEN import > 0 THEN import ELSE 0 END), 0) as ingressos,
                COALESCE(SUM(CASE WHEN import < 0 THEN import ELSE 0 END), 0) as despeses,
                COALESCE(SUM(import), 0) as net
            ")->first();

        $fills = $totes->filter(fn($c) => $c->categoria_pare_id === $categoria->id)->values();

        $fillsCalculats = $fills->map(fn($fill) => $this->calcularCategoria(
            $fill,
            $totes,
            $compteCorrentId,
            $dataInici,
            $dataFi
        ))->sortBy('net')->values()->toArray();

        return [
            'id'        => $categoria->id,
            'nom'       => $categoria->nom,
            'ingressos' => (float) $fila->ingressos,
            'despeses'  => (float) $fila->despeses,
            'net'       => (float) $fila->net,
            'fills'     => $fillsCalculats,
        ];
    }

    /**
     * Recull recursivament tots els IDs descendents d'una categoria.
     */
    private function collectDescendantIds(int $categoriaId): array
    {
        $ids = [$categoriaId];
        foreach (Categoria::where('categoria_pare_id', $categoriaId)->pluck('id') as $fillId) {
            $ids = array_merge($ids, $this->collectDescendantIds($fillId));
        }
        return $ids;
    }
}
