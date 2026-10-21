<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportacioSortidesRequest;
use App\Models\SortidaBici;
use App\Models\Vehicle;
use App\Services\ImportacioRecorregutsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;

/**
 * La pantalla per importar els FIT baixats de Garmin Connect: completa les sortides que no tenen
 * recorregut i crea les que no hi són. La llista de les que encara no en tenen hi és al costat,
 * amb l'enllaç a Garmin Connect per baixar-ne l'original.
 */
class ImportacioSortidesController extends Controller
{
    public function index()
    {
        return Inertia::render('Bicis/Importa', [
            'bicis'           => Vehicle::where('tipus', 'bici')->orderBy('nom')->get()
                ->map(fn (Vehicle $v) => ['id' => $v->id, 'nom' => $v->nom, 'actiu' => $v->esActiu()]),
            'senseRecorregut' => SortidaBici::with('vehicle:id,nom')
                ->where('punts', 0)
                ->where('tipus_activitat', '!=', 'indoor_cycling')
                ->orderByDesc('distancia_m')
                ->get()
                ->map(fn (SortidaBici $s) => [
                    'id'     => $s->id,
                    'data'   => $s->data_local->toDateString(),
                    'nom'    => $s->nom,
                    'bici'   => $s->vehicle?->nom,
                    'km'     => $s->distancia_m !== null ? round($s->distancia_m / 1000, 1) : null,
                    // Només les de Garmin tenen pàgina a Garmin Connect
                    'garmin' => $s->origen === 'garmin' && ctype_digit($s->id_extern)
                        ? "https://connect.garmin.com/modern/activity/{$s->id_extern}"
                        : null,
                ]),
        ]);
    }

    public function store(ImportacioSortidesRequest $request, ImportacioRecorregutsService $servei): JsonResponse
    {
        // Amb el nom original: el de Garmin Connect porta l'identificador de l'activitat
        $carpeta = storage_path('app/tmp/pujada-' . uniqid());
        File::ensureDirectoryExists($carpeta);

        try {
            $rutes = [];
            foreach ($request->file('fitxers') as $fitxer) {
                $nom = basename($fitxer->getClientOriginalName());
                $fitxer->move($carpeta, $nom);
                $rutes[] = "{$carpeta}/{$nom}";
            }

            $resultats = $servei->importa($rutes, $request->validated('vehicle_id'));
        } finally {
            File::deleteDirectory($carpeta);
        }

        $canvis = (bool) array_intersect(['afegit', 'creada'], array_column($resultats, 'estat'));
        if ($canvis) {
            $servei->recalculaRutes();
        }

        return response()->json(['resultats' => $resultats, 'recalculant' => $canvis]);
    }
}
