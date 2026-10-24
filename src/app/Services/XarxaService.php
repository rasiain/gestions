<?php

namespace App\Services;

use App\Models\SortidaBici;
use Illuminate\Support\Facades\DB;

/**
 * La xarxa de camins que surt de les sortides: el graf on es busquen les propostes de sortida
 * (especificació a .claude/specs/bicis-propostes.md).
 *
 * 1. **Nodes per enganxament.** Cada sortida es remostreja cada PAS_M, i cada mostra s'enganxa al node
 *    més proper si n'hi ha un a menys de RADI_M; si no, en crea un. Les mostres seguides d'una sortida
 *    queden unides. Així una segona passada pel mateix camí, amb l'error del GPS, reaprofita els nodes de
 *    la primera, i dos recorreguts que es creuen comparteixen la cruïlla. Amb cel·les fixes, en canvi,
 *    dues passades del mateix camí queien sovint en cel·les veïnes i quedaven com dos camins paral·lels.
 *    Si dues mostres seguides cauen en nodes que no són veïns però ja hi ha un camí curt entre ells (fins
 *    a SALTS_DRECERA nodes), la passada marca aquell camí en lloc de crear-hi una drecera: si no, una
 *    passada que s'enganxa saltant-se un node (del 5 al 7) feia una cruïlla falsa, i la xarxa quedava en
 *    trams de 20 m.
 * 2. **Primer les millors gravacions** (més punts per segon): la geometria dels nodes és la de la
 *    primera passada que els crea.
 * 3. **Altitud**: la mitjana de l'altitud combinada de totes les passades (o la del dispositiu, si cap
 *    model no cobreix la sortida).
 * 4. **Trams**: les cadenes de nodes sense bifurcació es col·lapsen en un tram entre dues cruïlles. Un
 *    tram és apte per a carretera si per almenys APTE_CARRETERA dels seus nodes hi ha passat una bici de
 *    carretera o urbana. Es mira per node i no per aresta: una passada urbana que s'enganxava de costat
 *    marcava una aresta paral·lela que després la neteja treia, i el carrer quedava com a no apte.
 * 5. **Cruïlles properes fusionades.** Als camins més freqüentats les passades no sempre s'enganxen als
 *    mateixos nodes i fan una trena: cadenes paral·leles amb travessers curts, i cada travesser, dues
 *    cruïlles falses (11.000 dels 16.000 trams feien menys de 40 m). Les dues puntes d'un tram de menys de
 *    FUSIO_M entre cruïlles es fusionen en una, sempre que quedin a menys de FUSIO_M de la llavor del
 *    grup: si no, una trena d'un quilòmetre es col·lapsaria en un sol punt.
 * 6. **Neteja**, fins que no canvia res: fora els espolons de menys de ESPOLO_M (el GPS que se separa un
 *    moment del camí; els llargs es queden, que pujar i baixar pel mateix camí és una volta), els bucles
 *    d'un sol tram de menys de BUCLE_M i els trams paral·lels entre les mateixes dues cruïlles amb
 *    llargades semblants i que passen a menys de MATEIX_CAMI_M l'un de l'altre (el mateix camí gravat dues
 *    vegades: es queda el de més passades; dos camins diferents de la mateixa llargada es queden tots dos). Després es
 *    tornen a unir les cadenes que han deixat de tenir cruïlla.
 */
class XarxaService
{
    public const PAS_M = 10.0;

    public const RADI_M = 15.0;

    private const FUSIO_M = 30.0;

    private const APTE_CARRETERA = 0.8;

    private const MATEIX_CAMI_M = 20.0;

    private const ESPOLO_M = 50.0;

    private const BUCLE_M = 60.0;

    /** Fins a quants nodes es busca un camí que ja existeix abans de crear una drecera. */
    private const SALTS_DRECERA = 4;

    /** Un salt més llarg és un forat del GPS: no s'hi uneix res. */
    private const SALT_MAXIM_M = 200.0;

    /** Una passada amb una d'aquestes bicis fa el tram apte per a carretera. */
    public const MODALITATS_CARRETERA = ['carretera', 'urbana'];

    private const M_PER_GRAU = 111320.0;

    private const SEMICERCLE = 180 / 2 ** 31;

    /** @var array<int, float> coordenades planes (m) i geogràfiques (graus) de cada node */
    private array $x = [];

    private array $y = [];

    private array $lat = [];

    private array $lon = [];

    private array $altSuma = [];

    private array $altN = [];

    private array $passades = [];

    /** @var array<int, bool> si hi ha passat alguna bici de carretera o urbana */
    private array $carreteraNode = [];

    /** @var array<int, int> l'última sortida que ha passat per cada node, per comptar-les una vegada */
    private array $darreraSortida = [];

    /** @var array<int, array<int, int>> nodes per casella de RADI_M */
    private array $caselles = [];

    /** @var array<int, array<int, true>> veïns de cada node, mentre es construeix */
    private array $veins = [];

    /** @var array<string, array{carretera: bool, darrera: string, passades: int, ultima: int, sortides: array<int, true>}> per "a,b" */
    private array $arestes = [];

    /**
     * Construeix la xarxa amb totes les sortides amb recorregut.
     *
     * @param  callable(): void|null  $progres  un cop per sortida
     * @return array{nodes: int, arestes: int, trams: array<int, array>}
     */
    public function construeix(?callable $progres = null): array
    {
        $altitud = app(AltitudTerrenyService::class);
        $sortides = SortidaBici::query()
            ->join('g_vehicles', 'g_vehicles.id', '=', 'g_sortides_bici.vehicle_id')
            ->where('g_sortides_bici.punts', '>', 0)
            ->where('g_sortides_bici.tipus_activitat', '!=', 'indoor_cycling')
            ->orderByRaw('1.0 * g_sortides_bici.punts / max(coalesce(g_sortides_bici.temps_total_s, 1), 1) desc')
            ->get(['g_sortides_bici.id', 'g_sortides_bici.data_local', 'g_vehicles.modalitat']);

        foreach ($sortides as $s) {
            $perfils = $altitud->perfils($s->id);
            $alts = $perfils['combinat/icgc'] ?? $perfils['combinat/ign'] ?? $perfils['dispositiu/dispositiu'];
            $punts = DB::connection('recorreguts')->table('punts')->where('sortida_id', $s->id)->orderBy('t')->get(['lat', 'lon']);
            $this->afegeix($s->id, $s->data_local->toDateString(), in_array($s->modalitat, self::MODALITATS_CARRETERA, true), $punts->all(), $alts);
            $progres && $progres();
        }

        return ['nodes' => count($this->x), 'arestes' => count($this->arestes), 'trams' => $this->trams()];
    }

    /** Remostreja una sortida cada PAS_M i n'enganxa les mostres a la xarxa. */
    private function afegeix(int $sortida, string $data, bool $carretera, array $punts, array $alts): void
    {
        $anterior = null;      // [x, y, alt]
        $resta = 0.0;
        $nodeAnterior = null;

        $mostra = function (float $x, float $y, ?float $alt) use (&$nodeAnterior, $sortida, $data, $carretera) {
            $node = $this->enganxa($x, $y, $alt, $sortida, $carretera);
            if ($nodeAnterior !== null && $nodeAnterior !== $node) {
                // Un camí que ja hi és, si n'hi ha; si no, una aresta nova
                $cami = isset($this->veins[$nodeAnterior][$node]) ? [$nodeAnterior, $node] : ($this->camiCurt($nodeAnterior, $node) ?? [$nodeAnterior, $node]);
                for ($k = 1; $k < count($cami); $k++) {
                    $this->uneix($cami[$k - 1], $cami[$k], $sortida, $data, $carretera);
                }
            }
            $nodeAnterior = $node;
        };

        foreach ($punts as $i => $p) {
            if ($p->lat === null || $p->lon === null) {
                continue;
            }
            $lat = $p->lat * self::SEMICERCLE;
            $x = $p->lon * self::SEMICERCLE * self::M_PER_GRAU * cos(deg2rad($lat));
            $y = $lat * self::M_PER_GRAU;
            $alt = $alts[$i] ?? null;

            if ($anterior === null) {
                $mostra($x, $y, $alt);
                $anterior = [$x, $y, $alt];

                continue;
            }

            [$x0, $y0, $a0] = $anterior;
            $llarg = hypot($x - $x0, $y - $y0);
            if ($llarg > self::SALT_MAXIM_M) {
                // Un forat del GPS: es comença una cadena nova
                $nodeAnterior = null;
                $mostra($x, $y, $alt);
                $resta = 0.0;
            } else {
                $pos = self::PAS_M - $resta;
                while ($pos <= $llarg) {
                    $f = $pos / $llarg;
                    $mostra($x0 + ($x - $x0) * $f, $y0 + ($y - $y0) * $f, $a0 !== null && $alt !== null ? $a0 + ($alt - $a0) * $f : ($alt ?? $a0));
                    $pos += self::PAS_M;
                }
                $resta = $llarg - ($pos - self::PAS_M);
            }
            $anterior = [$x, $y, $alt];
        }
    }

    /** El node més proper a menys de RADI_M, o un de nou. */
    private function enganxa(float $x, float $y, ?float $alt, int $sortida, bool $carretera): int
    {
        $cx = (int) floor($x / self::RADI_M);
        $cy = (int) floor($y / self::RADI_M);
        $millor = null;
        $distancia = self::RADI_M;
        for ($dx = -1; $dx <= 1; $dx++) {
            for ($dy = -1; $dy <= 1; $dy++) {
                foreach ($this->caselles[($cx + $dx) . ',' . ($cy + $dy)] ?? [] as $n) {
                    $d = hypot($this->x[$n] - $x, $this->y[$n] - $y);
                    if ($d < $distancia) {
                        $distancia = $d;
                        $millor = $n;
                    }
                }
            }
        }

        if ($millor === null) {
            $millor = count($this->x);
            $this->x[] = $x;
            $this->y[] = $y;
            $this->lat[] = $y / self::M_PER_GRAU;
            $this->lon[] = $x / (self::M_PER_GRAU * cos(deg2rad($y / self::M_PER_GRAU)));
            $this->altSuma[] = 0.0;
            $this->altN[] = 0;
            $this->passades[] = 0;
            $this->darreraSortida[] = 0;
            $this->carreteraNode[] = false;
            $this->caselles["{$cx},{$cy}"][] = $millor;
        }
        $this->carreteraNode[$millor] = $this->carreteraNode[$millor] || $carretera;
        if ($alt !== null) {
            $this->altSuma[$millor] += $alt;
            $this->altN[$millor]++;
        }
        if ($this->darreraSortida[$millor] !== $sortida) {
            $this->darreraSortida[$millor] = $sortida;
            $this->passades[$millor]++;
        }

        return $millor;
    }

    /**
     * El camí més curt en nombre de nodes entre a i b per la xarxa ja feta, si n'hi ha un de fins a
     * SALTS_DRECERA nodes i no fa més del doble de la distància en línia recta (+ PAS_M).
     *
     * @return array<int, int>|null
     */
    private function camiCurt(int $a, int $b): ?array
    {
        $previ = [$a => null];
        $frontera = [$a];
        for ($salt = 0; $salt < self::SALTS_DRECERA && $frontera !== []; $salt++) {
            $seguent = [];
            foreach ($frontera as $n) {
                foreach ($this->veins[$n] ?? [] as $m => $_) {
                    if (array_key_exists($m, $previ)) {
                        continue;
                    }
                    $previ[$m] = $n;
                    if ($m === $b) {
                        $cami = [$b];
                        for ($c = $n; $c !== null; $c = $previ[$c]) {
                            $cami[] = $c;
                        }
                        $cami = array_reverse($cami);
                        $llarg = 0.0;
                        for ($k = 1; $k < count($cami); $k++) {
                            $llarg += hypot($this->x[$cami[$k]] - $this->x[$cami[$k - 1]], $this->y[$cami[$k]] - $this->y[$cami[$k - 1]]);
                        }

                        return $llarg <= 2 * hypot($this->x[$b] - $this->x[$a], $this->y[$b] - $this->y[$a]) + self::PAS_M ? $cami : null;
                    }
                    $seguent[] = $m;
                }
            }
            $frontera = $seguent;
        }

        return null;
    }

    private function uneix(int $a, int $b, int $sortida, string $data, bool $carretera): void
    {
        $this->veins[$a][$b] = true;
        $this->veins[$b][$a] = true;
        $clau = min($a, $b) . ',' . max($a, $b);
        $aresta = &$this->arestes[$clau];
        $aresta ??= ['carretera' => false, 'darrera' => $data, 'passades' => 0, 'ultima' => 0, 'sortides' => []];
        $aresta['carretera'] = $aresta['carretera'] || $carretera;
        $aresta['darrera'] = max($aresta['darrera'], $data);
        if ($aresta['ultima'] !== $sortida) {
            $aresta['ultima'] = $sortida;
            $aresta['passades']++;
            $aresta['sortides'][$sortida] = true;
        }
    }

    /**
     * Les cadenes de nodes entre cruïlles: un node és frontera si no té exactament dos veïns.
     *
     * @return array<int, array{nodes: array<int, int>, longitud_m: float, pujada_ab: float, pujada_ba: float, carretera: bool, passades: int, darrera: string, sortides: array<int, int>}>
     */
    private function trams(): array
    {
        $veins = [];
        foreach (array_keys($this->arestes) as $clau) {
            [$a, $b] = array_map('intval', explode(',', $clau));
            $veins[$a][] = $b;
            $veins[$b][] = $a;
        }
        $frontera = fn (int $n) => count($veins[$n]) !== 2;

        $fetes = [];
        $trams = [];
        $camina = function (int $inici, int $primer) use (&$fetes, &$trams, $veins, $frontera) {
            $nodes = [$inici];
            $anterior = $inici;
            $actual = $primer;
            $fetes[min($inici, $primer) . ',' . max($inici, $primer)] = true;
            while ($actual !== $inici && ! $frontera($actual)) {
                $nodes[] = $actual;
                $seguent = $veins[$actual][0] === $anterior ? $veins[$actual][1] : $veins[$actual][0];
                $fetes[min($actual, $seguent) . ',' . max($actual, $seguent)] = true;
                $anterior = $actual;
                $actual = $seguent;
            }
            $nodes[] = $actual;
            $trams[] = $this->tram($nodes);
        };

        foreach ($veins as $n => $llista) {
            if ($frontera($n)) {
                foreach ($llista as $m) {
                    if (! isset($fetes[min($n, $m) . ',' . max($n, $m)])) {
                        $camina($n, $m);
                    }
                }
            }
        }
        // El que queda són voltes tancades sense cap cruïlla
        foreach (array_keys($this->arestes) as $clau) {
            if (! isset($fetes[$clau])) {
                [$a, $b] = array_map('intval', explode(',', $clau));
                $camina($a, $b);
            }
        }

        return $this->neteja($this->fusionaCruilles($trams));
    }

    /** Fusiona les puntes dels trams curts entre cruïlles (vegeu el pas 5). */
    private function fusionaCruilles(array $trams): array
    {
        $extrems = fn (array $t) => [$t['nodes'][0], $t['nodes'][count($t['nodes']) - 1]];
        $grau = [];
        foreach ($trams as $t) {
            [$a, $b] = $extrems($t);
            $grau[$a] = ($grau[$a] ?? 0) + 1;
            $grau[$b] = ($grau[$b] ?? 0) + 1;
        }

        $pare = [];
        $arrel = function (int $n) use (&$pare, &$arrel): int {
            $pare[$n] ??= $n;

            return $pare[$n] === $n ? $n : ($pare[$n] = $arrel($pare[$n]));
        };
        $curts = array_filter($trams, function ($t) use ($grau, $extrems) {
            [$a, $b] = $extrems($t);

            return $a !== $b && $t['longitud_m'] < self::FUSIO_M && $grau[$a] > 1 && $grau[$b] > 1;
        });
        uasort($curts, fn ($p, $q) => $p['longitud_m'] <=> $q['longitud_m']);
        $fora = [];
        foreach ($curts as $i => $t) {
            [$ra, $rb] = array_map($arrel, $extrems($t));
            if ($ra === $rb) {
                $fora[$i] = true;
            } elseif (hypot($this->x[$ra] - $this->x[$rb], $this->y[$ra] - $this->y[$rb]) <= self::FUSIO_M) {
                // La llavor és el node amb més passades
                [$queda, $se] = $this->passades[$ra] >= $this->passades[$rb] ? [$ra, $rb] : [$rb, $ra];
                $pare[$se] = $queda;
                $fora[$i] = true;
            }
        }

        $resultat = [];
        foreach ($trams as $i => $t) {
            if (isset($fora[$i])) {
                continue;
            }
            $t['nodes'][0] = $arrel($t['nodes'][0]);
            $t['nodes'][count($t['nodes']) - 1] = $arrel($t['nodes'][count($t['nodes']) - 1]);
            $resultat[] = $t;
        }

        return $resultat;
    }

    private function neteja(array $trams): array
    {
        $extrems = fn (array $t) => [$t['nodes'][0], $t['nodes'][count($t['nodes']) - 1]];
        do {
            $abans = count($trams);

            $grau = [];
            foreach ($trams as $t) {
                [$a, $b] = $extrems($t);
                $grau[$a] = ($grau[$a] ?? 0) + 1;
                $grau[$b] = ($grau[$b] ?? 0) + 1;
            }
            $trams = array_values(array_filter($trams, function ($t) use ($grau, $extrems) {
                [$a, $b] = $extrems($t);
                if ($a === $b) {
                    return $t['longitud_m'] >= self::BUCLE_M;
                }

                return ! (($grau[$a] === 1 || $grau[$b] === 1) && $t['longitud_m'] < self::ESPOLO_M);
            }));

            // El mateix camí gravat dues vegades: dos trams entre les mateixes cruïlles i de llargada semblant
            $perParella = [];
            foreach ($trams as $i => $t) {
                [$a, $b] = $extrems($t);
                if ($a !== $b) {
                    $perParella[min($a, $b) . ',' . max($a, $b)][] = $i;
                }
            }
            $fora = [];
            foreach ($perParella as $index) {
                if (count($index) < 2) {
                    continue;
                }
                usort($index, fn ($i, $j) => $trams[$j]['passades'] <=> $trams[$i]['passades']);
                $guarda = $index[0];
                foreach (array_slice($index, 1) as $i) {
                    $l1 = $trams[$guarda]['longitud_m'];
                    $l2 = $trams[$i]['longitud_m'];
                    if (abs($l1 - $l2) <= 0.3 * max($l1, $l2) + 20 && $this->mateixCami($trams[$guarda]['nodes'], $trams[$i]['nodes'])) {
                        $trams[$guarda]['sortides'] = array_values(array_unique([...$trams[$guarda]['sortides'], ...$trams[$i]['sortides']]));
                        $trams[$guarda]['darrera'] = max($trams[$guarda]['darrera'], $trams[$i]['darrera']);
                        $fora[$i] = true;
                    }
                }
            }
            $trams = $this->uneixCadenes(array_values(array_diff_key($trams, $fora)));
        } while (count($trams) < $abans);

        return $trams;
    }

    /**
     * Si dos trams són el mateix camí: els punts de l'un passen a menys de MATEIX_CAMI_M de l'altre. No n'hi
     * ha prou que facin la mateixa llargada: dos camins diferents entre les mateixes cruïlles també poden.
     */
    private function mateixCami(array $p, array $q): bool
    {
        [$curt, $llarg] = count($p) <= count($q) ? [$p, $q] : [$q, $p];
        $mostra = array_slice($curt, 1, -1);
        $mostra = $mostra === [] ? $curt : array_values(array_filter($mostra, fn ($_, $k) => $k % max(1, intdiv(count($mostra), 20)) === 0, ARRAY_FILTER_USE_BOTH));
        foreach ($mostra as $n) {
            $minim = INF;
            foreach ($llarg as $m) {
                $minim = min($minim, hypot($this->x[$n] - $this->x[$m], $this->y[$n] - $this->y[$m]));
            }
            if ($minim > self::MATEIX_CAMI_M) {
                return false;
            }
        }

        return true;
    }

    /** Uneix els trams que es toquen en un node que ja no és cruïlla (només en té dos). */
    private function uneixCadenes(array $trams): array
    {
        $incidents = [];
        foreach ($trams as $i => $t) {
            $incidents[$t['nodes'][0]][] = $i;
            $incidents[$t['nodes'][count($t['nodes']) - 1]][] = $i;
        }
        $vius = array_fill_keys(array_keys($trams), true);

        foreach (array_keys($incidents) as $n) {
            $llista = array_values(array_filter($incidents[$n], fn ($i) => isset($vius[$i])));
            if (count($llista) !== 2 || $llista[0] === $llista[1]) {
                continue;
            }
            [$i, $j] = $llista;
            $primer = $trams[$i]['nodes'][0] === $n ? $this->inverteix($trams[$i]) : $trams[$i];
            $segon = $trams[$j]['nodes'][0] === $n ? $trams[$j] : $this->inverteix($trams[$j]);
            $nou = count($trams);
            $trams[$nou] = [
                'nodes'      => [...$primer['nodes'], ...array_slice($segon['nodes'], 1)],
                'longitud_m' => round($primer['longitud_m'] + $segon['longitud_m'], 1),
                'pujada_ab'  => round($primer['pujada_ab'] + $segon['pujada_ab'], 1),
                'pujada_ba'  => round($primer['pujada_ba'] + $segon['pujada_ba'], 1),
                'carretera'  => $this->esCarretera([...$primer['nodes'], ...array_slice($segon['nodes'], 1)]),
                'passades'   => min($primer['passades'], $segon['passades']),
                'darrera'    => min($primer['darrera'], $segon['darrera']),
                'sortides'   => array_values(array_unique([...$primer['sortides'], ...$segon['sortides']])),
            ];
            $vius[$nou] = true;
            unset($vius[$i], $vius[$j]);
            $lluny = [$primer['nodes'][0], $segon['nodes'][count($segon['nodes']) - 1]];
            foreach (array_unique($lluny) as $m) {
                $incidents[$m] = array_map(fn ($k) => $k === $i || $k === $j ? $nou : $k, $incidents[$m]);
            }
            $incidents[$n] = [];
        }

        return array_values(array_intersect_key($trams, $vius));
    }

    private function esCarretera(array $nodes): bool
    {
        $aptes = count(array_filter($nodes, fn ($n) => $this->carreteraNode[$n]));

        return $aptes >= self::APTE_CARRETERA * count($nodes);
    }

    private function inverteix(array $t): array
    {
        return ['nodes' => array_reverse($t['nodes']), 'pujada_ab' => $t['pujada_ba'], 'pujada_ba' => $t['pujada_ab']] + $t;
    }

    private function tram(array $nodes): array
    {
        $alt = fn (int $n) => $this->altN[$n] > 0 ? $this->altSuma[$n] / $this->altN[$n] : null;
        $longitud = $puja = $baixa = 0.0;
        $anteriorAlt = $alt($nodes[0]);
        $arestes = [];
        for ($i = 1; $i < count($nodes); $i++) {
            [$a, $b] = [$nodes[$i - 1], $nodes[$i]];
            $longitud += hypot($this->x[$b] - $this->x[$a], $this->y[$b] - $this->y[$a]);
            $arestes[] = $this->arestes[min($a, $b) . ',' . max($a, $b)];
            $h = $alt($b);
            if ($h !== null && $anteriorAlt !== null) {
                $h > $anteriorAlt ? $puja += $h - $anteriorAlt : $baixa += $anteriorAlt - $h;
            }
            $anteriorAlt = $h ?? $anteriorAlt;
        }
        $sortides = [];
        foreach ($arestes as $a) {
            $sortides += $a['sortides'];
        }

        return [
            'nodes'      => $nodes,
            'longitud_m' => round($longitud, 1),
            'pujada_ab'  => round($puja, 1),
            'pujada_ba'  => round($baixa, 1),
            'carretera'  => $this->esCarretera($nodes),
            // El tros menys recorregut: quantes sortides i quan va ser l'última
            'passades'   => min(array_column($arestes, 'passades')),
            'darrera'    => min(array_column($arestes, 'darrera')),
            'sortides'   => array_keys($sortides),
        ];
    }

    /** Substitueix la xarxa desada per la construïda. */
    public function desa(array $trams): void
    {
        $usats = [];
        foreach ($trams as $t) {
            foreach ($t['nodes'] as $n) {
                $usats[$n] = true;
            }
        }

        $bd = DB::connection('recorreguts');
        $bd->transaction(function () use ($bd, $trams, $usats) {
            foreach (['xarxa_tram_sortida', 'xarxa_trams', 'xarxa_nodes'] as $taula) {
                $bd->table($taula)->delete();
            }
            foreach (array_chunk(array_keys($usats), 200) as $tros) {
                $bd->table('xarxa_nodes')->insert(array_map(fn ($n) => [
                    'id'       => $n,
                    'lat'      => (int) round($this->lat[$n] * 1e6),
                    'lon'      => (int) round($this->lon[$n] * 1e6),
                    'alt'      => $this->altN[$n] > 0 ? (int) round($this->altSuma[$n] / $this->altN[$n] * 100) : null,
                    'passades' => $this->passades[$n],
                ], $tros));
            }
            $relacions = [];
            foreach (array_chunk($trams, 100, true) as $tros) {
                $bd->table('xarxa_trams')->insert(array_map(fn ($t, $id) => [
                    'id' => $id + 1, 'a' => $t['nodes'][0], 'b' => end($t['nodes']),
                    'longitud_m' => $t['longitud_m'], 'pujada_ab' => $t['pujada_ab'], 'pujada_ba' => $t['pujada_ba'],
                    'carretera' => (int) $t['carretera'], 'passades' => $t['passades'], 'darrera' => $t['darrera'],
                    'nodes' => json_encode($t['nodes']),
                ], $tros, array_keys($tros)));
                foreach ($tros as $id => $t) {
                    foreach ($t['sortides'] as $s) {
                        $relacions[] = ['tram_id' => $id + 1, 'sortida_id' => $s];
                    }
                }
            }
            foreach (array_chunk($relacions, 400) as $tros) {
                $bd->table('xarxa_tram_sortida')->insert($tros);
            }
        });
    }
}
