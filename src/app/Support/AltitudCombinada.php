<?php

namespace App\Support;

/**
 * L'altitud combinada d'una sortida: el model del terreny dona el **nivell** i l'altímetre el **detall**,
 * on l'altímetre és de fiar. Especificació i calibratge a .claude/specs/bicis-altitud-corregida.md.
 *
 * 1. **Les aturades es col·lapsen**: on la bici no avança, l'altitud no pot canviar. Cada tram on la
 *    distància avança PAS_M és un node, amb l'altitud de l'últim punt del grup (després d'una aturada,
 *    la del baròmetre ja reajustat).
 * 2. **Dispositiu corregit** = dispositiu + mediana mòbil de (terreny − dispositiu) en FINESTRA_M de
 *    distància. La mediana respecta els salts —la deriva d'una aturada llarga passa en una sola
 *    distància i es corregeix de cop— i no es deixa endur per un tram curt on el model diu una altra
 *    cosa: un pont (el model hi posa el fons de la vall) o un túnel.
 * 3. **On l'altímetre fa soroll, mana el model.** El soroll és el RMS de la segona diferència del
 *    dispositiu en SOROLL_M: per sota de NET_M el pes del dispositiu corregit és 1, per sobre de BRUT_M
 *    és 0. No es compara amb el soroll del model: de punt a punt, un bon altímetre i el model en fan
 *    el mateix, i fer-ne la mitjana s'enduia part del relleu.
 */
class AltitudCombinada
{
    public const PAS_M = 2.0;

    public const FINESTRA_M = 300.0;

    public const SOROLL_M = 200.0;

    public const NET_M = 0.2;

    public const BRUT_M = 0.5;

    /**
     * @param  array<int, array{0: ?float, 1: ?float, 2: ?float}>  $punts  [distància, dispositiu, terreny] en m, per ordre
     * @return array<int, ?float> l'altitud combinada de cada punt, en m; null on no hi ha terreny
     */
    public static function calcula(array $punts): array
    {
        // Els nodes: només els punts amb terreny; la distància no pot recular
        $grups = [];
        $x = $d = $m = [];
        $ultima = null;
        $distancia = 0.0;
        foreach ($punts as $k => [$dist, $disp, $terreny]) {
            $distancia = max($distancia, (float) ($dist ?? $distancia));
            if ($terreny === null) {
                continue;
            }
            if ($ultima === null || $distancia - $ultima >= self::PAS_M) {
                $grups[] = [];
                $x[] = $distancia;
                $ultima = $distancia;
            }
            $i = count($grups) - 1;
            $grups[$i][] = $k;
            $x[$i] = $distancia;
            // L'últim punt del grup; sense altitud del dispositiu, el model
            $d[$i] = $disp ?? $terreny;
            $m[$i] = $terreny;
        }
        $n = count($x);
        $sortida = array_fill_keys(array_keys($punts), null);
        if ($n === 0) {
            return $sortida;
        }

        $finestra = self::finestres($x, self::FINESTRA_M / 2);
        $diferencia = array_map(fn ($a, $b) => $b - $a, $d, $m);
        $segona = [0.0];
        for ($i = 1; $i < $n - 1; $i++) {
            $segona[] = $d[$i] - ($d[$i - 1] + $d[$i + 1]) / 2;
        }
        $segona[] = 0.0;
        $quadrats = [0.0];
        foreach ($segona as $v) {
            $quadrats[] = end($quadrats) + $v * $v;
        }
        $soroll = self::finestres($x, self::SOROLL_M / 2);

        foreach ($grups as $i => $membres) {
            [$lo, $hi] = $finestra[$i];
            $corregit = $d[$i] + self::mediana(array_slice($diferencia, $lo, $hi - $lo));
            [$lo, $hi] = $soroll[$i];
            $sigma = sqrt(($quadrats[$hi] - $quadrats[$lo]) / max(1, $hi - $lo));
            $pes = min(1.0, max(0.0, (self::BRUT_M - $sigma) / (self::BRUT_M - self::NET_M)));
            $alt = round($pes * $corregit + (1 - $pes) * $m[$i], 2);
            foreach ($membres as $k) {
                $sortida[$k] = $alt;
            }
        }

        return $sortida;
    }

    /**
     * Per a cada node, els índexs [lo, hi) dels que són a menys de $mig metres; amb dos punters,
     * perquè les distàncies van creixent.
     *
     * @return array<int, array{int, int}>
     */
    private static function finestres(array $x, float $mig): array
    {
        $n = count($x);
        $lo = $hi = 0;
        $resultat = [];
        for ($i = 0; $i < $n; $i++) {
            while ($x[$lo] < $x[$i] - $mig) {
                $lo++;
            }
            while ($hi < $n && $x[$hi] <= $x[$i] + $mig) {
                $hi++;
            }
            $resultat[] = [$lo, $hi];
        }

        return $resultat;
    }

    private static function mediana(array $v): float
    {
        sort($v);
        $n = count($v);

        return $n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
    }
}
