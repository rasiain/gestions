<?php

namespace App\Support;

/**
 * Polilínies codificades (format de Google, 5 decimals), com el `recorregut_simplificat` de les
 * sortides, i la seva simplificació (Douglas-Peucker sobre graus).
 */
class Polilinia
{
    /** @return array<int, array{float, float}> [lat, lon] */
    public static function decodifica(string $codi): array
    {
        $punts = [];
        $i = 0;
        $lat = 0;
        $lon = 0;
        $llarg = strlen($codi);

        while ($i < $llarg) {
            foreach (['lat', 'lon'] as $eix) {
                $resultat = 0;
                $despl = 0;
                do {
                    $b = ord($codi[$i++]) - 63;
                    $resultat |= ($b & 0x1f) << $despl;
                    $despl += 5;
                } while ($b >= 0x20 && $i < $llarg);
                $delta = ($resultat & 1) ? ~($resultat >> 1) : ($resultat >> 1);
                $eix === 'lat' ? $lat += $delta : $lon += $delta;
            }
            $punts[] = [$lat / 1e5, $lon / 1e5];
        }

        return $punts;
    }

    /** @param  array<int, array{float, float}>  $punts */
    public static function codifica(array $punts): string
    {
        $codi = '';
        $pLat = 0;
        $pLon = 0;
        foreach ($punts as [$lat, $lon]) {
            $la = (int) round($lat * 1e5);
            $lo = (int) round($lon * 1e5);
            $codi .= self::valor($la - $pLat) . self::valor($lo - $pLon);
            $pLat = $la;
            $pLon = $lo;
        }

        return $codi;
    }

    private static function valor(int $v): string
    {
        $v = $v < 0 ? ~($v << 1) : $v << 1;
        $r = '';
        while ($v >= 0x20) {
            $r .= chr((0x20 | ($v & 0x1f)) + 63);
            $v >>= 5;
        }

        return $r . chr($v + 63);
    }

    /**
     * Douglas-Peucker: treu els punts que es desvien menys de la tolerància (graus) de la recta
     * entre els seus veïns conservats.
     *
     * @param  array<int, array{float, float}>  $punts
     * @return array<int, array{float, float}>
     */
    public static function simplifica(array $punts, float $tolerancia): array
    {
        $n = count($punts);
        if ($n < 3) {
            return $punts;
        }

        $guarda = array_fill(0, $n, false);
        $guarda[0] = $guarda[$n - 1] = true;
        $pila = [[0, $n - 1]];

        while ($pila) {
            [$a, $b] = array_pop($pila);
            [$ay, $ax] = $punts[$a];
            [$by, $bx] = $punts[$b];
            $dx = $bx - $ax;
            $dy = $by - $ay;
            $llarg = hypot($dx, $dy) ?: 1e-12;
            $max = -1.0;
            $idx = -1;
            for ($i = $a + 1; $i < $b; $i++) {
                $d = abs($dy * $punts[$i][1] - $dx * $punts[$i][0] + $bx * $ay - $by * $ax) / $llarg;
                if ($d > $max) {
                    $max = $d;
                    $idx = $i;
                }
            }
            if ($max > $tolerancia) {
                $guarda[$idx] = true;
                $pila[] = [$a, $idx];
                $pila[] = [$idx, $b];
            }
        }

        return array_values(array_filter($punts, fn ($_, $i) => $guarda[$i], ARRAY_FILTER_USE_BOTH));
    }
}
