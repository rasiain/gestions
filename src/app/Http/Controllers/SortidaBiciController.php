<?php

namespace App\Http\Controllers;

use App\Models\MaterialEsportiu;
use App\Models\SortidaBici;
use App\Models\Vehicle;
use App\Models\VehicleDespesa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * La fitxa d'una bici: el que diuen les seves sortides. Va per JSON i no amb la pàgina de
 * bicis perquè són milers de sortides i només es miren quan s'obre la fitxa.
 */
class SortidaBiciController extends Controller
{
    /** Els rodets: la distància és virtual i es compta a part. */
    private const INDOOR = 'indoor_cycling';

    /** Resum per any i per mes, i els components amb els seus quilòmetres. */
    public function fitxa(Vehicle $vehicle): JsonResponse
    {
        abort_unless($vehicle->tipus === 'bici', 404);

        $base = SortidaBici::where('vehicle_id', $vehicle->id);

        $perAny = (clone $base)
            ->selectRaw("strftime('%Y', data_local) as any")
            ->selectRaw('count(*) as sortides')
            ->selectRaw('sum(distancia_m) / 1000.0 as km')
            ->selectRaw('sum(case when tipus_activitat = ? then distancia_m else 0 end) / 1000.0 as km_indoor', [self::INDOOR])
            ->selectRaw('sum(coalesce(temps_moviment_s, temps_s)) / 3600.0 as hores')
            ->selectRaw('sum(desnivell_pos_m) as desnivell')
            ->groupBy('any')
            ->orderByDesc('any')
            ->get()
            ->keyBy('any');

        $despeses = VehicleDespesa::where('vehicle_id', $vehicle->id)->get()
            ->groupBy(fn (VehicleDespesa $d) => $d->data->format('Y'))
            ->map(fn ($grup) => round((float) $grup->sum(fn (VehicleDespesa $d) => (float) $d->import), 2));

        $anys = $perAny->keys()->merge($despeses->keys())->unique()->sortDesc()->values();

        return response()->json([
            'per_any' => $anys->map(function ($any) use ($perAny, $despeses) {
                $f = $perAny[$any] ?? null;
                $km = $f ? round((float) $f->km, 1) : 0.0;
                $cost = $despeses[$any] ?? 0.0;

                return [
                    'any'       => (int) $any,
                    'sortides'  => $f ? (int) $f->sortides : 0,
                    'km'        => $km,
                    'km_indoor' => $f ? round((float) $f->km_indoor, 1) : 0.0,
                    'hores'     => $f ? round((float) $f->hores, 1) : 0.0,
                    'desnivell' => $f ? (int) round((float) $f->desnivell) : 0,
                    'despeses'  => $cost,
                    'cost_km'   => $km > 0 && $cost > 0 ? round($cost / $km, 3) : null,
                ];
            })->values(),
            'per_mes'    => (clone $base)
                ->selectRaw("strftime('%Y-%m', data_local) as mes")
                ->selectRaw('count(*) as sortides')
                ->selectRaw('round(sum(case when tipus_activitat = ? then 0 else distancia_m end) / 1000.0, 1) as km', [self::INDOOR])
                ->selectRaw('round(sum(case when tipus_activitat = ? then distancia_m else 0 end) / 1000.0, 1) as km_indoor', [self::INDOOR])
                ->groupBy('mes')
                ->orderBy('mes')
                ->get(),
            'tipus'      => (clone $base)
                ->selectRaw('tipus_activitat as tipus, count(*) as sortides')
                ->groupBy('tipus_activitat')
                ->orderByDesc('sortides')
                ->get(),
            'components' => $this->components($vehicle),
        ]);
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

        $any = $request->integer('any') ?: null;
        $tipus = $request->string('tipus')->toString() ?: null;

        $sortides = SortidaBici::where('vehicle_id', $vehicle->id)
            ->when($any, fn ($q) => $q->whereYear('data_local', $any))
            ->when($tipus, fn ($q) => $q->where('tipus_activitat', $tipus))
            ->orderByDesc('inici')
            // L'hora local és al registre de l'origen: no cal portar-ne el JSON sencer
            ->select([
                'id', 'data_local', 'nom', 'tipus_activitat', 'distancia_m', 'temps_moviment_s', 'temps_s',
                'desnivell_pos_m', 'vel_mitjana', 'fc_mitjana', 'punts', 'desnivell_corregit',
            ])
            ->selectRaw("json_extract(dades_originals, '$.startTimeLocal') as inici_local_ms")
            ->get()
            ->map(fn (SortidaBici $s) => [
                'id'                 => $s->id,
                'data'               => $s->data_local->toDateString(),
                'hora'               => $s->inici_local_ms ? gmdate('H:i', (int) ($s->inici_local_ms / 1000)) : null,
                'nom'                => $s->nom,
                'tipus'              => $s->tipus_activitat,
                'km'                 => $s->distancia_m !== null ? round($s->distancia_m / 1000, 2) : null,
                'temps_s'            => $s->temps_moviment_s ?? $s->temps_s,
                'desnivell'          => $s->desnivell_pos_m !== null ? (int) round($s->desnivell_pos_m) : null,
                'kmh'                => $s->vel_mitjana !== null ? round($s->vel_mitjana * 3.6, 1) : null,
                'fc'                 => $s->fc_mitjana,
                'amb_recorregut'     => $s->punts > 0,
                'desnivell_corregit' => $s->desnivell_corregit,
            ]);

        return response()->json($sortides);
    }
}
