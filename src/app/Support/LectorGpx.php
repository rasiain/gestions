<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Llegeix un GPX i en torna el mateix que scripts/llegeix-fit.mjs per una sessió, perquè la resta
 * de la importació no hagi de distingir els dos formats.
 *
 * Un GPX porta molt menys que un FIT: posició, altitud i hora (els de Runtastic, OruxMaps o Strava,
 * res més). La posició passa a semicercles, com al FIT, sense pèrdua: un semicercle és més fi que el
 * setè decimal de grau que porten. La distància i la velocitat **no hi són i es calculen** de les
 * posicions; per això `sessio` porta `calculat`, i no és cap resum d'un dispositiu.
 */
class LectorGpx
{
    private const RADI_TERRA_M = 6371008.8;

    /** @return array|null  null si no té cap punt amb hora */
    public static function llegeix(string $ruta): ?array
    {
        $xml = @simplexml_load_file($ruta);
        if ($xml === false) {
            return null;
        }

        $creador = (string) ($xml['creator'] ?? '');
        $tipus = strtolower((string) ($xml->trk->type ?? '') . ' ' . basename($ruta));
        $semi = 2 ** 31 / 180;

        $punts = [];
        $coords = [];
        $inici = null;
        $anterior = null;
        $dist = 0.0;

        foreach ($xml->trk as $trk) {
            foreach ($trk->trkseg as $seg) {
                foreach ($seg->trkpt as $p) {
                    if (! isset($p->time)) {
                        continue;
                    }
                    $t = Carbon::parse((string) $p->time)->getTimestamp();
                    $inici ??= $t;
                    $lat = (float) $p['lat'];
                    $lon = (float) $p['lon'];

                    $tram = $anterior ? self::distancia($anterior[0], $anterior[1], $lat, $lon) : 0.0;
                    $dt = $anterior ? $t - $anterior[2] : 0;
                    $dist += $tram;

                    $punts[] = [
                        $t - $inici,
                        (int) round($lat * $semi),
                        (int) round($lon * $semi),
                        isset($p->ele) ? (int) round((float) $p->ele * 100) : null,
                        (int) round($dist * 100),
                        $dt > 0 ? (int) round($tram / $dt * 1000) : null,
                        null, null, null,
                    ];
                    $coords[] = [$lat, $lon];
                    $anterior = [$lat, $lon, $t];
                }
            }
        }

        if ($punts === []) {
            return null;
        }

        $lats = array_column($coords, 0);
        $lons = array_column($coords, 1);
        $durada = end($punts)[0];

        return [
            'fitxer'       => basename($ruta),
            'inici'        => $inici,
            'desfas_local' => null,
            'sessio'       => [
                'format'           => 'gpx',
                'creator'          => $creador,
                'calculat'         => true,
                'sport'            => 'cycling',
                'subSport'         => str_contains($tipus, 'mountain') ? 'mountain' : null,
                'startTime'        => gmdate('Y-m-d\TH:i:s.000\Z', $inici),
                'totalDistance'    => round($dist, 2),
                'totalElapsedTime' => $durada,
            ],
            'punts'        => $punts,
            'rectangle'    => ['lat_min' => min($lats), 'lat_max' => max($lats), 'lon_min' => min($lons), 'lon_max' => max($lons)],
            // La mateixa tolerància que el lector de FIT: ~10 m
            'simplificat'  => Polilinia::codifica(Polilinia::simplifica($coords, 0.0001)),
        ];
    }

    /** Haversine, en metres. */
    private static function distancia(float $la1, float $lo1, float $la2, float $lo2): float
    {
        $dLa = deg2rad($la2 - $la1);
        $dLo = deg2rad($lo2 - $lo1);
        $a = sin($dLa / 2) ** 2 + cos(deg2rad($la1)) * cos(deg2rad($la2)) * sin($dLo / 2) ** 2;

        return 2 * self::RADI_TERRA_M * asin(min(1.0, sqrt($a)));
    }
}
