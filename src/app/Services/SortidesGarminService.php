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
    public function sortidesDeBici(array $activitats, array $gear, array $assignacions = []): array
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

            // Una activitat sense bici a Garmin (una bici de lloguer, una que no es va marcar)
            // només entra si se li ha dit amb quina es va fer; queda com a corregida a mà
            $assignada = $bici === null && isset($assignacions[$a['activityId']]);

            if ($bici === null && ! $assignada) {
                continue;
            }

            $sortides[$a['activityId']] = [
                'activitat'  => $a,
                'material'   => $peces->pluck('id')->unique()->values()->all(),
                'vehicle_id' => $assignada ? $assignacions[$a['activityId']] : $bici->vehicle_id,
                'assignada'  => $assignada,
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
        // El del FIT mana sobre el del resum (el rectangle exacte sobre el que Garmin porta de vegades)
        $dades = array_merge(['vehicle_id' => $sortida['vehicle_id']] + $this->resum($a), $fit !== null ? $this->delFit($fit) : []);

        return DB::transaction(function () use ($a, $dades, $sortida, $fit, $rutaFit) {
            $model = SortidaBici::firstOrNew(['origen' => self::ORIGEN, 'id_extern' => (string) $a['activityId']]);

            if ($model->material_editat) {
                unset($dades['vehicle_id']);
            }

            // La bici la va dir l'usuari, no Garmin: una importació posterior no l'ha de tocar
            if ($sortida['assignada'] ?? false) {
                $dades['material_editat'] = true;
            }

            $model->fill($dades)->save();

            if (! $model->material_editat) {
                $model->material()->sync($sortida['material']);
            }

            $this->desaFitIPunts($model, $fit, $rutaFit);
            $model->save();

            return $model;
        });
    }

    /** El que una sortida treu del seu FIT: el resum del dispositiu, el traçat i el rectangle. */
    private function delFit(array $fit): array
    {
        return [
            'resum_dispositiu'       => $fit['sessio'],
            'recorregut_simplificat' => $fit['simplificat'],
        ] + ($fit['rectangle'] ?? []);   // exacte; el del resum, Garmin només el porta de vegades
    }

    private function desaFitIPunts(SortidaBici $model, ?array $fit, ?string $rutaFit): void
    {
        if ($rutaFit !== null && $model->fitxer === null) {
            $model->fitxer = $this->copiaFit($rutaFit, $model);
        }

        if ($fit !== null && $model->punts === 0 && $fit['punts'] !== []) {
            $model->punts = $this->desaPunts($model->id, $fit['punts']);
        }
    }

    /**
     * Crea una sortida que no és a la BD només amb el FIT —sense el resum de Garmin Connect al
     * costat—: el resum de les columnes és el del dispositiu, i el que la sessió no porta (altituds
     * i temperatures extremes) surt dels punts. La bici la diu l'usuari, i els components són els
     * muntats a la bici aquell dia.
     *
     * @param  array  $fit  una línia de scripts/llegeix-fit.mjs
     */
    public function creaDesDeFit(array $fit, string $rutaFit, int $vehicleId, string $idExtern): SortidaBici
    {
        $s = $fit['sessio'];
        $punts = $fit['punts'];
        $inici = Carbon::createFromTimestamp($fit['inici'], 'UTC');
        // Sense el desfasament de l'hora local, el de Catalunya: és on es fan gairebé totes
        $local = $fit['desfas_local'] !== null
            ? $inici->copy()->addSeconds($fit['desfas_local'])
            : $inici->copy()->setTimezone('Europe/Madrid');

        $columna = fn (int $i) => array_values(array_filter(array_column($punts, $i), fn ($v) => $v !== null));
        $alts = $columna(3);
        $temps = $columna(8);
        $semi = 180 / 2 ** 31;

        $dades = [
            'vehicle_id'       => $vehicleId,
            'nom'              => self::NOMS_TIPUS[$this->tipusActivitat($s)] ?? 'Sortida en bici',
            'tipus_activitat'  => $this->tipusActivitat($s),
            'inici'            => $inici,
            'data_local'       => $local->toDateString(),
            'distancia_m'      => $s['totalDistance'] ?? null,
            'temps_s'          => isset($s['totalTimerTime']) ? (int) round($s['totalTimerTime']) : null,
            'temps_moviment_s' => isset($s['totalMovingTime']) ? (int) round($s['totalMovingTime']) : null,
            'temps_total_s'    => isset($s['totalElapsedTime']) ? (int) round($s['totalElapsedTime']) : null,
            'desnivell_pos_m'  => $s['totalAscent'] ?? null,
            'desnivell_neg_m'  => $s['totalDescent'] ?? null,
            'alt_min_m'        => $s['enhancedMinAltitude'] ?? ($alts ? min($alts) / 100 : null),
            'alt_max_m'        => $s['enhancedMaxAltitude'] ?? ($alts ? max($alts) / 100 : null),
            'vel_mitjana'      => $s['enhancedAvgSpeed'] ?? $s['avgSpeed'] ?? null,
            'vel_max'          => $s['enhancedMaxSpeed'] ?? $s['maxSpeed'] ?? null,
            'fc_mitjana'       => $s['avgHeartRate'] ?? null,
            'fc_max'           => $s['maxHeartRate'] ?? null,
            'cadencia_mitjana' => $s['avgCadence'] ?? null,
            'calories'         => $s['totalCalories'] ?? null,
            'temp_min'         => $s['minTemperature'] ?? ($temps ? min($temps) : null),
            'temp_max'         => $s['maxTemperature'] ?? ($temps ? max($temps) : null),
            'lat_inici'        => isset($s['startPositionLat']) ? $s['startPositionLat'] * $semi : null,
            'lon_inici'        => isset($s['startPositionLong']) ? $s['startPositionLong'] * $semi : null,
        ] + $this->delFit($fit);

        return DB::transaction(function () use ($dades, $fit, $rutaFit, $idExtern, $vehicleId, $local) {
            $model = SortidaBici::create(['origen' => self::ORIGEN, 'id_extern' => $idExtern] + $dades);

            // La bici (la peça de Garmin que la representa) i els components muntats aquell dia
            $dia = $local->toDateString();
            $material = MaterialEsportiu::where(fn ($q) => $q->where('tipus', 'bici')->where('vehicle_id', $vehicleId))
                ->orWhere(fn ($q) => $q->where('muntat_a_vehicle_id', $vehicleId)
                    ->where(fn ($q) => $q->whereNull('data_inici')->orWhere('data_inici', '<=', $dia))
                    ->where(fn ($q) => $q->whereNull('data_fi')->orWhere('data_fi', '>=', $dia)))
                ->pluck('id');
            $model->material()->sync($material);

            $this->desaFitIPunts($model, $fit, $rutaFit);
            $model->save();

            return $model;
        });
    }

    /** Les sessions de ciclisme del FIT, amb el nom que Garmin Connect dona a l'activitat. */
    public function tipusActivitat(array $sessio): ?string
    {
        if (($sessio['sport'] ?? null) !== 'cycling') {
            return null;
        }

        return match ($sessio['subSport'] ?? null) {
            'mountain'      => 'mountain_biking',
            'road'          => 'road_biking',
            'gravelCycling' => 'gravel_cycling',
            'indoorCycling' => 'indoor_cycling',
            'track'         => 'track_cycling',
            'downhill'      => 'downhill_biking',
            default         => 'cycling',
        };
    }

    private const NOMS_TIPUS = [
        'mountain_biking' => 'Sortida de BTT',
        'road_biking'     => 'Sortida de carretera',
        'gravel_cycling'  => 'Sortida de gravel',
        'indoor_cycling'  => 'Rodets',
        'cycling'         => 'Sortida en bici',
    ];

    /**
     * Afegeix el recorregut a una sortida que ja hi és però que no en té: la que l'exportació de
     * Garmin no va portar el FIT i se n'ha baixat l'original a part. El resum de les columnes no es
     * toca —continua sent el de Garmin—; s'hi afegeixen el del dispositiu, el traçat i els punts.
     *
     * @param  array  $fit  una línia de scripts/llegeix-fit.mjs
     */
    public function afegeixRecorregut(SortidaBici $model, array $fit, string $rutaFit): SortidaBici
    {
        if ($model->punts > 0) {
            throw new \RuntimeException('Aquesta sortida ja té recorregut: els punts originals no es reescriuen.');
        }

        return DB::transaction(function () use ($model, $fit, $rutaFit) {
            $model->fill($this->delFit($fit));
            $this->desaFitIPunts($model, $fit, $rutaFit);
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

    /** Copia el FIT a storage/app/sortides/{any}/ i en torna la ruta relativa (null si no hi és). */
    private function copiaFit(string $origen, SortidaBici $sortida): ?string
    {
        if (! is_file($origen)) {
            return null;
        }

        // L'extensió de l'original: un GPX continua sent un GPX
        $extensio = strtolower(pathinfo($origen, PATHINFO_EXTENSION)) ?: 'fit';
        $relativa = $sortida->data_local->format('Y') . '/' . self::ORIGEN . '-' . $sortida->id_extern . '.' . $extensio;
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
