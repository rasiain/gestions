<?php

namespace App\Http\Controllers;

use App\Http\Requests\AltitudPreferenciaRequest;
use App\Http\Requests\SortidaMaterialRequest;
use App\Models\MaterialEsportiu;
use App\Models\Preferencia;
use App\Models\SortidaBici;
use App\Models\Vehicle;
use App\Models\VehicleDespesa;
use App\Services\AltitudTerrenyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * La fitxa d'una bici: el que diuen les seves sortides. Va per JSON i no amb la pàgina de
 * bicis perquè són milers de sortides i només es miren quan s'obre la fitxa.
 */
class SortidaBiciController extends Controller
{
    /** Els rodets: la distància és virtual i es compta a part. */
    private const INDOOR = 'indoor_cycling';

    /**
     * Resum per any i per mes, i els components amb els seus quilòmetres. El desnivell, amb l'altitud
     * de cada sortida o amb el mètode demanat (`?altitud=`), vegeu AltitudTerrenyService::desnivellsTriats().
     */
    public function fitxa(Vehicle $vehicle, Request $request): JsonResponse
    {
        abort_unless($vehicle->tipus === 'bici', 404);

        return response()->json($this->resum([$vehicle->id], $request) + ['components' => $this->components($vehicle)]);
    }

    /**
     * La fitxa de totes les bicis juntes: el mateix resum que la d'una, sumat, i a més els km de cada bici
     * per any (per a la gràfica apilada). Sense components, que són de cada bici.
     */
    public function fitxaTotes(Request $request): JsonResponse
    {
        $bicis = Vehicle::where('tipus', 'bici')->orderBy('id')->get(['id', 'nom']);
        $perBici = SortidaBici::whereIn('vehicle_id', $bicis->pluck('id'))
            ->selectRaw("strftime('%Y', data_local) as any, vehicle_id")
            ->selectRaw('round(sum(distancia_m) / 1000.0, 1) as km')
            ->selectRaw('coalesce(sum(calories), 0) as calories')
            ->selectRaw('sum(coalesce(nullif(temps_moviment_s, 0), temps_s)) / 3600.0 as hores')
            ->groupBy('any', 'vehicle_id')
            ->get();

        return response()->json($this->resum($bicis->pluck('id')->all(), $request) + [
            'components' => [],
            'bicis'      => $bicis->map(fn (Vehicle $v) => ['id' => $v->id, 'nom' => $v->nom])->values(),
            'per_bici'   => $perBici->map(fn ($f) => ['any' => (int) $f->any, 'vehicle_id' => $f->vehicle_id, 'km' => (float) $f->km, 'calories' => (int) $f->calories, 'hores' => round((float) $f->hores, 1)])->values(),
        ]);
    }

    /**
     * El resum per any, per mes i per tipus d'unes quantes bicis (una sola, o totes). El desnivell, amb
     * l'altitud de cada sortida o amb el mètode demanat (`?altitud=`).
     *
     * @param  array<int, int>  $vehicles
     */
    private function resum(array $vehicles, Request $request): array
    {
        $base = SortidaBici::whereIn('vehicle_id', $vehicles);

        $perAny = (clone $base)
            ->selectRaw("strftime('%Y', data_local) as any")
            ->selectRaw('count(*) as sortides')
            ->selectRaw('sum(distancia_m) / 1000.0 as km')
            ->selectRaw('sum(case when tipus_activitat = ? then distancia_m else 0 end) / 1000.0 as km_indoor', [self::INDOOR])
            ->selectRaw('sum(coalesce(nullif(temps_moviment_s, 0), temps_s)) / 3600.0 as hores')
            ->selectRaw('sum(case when tipus_activitat = ? then coalesce(nullif(temps_moviment_s, 0), temps_s) else 0 end) / 3600.0 as hores_indoor', [self::INDOOR])
            ->selectRaw('sum(calories) as calories')
            ->selectRaw('sum(case when tipus_activitat = ? then calories else 0 end) as calories_indoor', [self::INDOOR])
            ->groupBy('any')
            ->orderByDesc('any')
            ->get()
            ->keyBy('any');

        $despeses = VehicleDespesa::whereIn('vehicle_id', $vehicles)->get()
            ->groupBy(fn (VehicleDespesa $d) => $d->data->format('Y'))
            ->map(fn ($grup) => round((float) $grup->sum(fn (VehicleDespesa $d) => (float) $d->import), 2));

        $anys = $perAny->keys()->merge($despeses->keys())->unique()->sortDesc()->values();

        $sortides = (clone $base)->get(['id', 'data_local', 'desnivell_pos_m', 'altitud_metode', 'altitud_font']);
        $triats = app(AltitudTerrenyService::class)->desnivellsTriats($sortides, AltitudTerrenyService::metodeDemanat($request->query('altitud')));
        $desnivell = $sortides->groupBy(fn (SortidaBici $s) => $s->data_local->format('Y'))
            ->map(fn ($grup) => $grup->sum(fn (SortidaBici $s) => $triats[$s->id]['pujada'] ?? 0));

        return [
            'per_any' => $anys->map(function ($any) use ($perAny, $despeses, $desnivell) {
                $f = $perAny[$any] ?? null;
                $km = $f ? round((float) $f->km, 1) : 0.0;
                $cost = $despeses[$any] ?? 0.0;

                return [
                    'any'       => (int) $any,
                    'sortides'  => $f ? (int) $f->sortides : 0,
                    'km'        => $km,
                    'km_indoor' => $f ? round((float) $f->km_indoor, 1) : 0.0,
                    'hores'     => $f ? round((float) $f->hores, 1) : 0.0,
                    'hores_indoor' => $f ? round((float) $f->hores_indoor, 1) : 0.0,
                    'calories'  => $f ? (int) $f->calories : 0,
                    'calories_indoor' => $f ? (int) $f->calories_indoor : 0,
                    'desnivell' => (int) round($desnivell[$any] ?? 0),
                    'despeses'  => $cost,
                    'cost_km'   => $km > 0 && $cost > 0 ? round($cost / $km, 3) : null,
                ];
            })->values(),
            'per_mes'    => (clone $base)
                ->selectRaw("strftime('%Y-%m', data_local) as mes")
                ->selectRaw('count(*) as sortides')
                ->selectRaw('round(sum(case when tipus_activitat = ? then 0 else distancia_m end) / 1000.0, 1) as km', [self::INDOOR])
                ->selectRaw('round(sum(case when tipus_activitat = ? then distancia_m else 0 end) / 1000.0, 1) as km_indoor', [self::INDOOR])
                ->selectRaw('coalesce(sum(case when tipus_activitat = ? then 0 else calories end), 0) as calories', [self::INDOOR])
                ->selectRaw('coalesce(sum(case when tipus_activitat = ? then calories else 0 end), 0) as calories_indoor', [self::INDOOR])
                ->selectRaw('round(coalesce(sum(case when tipus_activitat = ? then 0 else coalesce(nullif(temps_moviment_s, 0), temps_s) end), 0) / 3600.0, 1) as hores', [self::INDOOR])
                ->selectRaw('round(coalesce(sum(case when tipus_activitat = ? then coalesce(nullif(temps_moviment_s, 0), temps_s) else 0 end), 0) / 3600.0, 1) as hores_indoor', [self::INDOOR])
                ->groupBy('mes')
                ->orderBy('mes')
                ->get(),
            'tipus'      => (clone $base)
                ->selectRaw('tipus_activitat as tipus, count(*) as sortides')
                ->groupBy('tipus_activitat')
                ->orderByDesc('sortides')
                ->get(),
        ];
    }

    /**
     * Els components que han anat en sortides d'aquesta bici. Els km són els de les sortides
     * on es van marcar; al costat, els de la bici entre les dates del component, que diuen si
     * hi ha sortides on no es va marcar.
     */
    private function components(Vehicle $vehicle): array
    {
        $ids = DB::table('g_sortida_material')
            ->join('g_sortides_bici', 'g_sortides_bici.id', '=', 'g_sortida_material.sortida_id')
            ->join('g_material_esportiu', 'g_material_esportiu.id', '=', 'g_sortida_material.material_id')
            ->where('g_sortides_bici.vehicle_id', $vehicle->id)
            ->where('g_material_esportiu.tipus', 'component')
            ->distinct()
            ->pluck('g_material_esportiu.id');

        // També els muntats a la bici, encara que no tinguin cap sortida (una peça nova)
        $ids = $ids->merge(MaterialEsportiu::where('muntat_a_vehicle_id', $vehicle->id)->pluck('id'))->unique();

        return MaterialEsportiu::whereIn('id', $ids)
            ->orderByRaw("estat = 'retirat'")
            ->orderByDesc('data_inici')
            ->get()
            ->map(function (MaterialEsportiu $m) use ($vehicle) {
                $marcades = DB::table('g_sortida_material')
                    ->join('g_sortides_bici', 'g_sortides_bici.id', '=', 'g_sortida_material.sortida_id')
                    ->where('g_sortida_material.material_id', $m->id)
                    ->where('g_sortides_bici.vehicle_id', $vehicle->id)
                    ->selectRaw('count(*) as sortides, sum(distancia_m) / 1000.0 as km')
                    ->first();

                $enDates = SortidaBici::where('vehicle_id', $vehicle->id)
                    ->when($m->data_inici, fn ($q) => $q->where('data_local', '>=', $m->data_inici->toDateString()))
                    ->when($m->data_fi, fn ($q) => $q->where('data_local', '<=', $m->data_fi->toDateString()))
                    ->where('tipus_activitat', '!=', self::INDOOR)
                    ->selectRaw('count(*) as sortides, sum(distancia_m) / 1000.0 as km')
                    ->first();

                return [
                    'id'                => $m->id,
                    'nom'               => $m->nom,
                    'estat'             => $m->estat,
                    'muntat'            => $m->muntat_a_vehicle_id === $vehicle->id,
                    'data_inici'        => $m->data_inici?->toDateString(),
                    'data_fi'           => $m->data_fi?->toDateString(),
                    'sortides'          => (int) $marcades->sortides,
                    'km'                => round((float) $marcades->km, 0),
                    'sortides_en_dates' => (int) $enDates->sortides,
                    'km_en_dates'       => round((float) $enDates->km, 0),
                ];
            })->all();
    }

    /** Les sortides d'un any, de la més recent a la més antiga. */
    public function index(Vehicle $vehicle, Request $request): JsonResponse
    {
        abort_unless($vehicle->tipus === 'bici', 404);

        return response()->json($this->llistaSortides([$vehicle->id], $request));
    }

    /** Les sortides d'un any de totes les bicis, amb el nom de la bici de cadascuna. */
    public function indexTotes(Request $request): JsonResponse
    {
        $bicis = Vehicle::where('tipus', 'bici')->pluck('nom', 'id');

        return response()->json($this->llistaSortides($bicis->keys()->all(), $request)
            ->map(fn ($s) => $s + ['bici' => $bicis[$s['vehicle_id']] ?? null]));
    }

    /** @param  array<int, int>  $vehicles */
    private function llistaSortides(array $vehicles, Request $request)
    {
        $any = $request->integer('any') ?: null;
        $tipus = $request->string('tipus')->toString() ?: null;

        $sortides = SortidaBici::whereIn('vehicle_id', $vehicles)
            ->when($any, fn ($q) => $q->whereYear('data_local', $any))
            ->when($tipus, fn ($q) => $q->where('tipus_activitat', $tipus))
            ->orderByDesc('inici')
            // L'hora local és al registre de l'origen: no cal portar-ne el JSON sencer
            ->select([
                'id', 'vehicle_id', 'data_local', 'nom', 'tipus_activitat', 'distancia_m', 'temps_moviment_s', 'temps_s',
                'desnivell_pos_m', 'vel_mitjana', 'fc_mitjana', 'calories', 'punts', 'desnivell_corregit', 'altitud_metode', 'altitud_font',
            ])
            ->selectRaw("json_extract(dades_originals, '$.startTimeLocal') as inici_local_ms")
            ->get();
        $triats = app(AltitudTerrenyService::class)->desnivellsTriats($sortides, AltitudTerrenyService::metodeDemanat($request->query('altitud')));

        return $sortides->map(fn (SortidaBici $s) => [
                'id'                 => $s->id,
                'vehicle_id'         => $s->vehicle_id,
                'data'               => $s->data_local->toDateString(),
                'hora'               => $s->inici_local_ms ? gmdate('H:i', (int) ($s->inici_local_ms / 1000)) : null,
                'nom'                => $s->nom,
                'tipus'              => $s->tipus_activitat,
                'km'                 => $s->distancia_m !== null ? round($s->distancia_m / 1000, 2) : null,
                'temps_s'            => $s->temps_moviment_s ?: $s->temps_s,
                'desnivell'          => $triats[$s->id]['pujada'] !== null ? (int) round($triats[$s->id]['pujada']) : null,
                'desnivell_metode'   => $triats[$s->id]['metode'],
                'kmh'                => $s->vel_mitjana !== null ? round($s->vel_mitjana * 3.6, 1) : null,
                'fc'                 => $s->fc_mitjana,
                'calories'           => $s->calories,
                'amb_recorregut'     => $s->punts > 0,
                // L'asterisc de Garmin només té sentit amb el seu desnivell
                'desnivell_corregit' => $s->desnivell_corregit && $triats[$s->id]['metode'] === 'dispositiu',
            ]);
    }

    /** La pàgina d'una sortida. Els punts es demanen a part (`punts`): una sortida llarga en té milers. */
    public function show(SortidaBici $sortida)
    {
        $sortida->load(['vehicle:id,nom,modalitat', 'material' => fn ($q) => $q->orderBy('tipus')->orderBy('nom')]);

        $germanes = SortidaBici::where('vehicle_id', $sortida->vehicle_id);
        $anterior = (clone $germanes)->where('inici', '<', $sortida->inici)->orderByDesc('inici')->value('id');
        $seguent = (clone $germanes)->where('inici', '>', $sortida->inici)->orderBy('inici')->value('id');

        $o = $sortida->dades_originals ?? [];
        $d = $sortida->resum_dispositiu ?? [];

        return Inertia::render('Bicis/Sortida', [
            'sortida' => [
                'id'                 => $sortida->id,
                'nom'                => $sortida->nom,
                'tipus'              => $sortida->tipus_activitat,
                'data'               => $sortida->data_local->toDateString(),
                // En UTC: l'hora de cada punt (per al GPX) és aquesta més els seus segons
                'inici'              => $sortida->inici->toIso8601ZuluString(),
                'hora'               => isset($o['startTimeLocal']) ? gmdate('H:i', (int) ($o['startTimeLocal'] / 1000)) : null,
                'lloc'               => $o['locationName'] ?? null,
                'punts'              => $sortida->punts,
                'desnivell_corregit' => $sortida->desnivell_corregit,
                'material_editat'    => $sortida->material_editat,
                'fitxer'             => $sortida->fitxer,
                'origen'             => $sortida->origen,
                'id_extern'          => $sortida->id_extern,
                'vehicle'            => $sortida->vehicle,
                'material'           => $sortida->material->map(fn ($m) => ['id' => $m->id, 'nom' => $m->nom, 'tipus' => $m->tipus])->values(),
            ],
            // El resum de Garmin (el de les columnes) i el del dispositiu, en les mateixes unitats
            'resums' => [
                'garmin' => [
                    'km'        => $sortida->distancia_m !== null ? $sortida->distancia_m / 1000 : null,
                    'temps_s'   => $sortida->temps_s,
                    'moviment_s' => $sortida->temps_moviment_s,
                    'total_s'   => $sortida->temps_total_s,
                    'pujada'    => $sortida->desnivell_pos_m !== null ? (float) $sortida->desnivell_pos_m : null,
                    'baixada'   => $sortida->desnivell_neg_m !== null ? (float) $sortida->desnivell_neg_m : null,
                    'alt_min'   => $sortida->alt_min_m !== null ? (float) $sortida->alt_min_m : null,
                    'alt_max'   => $sortida->alt_max_m !== null ? (float) $sortida->alt_max_m : null,
                    'kmh'       => $sortida->vel_mitjana !== null ? $sortida->vel_mitjana * 3.6 : null,
                    'kmh_max'   => $sortida->vel_max !== null ? $sortida->vel_max * 3.6 : null,
                    'fc'        => $sortida->fc_mitjana,
                    'fc_max'    => $sortida->fc_max,
                    'cadencia'  => $sortida->cadencia_mitjana,
                    'calories'  => $sortida->calories,
                    'temp_min'  => $sortida->temp_min !== null ? (float) $sortida->temp_min : null,
                    'temp_max'  => $sortida->temp_max !== null ? (float) $sortida->temp_max : null,
                ],
                'dispositiu' => $d === [] ? null : [
                    'km'        => isset($d['totalDistance']) ? $d['totalDistance'] / 1000 : null,
                    'temps_s'   => isset($d['totalTimerTime']) ? (int) round($d['totalTimerTime']) : null,
                    'moviment_s' => isset($d['totalMovingTime']) ? (int) round($d['totalMovingTime']) : null,
                    'total_s'   => isset($d['totalElapsedTime']) ? (int) round($d['totalElapsedTime']) : null,
                    'pujada'    => $d['totalAscent'] ?? null,
                    'baixada'   => $d['totalDescent'] ?? null,
                    'alt_min'   => $d['enhancedMinAltitude'] ?? $d['minAltitude'] ?? null,
                    'alt_max'   => $d['enhancedMaxAltitude'] ?? $d['maxAltitude'] ?? null,
                    'kmh'       => isset($d['enhancedAvgSpeed']) || isset($d['avgSpeed']) ? ($d['enhancedAvgSpeed'] ?? $d['avgSpeed']) * 3.6 : null,
                    'kmh_max'   => isset($d['enhancedMaxSpeed']) || isset($d['maxSpeed']) ? ($d['enhancedMaxSpeed'] ?? $d['maxSpeed']) * 3.6 : null,
                    'fc'        => $d['avgHeartRate'] ?? null,
                    'fc_max'    => $d['maxHeartRate'] ?? null,
                    'cadencia'  => $d['avgCadence'] ?? null,
                    'calories'  => $d['totalCalories'] ?? null,
                    'temp_min'  => $d['minTemperature'] ?? null,
                    'temp_max'  => $d['maxTemperature'] ?? null,
                ],
            ],
            'altitud'  => app(AltitudTerrenyService::class)->opcions($sortida),
            'anterior' => $anterior,
            'seguent'  => $seguent,
            // Per corregir el material: les bicis i la resta de peces, amb les dates de cada una
            'bicis'    => Vehicle::where('tipus', 'bici')->orderBy('nom')->get(['id', 'nom', 'data_alta', 'data_baixa'])
                ->map(fn (Vehicle $v) => ['id' => $v->id, 'nom' => $v->nom, 'actiu' => $v->esActiu()]),
            'peces'    => MaterialEsportiu::where('tipus', '!=', 'bici')->orderBy('tipus')->orderBy('nom')->get()
                ->map(fn (MaterialEsportiu $m) => [
                    'id'         => $m->id,
                    'nom'        => $m->nom,
                    'tipus'      => $m->tipus,
                    'estat'      => $m->estat,
                    'data_inici' => $m->data_inici?->toDateString(),
                    'data_fi'    => $m->data_fi?->toDateString(),
                    'muntat_a_vehicle_id' => $m->muntat_a_vehicle_id,
                ]),
        ]);
    }

    /**
     * Corregeix la bici i el material d'una sortida. La peça de material de la bici (la que la
     * representa a l'origen) segueix la bici triada; la resta és el que s'hagi marcat. La
     * sortida queda com a editada i les importacions posteriors ja no li canvien el material.
     */
    public function material(SortidaBici $sortida, SortidaMaterialRequest $request)
    {
        $dades = $request->validated();

        $perLaBici = MaterialEsportiu::where('tipus', 'bici')->where('vehicle_id', $dades['vehicle_id'])->pluck('id');

        DB::transaction(function () use ($sortida, $dades, $perLaBici) {
            $sortida->update(['vehicle_id' => $dades['vehicle_id'], 'material_editat' => true]);
            $sortida->material()->sync($perLaBici->merge($dades['material_ids'])->unique()->values()->all());
        });

        return back();
    }

    /**
     * Els punts del recorregut, en columnes —una llista per magnitud—, que ocupa la meitat que
     * una llista d'objectes. Passats a unitats de lectura: graus, m, km, km/h.
     */
    public function punts(SortidaBici $sortida): JsonResponse
    {
        $graus = 180 / 2 ** 31;
        $columnes = ['t' => [], 'lat' => [], 'lon' => [], 'alt' => [], 'km' => [], 'kmh' => [], 'fc' => [], 'cad' => [], 'temp' => []];

        foreach ($sortida->puntsRecorregut()->cursor() as $p) {
            $columnes['t'][] = (int) $p->t;
            $columnes['lat'][] = $p->lat !== null ? round($p->lat * $graus, 6) : null;
            $columnes['lon'][] = $p->lon !== null ? round($p->lon * $graus, 6) : null;
            $columnes['alt'][] = $p->alt !== null ? round($p->alt / 100, 1) : null;
            $columnes['km'][] = $p->dist !== null ? round($p->dist / 100000, 3) : null;
            $columnes['kmh'][] = $p->vel !== null ? round($p->vel * 0.0036, 1) : null;
            $columnes['fc'][] = $p->fc !== null ? (int) $p->fc : null;
            $columnes['cad'][] = $p->cad !== null ? (int) $p->cad : null;
            $columnes['temp'][] = $p->temp !== null ? (int) $p->temp : null;
        }

        // Els perfils del terreny i combinats de cada model que la cobreix; el del dispositiu és `alt`
        $columnes['altituds'] = collect(app(AltitudTerrenyService::class)->perfils($sortida->id))
            ->except('dispositiu/dispositiu')
            ->map(fn ($alts) => array_map(fn ($a) => $a === null ? null : round($a, 1), $alts));

        return response()->json($columnes);
    }

    /** Com es mira l'altitud d'aquesta sortida; sense mètode, torna a la de per defecte. */
    public function altitud(AltitudPreferenciaRequest $request, SortidaBici $sortida)
    {
        $metode = $request->validated('metode');
        $sortida->update([
            'altitud_metode' => $metode,
            'altitud_font'   => $metode === null || $metode === 'dispositiu' ? null : $request->validated('font', 'millor'),
        ]);

        return back();
    }

    /** Com es mira l'altitud de totes les sortides que no en tenen una de pròpia. */
    public function altitudDefecte(AltitudPreferenciaRequest $request)
    {
        Preferencia::desa(AltitudTerrenyService::CLAU_PREFERENCIA, [
            'metode' => $request->validated('metode'),
            'font'   => $request->validated('font') ?? 'millor',
        ]);

        return back();
    }
}
