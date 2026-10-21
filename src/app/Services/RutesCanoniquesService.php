<?php

namespace App\Services;

use App\Models\SortidaBici;
use Illuminate\Support\Facades\DB;
use SplPriorityQueue;

/**
 * Rutes canòniques: agrupa les sortides que fan el mateix recorregut i en tria una de
 * representant, a cada nivell de tolerància. Especificació a .claude/specs/bicis-rutes-canoniques.md.
 *
 * 1. **Empremta** de cada sortida: el recorregut remostrejat cada 25 m i convertit en el conjunt de
 *    cel·les de 50 m per on passa. Remostrejar a distància fixa fa que dues gravacions del mateix
 *    camí, amb punts cada segon o cada 30 m, tinguin la mateixa densitat; i com que el pas és més
 *    curt que la cel·la, també hi són les cel·les que travessen els segments entre punts.
 * 2. **Semblança** de cada parella: quina part de cada recorregut cau dins de l'altre —una cel·la
 *    seva o una de veïna, uns 50–75 m—, presa del costat que en comparteix menys. No mira l'ordre
 *    dels punts: el sentit de la ruta i on comença una volta són indiferents.
 * 3. **Agrupament per enllaç complet**, calculat un sol cop: dos grups s'ajunten si *totes* les
 *    sortides de l'un s'assemblen a totes les de l'altre. Cada nivell talla l'escala a una alçada, i
 *    per això els grups queden niats i cap no s'encadena (A ≈ B i B ≈ C no ajunta A amb C).
 * 4. **Representant**: el més central del grup i, entre els que gairebé ho són, el de millor gravació.
 */
class RutesCanoniquesService
{
    /** Nivells de tolerància: semblança mínima entre *tots* els membres d'un grup. */
    public const NIVELLS = [
        'identiques' => 0.90,
        'mateixa'    => 0.80,
        'variants'   => 0.65,
        'zona'       => 0.40,
    ];

    private const PAS_M = 25;

    private const CELLA_M = 50;

    /** Un salt més llarg és un forat del GPS: no s'hi interpola cap camí. */
    private const SALT_MAXIM_M = 1000;

    /** Una parella amb menys proporció de mides no pot arribar al nivell més ample. */
    private const PROPORCIO_MINIMA = 0.3;

    /** Entre els candidats a representant, els que són a menys d'això del més central. */
    private const MARGE_CENTRALITAT = 0.02;

    private const M_PER_GRAU = 111320.0;

    private const SEMICERCLE = 180 / 2 ** 31;

    // Clau d'una cel·la: (x + DESPL) * MULT + (y + DESPL). Veïnes: ±1 en y, ±MULT en x.
    private const DESPL = 1_000_000;

    private const MULT = 10_000_000;

    /** Les sortides que entren: amb recorregut real (els rodets no en tenen). */
    public function sortides()
    {
        return SortidaBici::where('punts', '>', 0)
            ->where('tipus_activitat', '!=', 'indoor_cycling')
            ->orderBy('id');
    }

    /**
     * L'empremta d'una sortida a partir dels seus punts (semicercles i segons, com a la BD).
     *
     * @param  iterable<object{t: int, lat: ?int, lon: ?int}>  $punts
     * @return array{celles: array<int, true>, n: int, x: array{int, int}, y: array{int, int}, qualitat: float}|null
     */
    public function empremta(iterable $punts): ?array
    {
        $celles = [];
        $anterior = null;
        $resta = 0.0;          // metres des de l'última mostra
        $n = 0;
        $salts = 0;
        $t0 = null;
        $tAnterior = null;

        foreach ($punts as $p) {
            $tAnterior !== null && $p->t - $tAnterior > 30 && $salts++;
            $t0 ??= $p->t;
            $tAnterior = $p->t;
            $n++;

            if ($p->lat === null || $p->lon === null) {
                continue;
            }

            $lat = $p->lat * self::SEMICERCLE;
            $x = $p->lon * self::SEMICERCLE * self::M_PER_GRAU * cos(deg2rad($lat));
            $y = $lat * self::M_PER_GRAU;

            if ($anterior === null) {
                $celles[$this->clau($x, $y)] = true;
                $anterior = [$x, $y];

                continue;
            }

            [$x0, $y0] = $anterior;
            $llarg = hypot($x - $x0, $y - $y0);

            if ($llarg > self::SALT_MAXIM_M) {
                $celles[$this->clau($x, $y)] = true;
                $resta = 0.0;
            } else {
                // Una mostra cada PAS_M al llarg del segment, continuant des de l'anterior
                $pos = self::PAS_M - $resta;
                while ($pos <= $llarg) {
                    $f = $pos / $llarg;
                    $celles[$this->clau($x0 + ($x - $x0) * $f, $y0 + ($y - $y0) * $f)] = true;
                    $pos += self::PAS_M;
                }
                $resta = $llarg - ($pos - self::PAS_M);
            }

            $anterior = [$x, $y];
        }

        if (count($celles) < 2) {
            return null;
        }

        $xs = array_map(fn ($k) => intdiv($k, self::MULT), array_keys($celles));
        $ys = array_map(fn ($k) => $k % self::MULT, array_keys($celles));
        $durada = max(1, $tAnterior - $t0);

        return [
            'celles'  => $celles,
            'n'        => count($celles),
            'x'        => [min($xs), max($xs)],
            'y'        => [min($ys), max($ys)],
            // Punts per segon (fins a 1) menys els forats de més de 30 s
            'qualitat' => round(min(1.0, $n / $durada) - 0.05 * $salts, 4),
        ];
    }

    private function clau(float $x, float $y): int
    {
        return ((int) floor($x / self::CELLA_M) + self::DESPL) * self::MULT + (int) floor($y / self::CELLA_M) + self::DESPL;
    }

    /** Quina part de les cel·les d'A cau en una cel·la de B o en una de les seves veïnes. */
    private function dins(array $a, array $b): float
    {
        $dins = 0;
        foreach ($a['celles'] as $k => $_) {
            if (isset($b['celles'][$k])
                || isset($b['celles'][$k + 1]) || isset($b['celles'][$k - 1])
                || isset($b['celles'][$k + self::MULT]) || isset($b['celles'][$k - self::MULT])
                || isset($b['celles'][$k + self::MULT + 1]) || isset($b['celles'][$k + self::MULT - 1])
                || isset($b['celles'][$k - self::MULT + 1]) || isset($b['celles'][$k - self::MULT - 1])) {
                $dins++;
            }
        }

        return $dins / $a['n'];
    }

    /** Semblança de dues empremtes: la part comuna, del costat que en comparteix menys. */
    public function semblanca(array $a, array $b): float
    {
        return min($this->dins($a, $b), $this->dins($b, $a));
    }

    /**
     * Les semblances de totes les parelles que arriben al nivell més ample. Abans de comparar-les
     * de debò, es descarten les que no es toquen o tenen mides massa diferents.
     *
     * @param  array<int, array>  $empremtes  per id de sortida
     * @return array<int, array{int, int, float}>  [a, b, valor] amb a < b
     */
    public function semblances(array $empremtes, ?callable $progres = null): array
    {
        $minim = min(self::NIVELLS);
        $ids = array_keys($empremtes);
        sort($ids);
        $parelles = [];

        foreach ($ids as $i => $a) {
            $ea = $empremtes[$a];
            for ($j = $i + 1, $total = count($ids); $j < $total; $j++) {
                $eb = $empremtes[$ids[$j]];

                if ($ea['x'][1] + 1 < $eb['x'][0] || $eb['x'][1] + 1 < $ea['x'][0]
                    || $ea['y'][1] + 1 < $eb['y'][0] || $eb['y'][1] + 1 < $ea['y'][0]) {
                    continue;
                }
                if (min($ea['n'], $eb['n']) / max($ea['n'], $eb['n']) < self::PROPORCIO_MINIMA) {
                    continue;
                }

                // El costat més llarg és el que en sol compartir menys: si no arriba, ni cal l'altre
                [$llarg, $curt] = $ea['n'] >= $eb['n'] ? [$ea, $eb] : [$eb, $ea];
                $primer = $this->dins($llarg, $curt);
                if ($primer < $minim) {
                    continue;
                }
                $valor = min($primer, $this->dins($curt, $llarg));
                if ($valor >= $minim) {
                    $parelles[] = [$a, $ids[$j], round($valor, 4)];
                }
            }
            $progres && $progres();
        }

        return $parelles;
    }

    /**
     * Agrupament jeràrquic per enllaç complet. Torna les fusions en l'ordre en què es fan, amb
     * l'alçada (la semblança mínima entre tots els membres del grup resultant), que no creix.
     *
     * @param  array<int, int>  $ids
     * @param  array<int, array{int, int, float}>  $parelles
     * @return array<int, array{int, int, float}>  [grup que queda, grup absorbit, alçada]
     */
    public function fusions(array $ids, array $parelles): array
    {
        $mida = array_fill_keys($ids, 1);
        // Per a cada parella de grups: quantes parelles de membres se n'han mesurat i la mínima
        $enllac = [];
        foreach ($parelles as [$a, $b, $v]) {
            $enllac[$a][$b] = $enllac[$b][$a] = [1, $v];
        }

        $cua = new SplPriorityQueue();
        $cua->setExtractFlags(SplPriorityQueue::EXTR_DATA);
        foreach ($parelles as [$a, $b, $v]) {
            $cua->insert([$a, $b, $v], $v);
        }

        $fusions = [];
        while (! $cua->isEmpty()) {
            [$a, $b, $v] = $cua->extract();

            // Una entrada vella: algun dels grups ja no hi és o l'enllaç ha canviat
            if (! isset($mida[$a], $mida[$b], $enllac[$a][$b])) {
                continue;
            }
            [$n, $min] = $enllac[$a][$b];
            if ($min !== $v || $n !== $mida[$a] * $mida[$b]) {
                continue;
            }

            $fusions[] = [$a, $b, $v];
            $mida[$a] += $mida[$b];

            foreach ($enllac[$b] as $c => [$nb, $mb]) {
                if ($c === $a) {
                    continue;
                }
                [$na, $ma] = $enllac[$a][$c] ?? [0, INF];
                $nou = [$na + $nb, min($ma, $mb)];
                $enllac[$a][$c] = $enllac[$c][$a] = $nou;
                unset($enllac[$c][$b]);

                // Només és fusionable si s'han mesurat totes les parelles de membres
                if ($nou[0] === $mida[$a] * $mida[$c]) {
                    $cua->insert([$a, $c, $nou[1]], $nou[1]);
                }
            }
            unset($enllac[$b], $enllac[$a][$b], $mida[$b]);
        }

        return $fusions;
    }

    /**
     * Els grups d'un nivell: les fusions de fins a aquella alçada.
     *
     * @return array<int, array<int, int>>  membres per grup
     */
    public function grups(array $ids, array $fusions, float $llindar): array
    {
        $grup = array_combine($ids, $ids);
        $arrel = function (int $x) use (&$grup, &$arrel): int {
            return $grup[$x] === $x ? $x : ($grup[$x] = $arrel($grup[$x]));
        };

        foreach ($fusions as [$a, $b, $alcada]) {
            if ($alcada < $llindar) {
                break;
            }
            $grup[$arrel($b)] = $arrel($a);
        }

        $grups = [];
        foreach ($ids as $id) {
            $grups[$arrel($id)][] = $id;
        }

        return array_values($grups);
    }

    /**
     * El representant d'un grup: el més central (més semblant de mitjana a la resta) i, entre els
     * que gairebé ho són, el de millor gravació.
     *
     * @param  array<int, int>  $membres
     * @param  array<int, array<int, float>>  $semblanca  per a, per b
     * @param  array<int, float>  $qualitat
     */
    public function representant(array $membres, array $semblanca, array $qualitat): int
    {
        if (count($membres) === 1) {
            return $membres[0];
        }

        $centralitat = [];
        foreach ($membres as $m) {
            $suma = 0.0;
            foreach ($membres as $o) {
                $m !== $o && $suma += $semblanca[$m][$o] ?? 0.0;
            }
            $centralitat[$m] = $suma / (count($membres) - 1);
        }

        $maxim = max($centralitat);
        $candidats = array_keys(array_filter($centralitat, fn ($c) => $c >= $maxim - self::MARGE_CENTRALITAT));
        usort($candidats, fn ($a, $b) => [$qualitat[$b] ?? 0, $centralitat[$b], -$b] <=> [$qualitat[$a] ?? 0, $centralitat[$a], -$a]);

        return $candidats[0];
    }

    /**
     * Desa les semblances (a la BD de recorreguts) i, per a cada nivell, les rutes i a quina
     * pertany cada sortida. Ho substitueix tot: són dades derivades.
     *
     * @return array<string, array<int, array{canonica: int, membres: array<int, int>}>>
     */
    public function desa(array $ids, array $parelles, array $fusions, array $qualitat): array
    {
        $semblanca = [];
        foreach ($parelles as [$a, $b, $v]) {
            $semblanca[$a][$b] = $semblanca[$b][$a] = $v;
        }

        $resultat = [];
        foreach (self::NIVELLS as $nivell => $llindar) {
            foreach ($this->grups($ids, $fusions, $llindar) as $membres) {
                $resultat[$nivell][] = ['canonica' => $this->representant($membres, $semblanca, $qualitat), 'membres' => $membres];
            }
        }

        $recorreguts = DB::connection('recorreguts');
        $recorreguts->transaction(function () use ($recorreguts, $parelles) {
            $recorreguts->table('semblances')->delete();
            foreach (array_chunk($parelles, 300) as $tros) {
                $recorreguts->table('semblances')->insert(array_map(fn ($p) => ['a' => $p[0], 'b' => $p[1], 'valor' => $p[2]], $tros));
            }
        });

        DB::transaction(function () use ($resultat, $semblanca) {
            DB::table('g_sortida_ruta')->delete();
            DB::table('g_rutes')->delete();
            $ara = now();

            foreach ($resultat as $nivell => $rutes) {
                foreach ($rutes as $ruta) {
                    $id = DB::table('g_rutes')->insertGetId([
                        'nivell' => $nivell, 'canonica_id' => $ruta['canonica'], 'sortides' => count($ruta['membres']),
                        'created_at' => $ara, 'updated_at' => $ara,
                    ]);
                    foreach (array_chunk($ruta['membres'], 200) as $tros) {
                        DB::table('g_sortida_ruta')->insert(array_map(fn ($m) => [
                            'sortida_id' => $m,
                            'nivell'     => $nivell,
                            'ruta_id'    => $id,
                            'semblanca'  => $m === $ruta['canonica'] ? 1 : ($semblanca[$m][$ruta['canonica']] ?? 0),
                        ], $tros));
                    }
                }
            }
        });

        return $resultat;
    }
}
