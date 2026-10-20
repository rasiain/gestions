<?php

namespace App\Services;

use App\Models\MaterialEsportiu;
use App\Models\SortidaBici;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZipArchive;

/**
 * Les sortides en bici d'una exportació de Garmin («Export Your Data»).
 *
 * L'exportació porta tres coses que s'han de creuar: el resum de cada activitat
 * (`*_summarizedActivities.json`), amb quin material es va fer (`*_gear.json`) i els FIT
 * originals, que són els que tenen els punts. El nom d'un FIT no diu de quina activitat és:
 * s'aparellen per l'hora d'inici.
 *
 * El resum de Garmin ve en cm, ms i cm/ms, i les calories, en kJ: aquí tot passa a SI i kcal.
 */
class SortidesGarminService
{
    public const ORIGEN = 'garmin';

    /** Marge per aparellar un FIT amb la seva activitat per l'hora d'inici. */
    public const MARGE_S = 5;

    private const KJ_PER_KCAL = 4.184;

    /** Punts per INSERT: 10 columnes × 500 queda lluny del límit de paràmetres de SQLite. */
    private const PUNTS_PER_INSERT = 500;

    /**
     * Les activitats fetes amb una bici del catàleg, amb el material de cadascuna.
     *
     * @return array<int, array{activitat: array, material: array<int, int>, vehicle_id: int}>
     *         per activityId
     */
    public function sortidesDeBici(array $activitats, array $gear): array
    {
        $material = MaterialEsportiu::where('origen', self::ORIGEN)->get()->keyBy('id_extern');

        $perActivitat = [];
        foreach ($gear['gearActivityDTOs'] ?? [] as $llista) {
            foreach ($llista as $fila) {
                $peca = $material[(string) $fila['gearPk']] ?? null;
                if ($peca !== null) {
                    $perActivitat[$fila['activityId']][] = $peca;
                }
            }
        }

        $sortides = [];
        foreach ($activitats as $a) {
            $peces = collect($perActivitat[$a['activityId']] ?? []);
            $bici = $peces->first(fn (MaterialEsportiu $p) => $p->tipus === 'bici' && $p->vehicle_id !== null);

            if ($bici === null) {
                continue;
            }

            $sortides[$a['activityId']] = [
                'activitat'  => $a,
                'material'   => $peces->pluck('id')->unique()->values()->all(),
                'vehicle_id' => $bici->vehicle_id,
            ];
        }

        return $sortides;
    }

    /** Les columnes de la sortida a partir del resum de Garmin. */
    public function resum(array $a): array
    {
        $div = fn (string $camp, float $entre) => isset($a[$camp]) ? round($a[$camp] / $entre, 3) : null;
        $enter = fn (?float $v) => $v === null ? null : (int) round($v);

        return [
            'nom'                => $a['name'] ?? null,
            'tipus_activitat'    => $a['activityType'] ?? null,
            'inici'              => Carbon::createFromTimestampMs((int) $a['startTimeGmt'], 'UTC'),
            // L'hora local ve com si fos UTC: n'hi ha prou amb llegir-ne la data tal qual
            'data_local'         => gmdate('Y-m-d', (int) ($a['startTimeLocal'] / 1000)),
            'distancia_m'        => $div('distance', 100),
            'temps_s'            => $enter($div('duration', 1000)),
            'temps_moviment_s'   => $enter($div('movingDuration', 1000)),
            'temps_total_s'      => $enter($div('elapsedDuration', 1000)),
            'desnivell_pos_m'    => $div('elevationGain', 100),
            'desnivell_neg_m'    => $div('elevationLoss', 100),
            'alt_min_m'          => $div('minElevation', 100),
            'alt_max_m'          => $div('maxElevation', 100),
            // cm/ms → m/s
            'vel_mitjana'        => isset($a['avgSpeed']) ? round($a['avgSpeed'] * 10, 3) : null,
            'vel_max'            => isset($a['maxSpeed']) ? round($a['maxSpeed'] * 10, 3) : null,
            'fc_mitjana'         => $enter($a['avgHr'] ?? null),
            'fc_max'             => $enter($a['maxHr'] ?? null),
            'cadencia_mitjana'   => $enter($a['avgBikeCadence'] ?? null),
            'calories'           => isset($a['calories']) ? (int) round($a['calories'] / self::KJ_PER_KCAL) : null,
            'temp_min'           => $a['minTemperature'] ?? null,
            'temp_max'           => $a['maxTemperature'] ?? null,
            'lat_inici'          => $a['startLatitude'] ?? null,
            'lon_inici'          => $a['startLongitude'] ?? null,
            'lat_min'            => $a['minLatitude'] ?? null,
            'lat_max'            => $a['maxLatitude'] ?? null,
            'lon_min'            => $a['minLongitude'] ?? null,
            'lon_max'            => $a['maxLongitude'] ?? null,
            'desnivell_corregit' => (bool) ($a['elevationCorrected'] ?? false),
            'dades_originals'    => $a,
        ];
    }

    /**
     * Desa una sortida —o l'actualitza, si ja hi és— amb el seu material i, si hi ha FIT, els
     * punts. Els punts d'una sortida que ja en té no es tornen a escriure: són els originals,
     * i en un futur hi pot haver ajustos al costat. El material d'una sortida corregida a mà
     * (`material_editat`) tampoc: ni la bici ni els components.
     *
     * @param  array{activitat: array, material: array<int, int>, vehicle_id: int}  $sortida
     * @param  array|null  $fit  una línia de scripts/llegeix-fit.mjs
     */
    public function desa(array $sortida, ?array $fit, ?string $rutaFit = null): SortidaBici
    {
        $a = $sortida['activitat'];
        $dades = ['vehicle_id' => $sortida['vehicle_id']] + $this->resum($a);

        if ($fit !== null) {
            $dades['resum_dispositiu'] = $fit['sessio'];
            $dades['recorregut_simplificat'] = $fit['simplificat'];
            // El rectangle dels punts és exacte; el del resum, Garmin només el porta de vegades
            if ($fit['rectangle'] !== null) {
                $dades = array_merge($dades, $fit['rectangle']);
            }
        }

        return DB::transaction(function () use ($a, $dades, $sortida, $fit, $rutaFit) {
            $model = SortidaBici::firstOrNew(['origen' => self::ORIGEN, 'id_extern' => (string) $a['activityId']]);

            if ($model->material_editat) {
                unset($dades['vehicle_id']);
            }

            $model->fill($dades)->save();

            if (! $model->material_editat) {
                $model->material()->sync($sortida['material']);
            }

            if ($rutaFit !== null && $model->fitxer === null) {
                $model->fitxer = $this->copiaFit($rutaFit, $model);
            }

            if ($fit !== null && $model->punts === 0 && $fit['punts'] !== []) {
                $model->punts = $this->desaPunts($model->id, $fit['punts']);
            }

            $model->save();

            return $model;
        });
    }

    /** @return int quants punts s'han desat */
    private function desaPunts(int $sortidaId, array $punts): int
    {
        $db = DB::connection('recorreguts');
        $desats = 0;

        $db->transaction(function () use ($db, $sortidaId, $punts, &$desats) {
            foreach (array_chunk($punts, self::PUNTS_PER_INSERT) as $tros) {
                // Dos punts al mateix segon: es queda el primer
                $desats += $db->table('punts')->insertOrIgnore(array_map(fn (array $p) => [
                    'sortida_id' => $sortidaId,
                    't'          => $p[0],
                    'lat'        => $p[1],
                    'lon'        => $p[2],
                    'alt'        => $p[3],
                    'dist'       => $p[4],
                    'vel'        => $p[5],
                    'fc'         => $p[6],
                    'cad'        => $p[7],
                    'temp'       => $p[8],
                ], $tros));
            }
        });

        return $desats;
    }

    /** Copia el FIT a storage/app/sortides/{any}/ i en torna la ruta relativa. */
    private function copiaFit(string $origen, SortidaBici $sortida): string
    {
        $relativa = $sortida->data_local->format('Y') . '/' . self::ORIGEN . '-' . $sortida->id_extern . '.fit';
        $desti = storage_path('app/sortides/' . $relativa);

        if (! is_dir(dirname($desti))) {
            mkdir(dirname($desti), 0755, true);
        }
        copy($origen, $desti);

        return $relativa;
    }

    /**
     * Treu del ZIP de l'exportació el material, els resums i els FIT, aquests a la carpeta
     * indicada. Els FIT són dins de ZIP niats (UploadedFiles_0-_PartN.zip).
     *
     * @return array{activitats: array, gear: array}
     */
    public function llegeixExportacio(string $zip, string $carpetaFit): array
    {
        $arxiu = new ZipArchive();
        if ($arxiu->open($zip) !== true) {
            throw new RuntimeException("{$zip} no és un ZIP vàlid.");
        }

        $gear = null;
        $activitats = [];
        $parts = [];

        for ($i = 0; $i < $arxiu->numFiles; $i++) {
            $nom = $arxiu->getNameIndex($i);

            if (str_ends_with($nom, '_gear.json')) {
                $json = json_decode($arxiu->getFromIndex($i), true);
                $gear = array_is_list($json) ? $json[0] : $json;
            } elseif (str_ends_with($nom, '_summarizedActivities.json')) {
                $json = json_decode($arxiu->getFromIndex($i), true);
                $activitats = array_merge($activitats, $json[0]['summarizedActivitiesExport'] ?? $json);
            } elseif (preg_match('#DI-Connect-Uploaded-Files/[^/]+\.zip$#', $nom)) {
                $parts[] = $nom;
            }
        }

        if ($gear === null || $activitats === []) {
            $arxiu->close();
            throw new RuntimeException('El ZIP no porta el material o els resums: no sembla una exportació de Garmin.');
        }

        if (! is_dir($carpetaFit)) {
            mkdir($carpetaFit, 0755, true);
        }

        foreach ($parts as $part) {
            $temporal = $carpetaFit . '/' . basename($part);
            copy("zip://{$zip}#{$part}", $temporal);

            $niat = new ZipArchive();
            if ($niat->open($temporal) === true) {
                for ($i = 0; $i < $niat->numFiles; $i++) {
                    $nom = $niat->getNameIndex($i);
                    if (str_ends_with(strtolower($nom), '.fit')) {
                        copy("zip://{$temporal}#{$nom}", $carpetaFit . '/' . basename($nom));
                    }
                }
                $niat->close();
            }
            unlink($temporal);
        }

        $arxiu->close();

        return ['activitats' => $activitats, 'gear' => $gear];
    }
}
