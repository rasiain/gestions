<?php

namespace App\Support;

/**
 * L'altitud combinada d'una sortida: el model del terreny dona el **nivell** i l'altímetre el **detall**,
 * on l'altímetre és de fiar. Especificació i calibratge a .claude/specs/bicis-altitud-corregida.md.
 *
 * 1. **Les aturades es col·lapsen**: on la bici no avança, l'altitud no pot canviar. Cada tram on la
 *    distància avança PAS_M és un node, amb l'altitud de l'últim punt del grup (després d'una aturada,
 *    la del baròmetre ja reajustat).
 * 2. **Les punxes del model es treuen** amb una mediana de PUNXA nodes a banda i banda. A ciutat, un
 *    punt del GPS que cau a la llera del riu, en un pati o en un pas soterrat fa un sot de 4–5 m d'un o
 *    dos punts, i cada un compta com una pujada: als trajectes de l'Albert's bike el model en treia
 *    5,5 m/km, i sense les punxes 3,5, el mateix que el rellotge quan anava bé.
 * 3. **Dispositiu corregit** = dispositiu + mediana mòbil de (terreny − dispositiu) en FINESTRA_M de
 *    distància. La mediana respecta els salts —la deriva d'una aturada llarga passa en una sola
 *    distància i es corregeix de cop— i no es deixa endur per un tram curt on el model diu una altra
 *    cosa: un pont (el model hi posa el fons de la vall) o un túnel.
 * 4. **On l'altímetre fa soroll, mana el model.** El soroll és el RMS de la segona diferència del
 *    dispositiu en SOROLL_M: per sota de NET_M el pes del dispositiu corregit és 1, per sobre de BRUT_M
 *    és 0. No es compara amb el soroll del model: de punt a punt, un bon altímetre i el model en fan
 *    el mateix, i fer-ne la mitjana s'enduia part del relleu.
 * 5. **Si el dispositiu infla el desnivell de tota la sortida, mana el model sencer.** Hi ha
 *    altímetres que no fan soroll sinó salts de 10 m (el Forerunner 935 des del 2025), i el pas 4 no
 *    els veu tots. Quan el desnivell del dispositiu passa de INFLAT_FACTOR vegades el del model més
 *    INFLAT_MARGE_M, l'altímetre no és de fiar en aquella sortida i el combinat és el terreny. Amb
 *    un aparell que va bé, tots dos donen el mateix (entre 0,9 i 1,07 vegades), i no hi arriba mai.
 */
class AltitudCombinada
{
    public const PAS_M = 2.0;

    /** Nodes a cada banda de la mediana que treu les punxes del model (5 en total). */
    public const PUNXA = 2;

    public const FINESTRA_M = 300.0;

    public const SOROLL_M = 200.0;

    public const NET_M = 0.2;

    public const BRUT_M = 0.5;

    public const INFLAT_FACTOR = 1.5;

    public const INFLAT_MARGE_M = 15.0;

    /**
     * @param  array<int, array{0: ?float, 1: ?float, 2: ?float}>  $punts  [distància, dispositiu, terreny] en m, per ordre
     * @return array<int, ?float> l'altitud combinada de cada punt, en m; null on no hi ha terreny
     */
    public static function calcula(array $punts): array
    {
        [$grups, $x, $d, $m] = self::nodes($punts);
        $n = count($x);
        if ($n === 0) {
            return array_fill_keys(array_keys($punts), null);
        }
        if (Desnivell::calcula($d)['pujada'] > self::INFLAT_FACTOR * Desnivell::calcula($m)['pujada'] + self::INFLAT_MARGE_M) {
            return self::alsPunts($punts, $grups, $m);
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

        $alts = [];
        for ($i = 0; $i < $n; $i++) {
            [$lo, $hi] = $finestra[$i];
            $corregit = $d[$i] + self::mediana(array_slice($diferencia, $lo, $hi - $lo));
            [$lo, $hi] = $soroll[$i];
            $sigma = sqrt(($quadrats[$hi] - $quadrats[$lo]) / max(1, $hi - $lo));
            $pes = min(1.0, max(0.0, (self::BRUT_M - $sigma) / (self::BRUT_M - self::NET_M)));
            $alts[] = $pes * $corregit + (1 - $pes) * $m[$i];
        }

        return self::alsPunts($punts, $grups, $alts);
    }

    /**
     * L'altitud del model del terreny tal com es fa servir: amb les aturades col·lapsades i sense les
     * punxes. És el perfil del mètode «terreny».
     *
     * @param  array<int, array{0: ?float, 1: ?float, 2: ?float}>  $punts  com a calcula()
     * @return array<int, ?float>
     */
    public static function terreny(array $punts): array
    {
        [$grups, , , $m] = self::nodes($punts);

        return self::alsPunts($punts, $grups, $m);
    }

    /**
     * Els nodes: només els punts amb terreny, un per cada PAS_M de distància (que no pot recular), amb
     * l'altitud de l'últim punt del grup. El model ja hi va sense punxes.
     *
     * @return array{0: array<int, array<int, int>>, 1: array<int, float>, 2: array<int, float>, 3: array<int, float>}
     *              [punts de cada node, distància, dispositiu, terreny]
     */
    private static function nodes(array $punts): array
    {
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
                $ultima = $distancia;
            }
            $i = count($grups) - 1;
            $grups[$i][] = $k;
            $x[$i] = $distancia;
            // Sense altitud del dispositiu, la del model
            $d[$i] = $disp ?? $terreny;
            $m[$i] = $terreny;
        }

        return [$grups, $x, $d, self::sensePunxes($m)];
    }

    private static function sensePunxes(array $m): array
    {
        $n = count($m);
        $resultat = [];
        for ($i = 0; $i < $n; $i++) {
            $lo = max(0, $i - self::PUNXA);
            $resultat[] = self::mediana(array_slice($m, $lo, min($n, $i + self::PUNXA + 1) - $lo));
        }

        return $resultat;
    }

    /** El valor de cada node, a tots els punts del seu grup; null als que no en tenen (sense terreny). */
    private static function alsPunts(array $punts, array $grups, array $valors): array
    {
        $sortida = array_fill_keys(array_keys($punts), null);
        foreach ($grups as $i => $membres) {
            foreach ($membres as $k) {
                $sortida[$k] = round($valors[$i], 2);
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
