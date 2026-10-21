<?php

namespace App\Http\Controllers;

use App\Models\Ruta;
use App\Models\SortidaBici;
use App\Models\Vehicle;
use App\Services\RutesCanoniquesService;
use App\Support\Polilinia;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * El catàleg de rutes canòniques i el detall de cada una. El catàleg porta les rutes de tots els
 * nivells d'un cop: el selector de tolerància canvia de nivell a la pantalla, sense tornar aquí.
 */
class RutaController extends Controller
{
    /** Per a la llista i el mapa general n'hi ha prou amb ~50 m: centenars de rutes alhora. */
    private const TOLERANCIA_CATALEG = 0.0005;

    public function index()
    {
        $rutes = DB::table('g_rutes as r')
            ->join('g_sortides_bici as c', 'c.id', '=', 'r.canonica_id')
            ->select('r.id', 'r.nivell', 'r.sortides', 'r.nom', 'r.canonica_id', 'c.data_local', 'c.distancia_m',
                'c.desnivell_pos_m', 'c.recorregut_simplificat')
            ->selectRaw("json_extract(c.dades_originals, '$.locationName') as lloc")
            ->get();

        // De cada ruta: l'última vegada i amb quines bicis
        $grups = DB::table('g_sortida_ruta as sr')
            ->join('g_sortides_bici as s', 's.id', '=', 'sr.sortida_id')
            ->groupBy('sr.ruta_id')
            ->selectRaw('sr.ruta_id, max(s.data_local) as darrera, min(s.data_local) as primera, group_concat(distinct s.vehicle_id) as bicis')
            ->get()
            ->keyBy('ruta_id');

        // El traçat de cada canònic, un sol cop encara que sigui canònic a diversos nivells
        $tracats = [];
        foreach ($rutes as $r) {
            $tracats[$r->canonica_id] ??= $r->recorregut_simplificat
                ? Polilinia::codifica(Polilinia::simplifica(Polilinia::decodifica($r->recorregut_simplificat), self::TOLERANCIA_CATALEG))
                : null;
        }

        return Inertia::render('Bicis/Rutes', [
            'nivells' => collect(RutesCanoniquesService::NIVELLS)->map(fn ($llindar, $clau) => [
                'clau' => $clau, 'llindar' => $llindar,
            ])->values(),
            'rutes' => $rutes->map(fn ($r) => [
                'id'          => $r->id,
                'nivell'      => $r->nivell,
                'nom'         => $r->nom ?? $this->nomAutomatic($r->lloc, $r->distancia_m, $r->desnivell_pos_m),
                'sortides'    => $r->sortides,
                'canonica_id' => $r->canonica_id,
                'km'          => round($r->distancia_m / 1000, 1),
                'desnivell'   => (int) round($r->desnivell_pos_m),
                'primera'     => substr($grups[$r->id]->primera, 0, 10),
                'darrera'     => substr($grups[$r->id]->darrera, 0, 10),
                'bicis'       => array_map('intval', explode(',', $grups[$r->id]->bicis)),
            ])->values(),
            'tracats' => $tracats,
            'bicis'   => Vehicle::where('tipus', 'bici')->orderBy('nom')->get(['id', 'nom', 'modalitat']),
        ]);
    }

    public function show(Ruta $ruta)
    {
        $ruta->load(['canonica.vehicle:id,nom']);
        $membres = $ruta->sortidesDelGrup()->with('vehicle:id,nom')->orderByDesc('inici')->get();
        $c = $ruta->canonica;

        // La mateixa sortida canònica, als altres nivells: per veure com creix o s'aprima el grup
        $altresNivells = DB::table('g_sortida_ruta as sr')
            ->join('g_rutes as r', 'r.id', '=', 'sr.ruta_id')
            ->where('sr.sortida_id', $c->id)
            ->get(['r.id', 'r.nivell', 'r.sortides'])
            ->sortBy(fn ($r) => array_search($r->nivell, array_keys(RutesCanoniquesService::NIVELLS)))
            ->values();

        return Inertia::render('Bicis/Ruta', [
            'ruta' => [
                'id'       => $ruta->id,
                'nivell'   => $ruta->nivell,
                'llindar'  => RutesCanoniquesService::NIVELLS[$ruta->nivell] ?? null,
                'nom'      => $ruta->nom ?? $this->nomAutomatic($c->dades_originals['locationName'] ?? null, $c->distancia_m, $c->desnivell_pos_m),
                'sortides' => $ruta->sortides,
            ],
            'canonica' => [
                'id'        => $c->id,
                'punts'     => $c->punts,
            ],
            'membres' => $membres->map(fn (SortidaBici $s) => [
                'id'        => $s->id,
                'data'      => $s->data_local->toDateString(),
                'nom'       => $s->nom,
                'bici'      => $s->vehicle?->nom,
                'km'        => $s->distancia_m !== null ? round($s->distancia_m / 1000, 1) : null,
                'desnivell' => $s->desnivell_pos_m !== null ? (int) round($s->desnivell_pos_m) : null,
                'temps_s'   => $s->temps_moviment_s ?? $s->temps_s,
                'semblanca' => (float) $s->pivot->semblanca,
                'canonica'  => $s->id === $c->id,
                'tracat'    => $s->recorregut_simplificat,
            ])->values(),
            'altres_nivells' => $altresNivells,
        ]);
    }

    /** Fins que l'usuari no posi noms: on comença, quants km i quant puja. */
    private function nomAutomatic(?string $lloc, $distancia, $desnivell): string
    {
        return trim(($lloc ? "{$lloc} · " : '') . number_format(($distancia ?? 0) / 1000, 0, ',', '.') . ' km · '
            . number_format((float) ($desnivell ?? 0), 0, ',', '.') . ' m');
    }
}
