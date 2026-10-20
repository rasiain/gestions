<?php

namespace App\Http\Controllers;

use App\Http\Requests\MaterialEsportiuRequest;
use App\Models\MaterialEsportiu;
use App\Models\Vehicle;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * El material esportiu que no és una bici: components, sabates i la resta. Ve de Garmin,
 * però s'hi poden afegir peces pròpies (`origen = manual`) i corregir les que hi ha. Una peça
 * de Garmin corregida queda `editat`, i `material:importa` ja no en toca les dades.
 */
class MaterialEsportiuController extends Controller
{
    public function index()
    {
        $peces = MaterialEsportiu::where('tipus', '!=', 'bici')
            ->withCount('sortides')
            ->withSum('sortides', 'distancia_m')
            ->with('muntatA:id,nom')
            ->orderBy('tipus')
            ->orderByRaw("estat = 'retirat'")
            ->orderByDesc('data_inici')
            ->get()
            ->map(fn (MaterialEsportiu $m) => [
                'id'                  => $m->id,
                'nom'                 => $m->nom,
                'model'               => $m->model,
                'tipus'               => $m->tipus,
                'estat'               => $m->estat,
                'data_inici'          => $m->data_inici?->toDateString(),
                'data_fi'             => $m->data_fi?->toDateString(),
                'muntat_a_vehicle_id' => $m->muntat_a_vehicle_id,
                'muntat_a'            => $m->muntatA?->nom,
                'origen'              => $m->origen,
                'editat'              => $m->editat,
                'sortides'            => $m->sortides_count,
                'km'                  => (int) round(($m->sortides_sum_distancia_m ?? 0) / 1000),
            ]);

        return Inertia::render('Bicis/Material', [
            'peces' => $peces,
            'bicis' => Vehicle::where('tipus', 'bici')->orderBy('nom')->get(['id', 'nom']),
            'tipus' => array_values(array_diff(MaterialEsportiu::TIPUS, ['bici'])),
        ]);
    }

    public function store(MaterialEsportiuRequest $request)
    {
        // Una peça pròpia no té identificador a cap aplicació: se li'n posa un perquè la
        // parella (origen, id_extern) continuï sent única
        MaterialEsportiu::create($request->validated() + [
            'origen'    => 'manual',
            'id_extern' => (string) Str::uuid(),
        ]);

        return back();
    }

    public function update(MaterialEsportiuRequest $request, MaterialEsportiu $material)
    {
        abort_if($material->tipus === 'bici', 404);

        $material->update($request->validated() + ['editat' => $material->origen !== 'manual']);

        return back();
    }

    /**
     * Només les peces pròpies i sense sortides. Una de Garmin tornaria amb la importació
     * següent —si ja no es fa servir, es retira—, i una amb sortides perdria els seus km.
     */
    public function destroy(MaterialEsportiu $material)
    {
        if ($material->origen !== 'manual' || $material->sortides()->exists()) {
            return back()->withErrors(['esborrar' => $material->origen !== 'manual'
                ? 'Les peces de Garmin no s\'esborren: tornarien amb la importació següent. Posa-la com a retirada.'
                : 'Aquesta peça té sortides: si s\'esborra, se\'n perden els km. Posa-la com a retirada.']);
        }

        $material->delete();

        return back();
    }
}
