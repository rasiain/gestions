<?php

namespace App\Support;

/**
 * El desnivell positiu i negatiu d'un perfil d'altituds. Sumar cada pujadeta s'enduria el soroll de
 * l'altímetre (un 30 % més que el dispositiu), i per això una pujada només compta quan l'altitud
 * supera en LLINDAR l'últim punt de referència.
 *
 * **És el mateix càlcul que `acumulat` de Pages/Bicis/Sortida.vue**: si divergissin, la pantalla de
 * la sortida i les llistes donarien desnivells diferents per a la mateixa sortida. La baixada és la
 * mateixa funció sobre el perfil girat.
 */
class Desnivell
{
    public const LLINDAR_M = 2.5;

    /**
     * @param  iterable<int|float|null>  $alts  en metres; els null se salten
     * @return array{pujada: float, baixada: float}
     */
    public static function calcula(iterable $alts): array
    {
        $alts = is_array($alts) ? $alts : iterator_to_array($alts, false);

        return [
            'pujada'  => self::puja($alts),
            'baixada' => self::puja(array_map(fn ($a) => $a === null ? null : -$a, $alts)),
        ];
    }

    private static function puja(array $alts): float
    {
        $ref = null;
        $total = 0.0;
        foreach ($alts as $a) {
            if ($a === null) {
                continue;
            }
            if ($ref === null || $a < $ref) {
                $ref = $ref === null || $ref - $a >= self::LLINDAR_M ? $a : $ref;
            }
            if ($a - $ref >= self::LLINDAR_M) {
                $total += $a - $ref;
                $ref = $a;
            }
        }

        return round($total, 1);
    }
}
