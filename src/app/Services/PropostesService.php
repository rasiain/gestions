<?php

namespace App\Services;

use App\Support\Desnivell;
use Illuminate\Support\Facades\DB;
use SplPriorityQueue;

/**
 * Propostes de sortida: voltes per la xarxa de camins (XarxaService) que s'acosten als km i al desnivell
 * demanats. Especificació a .claude/specs/bicis-propostes.md.
 *
 * 1. **Punts de gir**: des de l'inici, els nodes de la xarxa a entre GIR_MIN i GIR_MAX dels km demanats
 *    (per la xarxa), repartits per direccions i distàncies.
 * 2. **Anada i tornada**: a cada punt de gir s'hi va pel camí de menys cost —i també per una alternativa
 *    que l'evita, perquè surtin les voltes que deixen de banda el camí més curt— i es torna penalitzant
 *    els trams de l'anada (× REPETIR), també per dos camins: surt una volta, i només es repeteix camí si no n'hi ha cap altre
 *    (pujar i baixar pel mateix camí és una volta vàlida).
 * 3. **El desnivell s'empeny amb el cost**: cada tram costa la seva llargada × (1 + β · pendent en aquell
 *    sentit), amb diversos β (BETES): els negatius busquen pujades i els positius les eviten.
 * 4. **Els trams antics costen més**: + ANTIC_PER_ANY per any des de l'última passada, fins a ANTIC_MAXIM.
 * 5. **Puntuació**: com s'aparta dels km i del desnivell demanats (relatiu), amb el desnivell calculat com
 *    a la resta de l'app (`Desnivell`, llindar de 2,5 m) sobre l'altitud dels nodes. Sense tolerància.
 * 6. **Diversitat**: de les millors, se'n trien les que comparteixen menys de SOLAPAMENT_MAXIM entre elles.
 */
class PropostesService
{
    private const GIR_MIN = 0.2;

    private const GIR_MAX = 0.55;

    private const DIRECCIONS = 12;

    private const BANDES = 3;

    private const BETES = [-8.0, -4.0, 0.0, 4.0, 8.0];

    private const REPETIR = 4.0;

    private const ANTIC_PER_ANY = 0.15;

    private const ANTIC_MAXIM = 2.0;

    private const SOLAPAMENT_MAXIM = 0.6;

    /** Un tram que fa més d'això que no s'ha fet, a la proposta s'avisa. */
    public const ANYS_ANTIC = 3;

    private array $a = [];

    private array $b = [];

    private array $llargada = [];

    private array $pujada = [];   // [i][0] a→b, [i][1] b→a

    private array $edat = [];

    private array $darrera = [];

    private array $nodesTram = [];

    /** @var array<int, array<int, array{int, int, int}>> per node: [tram, sentit, node de l'altra banda] */
    private array $veins = [];

    /**
     * @return array{inici: array{node: int, lat: float, lon: float, distancia_m: float}, propostes: array<int, array>}
     */
    public function proposa(float $lat, float $lon, float $km, float $desnivell, string $tipus = 'btt', int $quantes = 5): array
    {
        $this->carrega($tipus);
        $inici = $this->nodeProper($lat, $lon);
        $objectiuM = $km * 1000;

        // Distàncies per la xarxa des de l'inici, per triar els punts de gir
        [$distancia] = $this->dijkstra($inici['node'], fn ($i, $s) => $this->llargada[$i]);
        $girs = $this->puntsDeGir($inici['node'], $distancia, $objectiuM);

        // Un tram que surt i torna a la mateixa cruïlla de l'inici ja és una volta
        $voltes = [];
        foreach ($this->veins[$inici['node']] ?? [] as [$i, $sentit, $m]) {
            if ($m === $inici['node'] && $sentit === 0) {
                $voltes[] = $this->avalua([[$i, 0]], $objectiuM, $desnivell);
            }
        }
        foreach (self::BETES as $beta) {
            $cost = fn ($i, $s) => $this->cost($i, $s, $beta);
            [, $anada] = $this->dijkstra($inici['node'], $cost);
            foreach ($girs as $gir) {
                $directe = $this->cami($anada, $inici['node'], $gir);
                if ($directe === null) {
                    continue;
                }
                // L'anada pel camí de menys cost i una alternativa que l'evita: si no, les voltes que deixen
                // de banda el camí més curt fins al punt de gir no sortirien mai
                $evitar = array_fill_keys(array_column($directe, 0), true);
                [, $alternativa] = $this->dijkstra($inici['node'], fn ($i, $s) => $this->cost($i, $s, $beta) * (isset($evitar[$i]) ? self::REPETIR : 1), $gir);
                $anades = [$directe];
                $alt = $this->cami($alternativa, $inici['node'], $gir);
                if ($alt !== null && $alt !== $directe) {
                    $anades[] = $alt;
                }
                foreach ($anades as $cami1) {
                    // La tornada, igual: la de menys cost evitant l'anada, i una alternativa que també l'evita
                    $usats = array_fill_keys(array_column($cami1, 0), true);
                    foreach ([0, 1] as $intent) {
                        [, $tornada] = $this->dijkstra($gir, fn ($i, $s) => $this->cost($i, $s, $beta) * (isset($usats[$i]) ? self::REPETIR : 1), $inici['node']);
                        $cami2 = $this->cami($tornada, $gir, $inici['node']);
                        if ($cami2 === null) {
                            break;
                        }
                        $voltes[] = $this->avalua([...$cami1, ...$cami2], $objectiuM, $desnivell);
                        $usats += array_fill_keys(array_column($cami2, 0), true);
                    }
                }
            }
        }

        usort($voltes, fn ($p, $q) => $p['error'] <=> $q['error']);
        $triades = [];
        foreach ($voltes as $v) {
            if (count($triades) >= $quantes) {
                break;
            }
            foreach ($triades as $t) {
                if ($this->solapament($v, $t) > self::SOLAPAMENT_MAXIM) {
                    continue 2;
                }
            }
            $triades[] = $v;
        }

        return ['inici' => $inici, 'propostes' => array_map(fn ($v) => $this->detalla($v), $triades)];
    }

    private function carrega(string $tipus): void
    {
        $avui = time();
        $trams = DB::connection('recorreguts')->table('xarxa_trams')
            ->when($tipus === 'carretera', fn ($q) => $q->where('carretera', 1))
            ->get(['id', 'a', 'b', 'longitud_m', 'pujada_ab', 'pujada_ba', 'darrera', 'nodes']);
        foreach ($trams as $t) {
            $i = $t->id;
            $this->a[$i] = $t->a;
            $this->b[$i] = $t->b;
            $this->llargada[$i] = max(1.0, (float) $t->longitud_m);
            $this->pujada[$i] = [(float) $t->pujada_ab, (float) $t->pujada_ba];
            $this->darrera[$i] = $t->darrera;
            $anys = max(0, ($avui - strtotime($t->darrera)) / (365.25 * 86400));
            $this->edat[$i] = min(self::ANTIC_MAXIM, 1 + self::ANTIC_PER_ANY * $anys);
            $this->nodesTram[$i] = $t->nodes;
            $this->veins[$t->a][] = [$i, 0, $t->b];
            $this->veins[$t->b][] = [$i, 1, $t->a];
        }
    }

    private function cost(int $i, int $sentit, float $beta): float
    {
        $pendent = $this->pujada[$i][$sentit] / $this->llargada[$i];

        return $this->llargada[$i] * max(0.2, 1 + $beta * $pendent) * $this->edat[$i];
    }

    /** La cruïlla de la xarxa més propera al punt demanat. */
    private function nodeProper(float $lat, float $lon): array
    {
        $cruilles = array_keys($this->veins);
        $coords = DB::connection('recorreguts')->table('xarxa_nodes')->whereIn('id', $cruilles)->get(['id', 'lat', 'lon']);
        $millor = null;
        $distancia = INF;
        $cos = cos(deg2rad($lat));
        foreach ($coords as $n) {
            $d = hypot(($n->lat / 1e6 - $lat) * 111320, ($n->lon / 1e6 - $lon) * 111320 * $cos);
            if ($d < $distancia) {
                $distancia = $d;
                $millor = $n;
            }
        }

        return ['node' => $millor->id, 'lat' => $millor->lat / 1e6, 'lon' => $millor->lon / 1e6, 'distancia_m' => round($distancia)];
    }

    /**
     * @param  callable(int $tram, int $sentit): float  $cost
     * @return array{0: array<int, float>, 1: array<int, array{int, int, int}>} distància i, per node, [tram, sentit, node anterior]
     */
    private function dijkstra(int $origen, callable $cost, ?int $desti = null): array
    {
        $distancia = [$origen => 0.0];
        $previ = [];
        $cua = new SplPriorityQueue;
        $cua->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
        $cua->insert($origen, 0);
        $fets = [];
        while (! $cua->isEmpty()) {
            $n = $cua->extract()['data'];
            if (isset($fets[$n])) {
                continue;
            }
            $fets[$n] = true;
            if ($n === $desti) {
                break;
            }
            foreach ($this->veins[$n] ?? [] as [$i, $sentit, $m]) {
                $d = $distancia[$n] + $cost($i, $sentit);
                if (! isset($distancia[$m]) || $d < $distancia[$m]) {
                    $distancia[$m] = $d;
                    $previ[$m] = [$i, $sentit, $n];
                    $cua->insert($m, -$d);
                }
            }
        }

        return [$distancia, $previ];
    }

    /** @return array<int, array{int, int}>|null [tram, sentit] de l'origen al destí */
    private function cami(array $previ, int $origen, int $desti): ?array
    {
        if ($origen === $desti) {
            return null;
        }
        $cami = [];
        for ($n = $desti; $n !== $origen; $n = $previ[$n][2]) {
            if (! isset($previ[$n])) {
                return null;
            }
            $cami[] = [$previ[$n][0], $previ[$n][1]];
        }

        return array_reverse($cami);
    }

    /** Uns quants nodes per direcció i banda de distància, els de més trams (cruïlles més importants). */
    private function puntsDeGir(int $inici, array $distancia, float $objectiuM): array
    {
        $dins = array_keys(array_filter($distancia, fn ($d) => $d >= self::GIR_MIN * $objectiuM && $d <= self::GIR_MAX * $objectiuM));
        // Una xarxa petita (la de carretera) pot no tenir cap cruïlla a la franja: aleshores, totes
        if ($dins === []) {
            $dins = array_keys(array_filter($distancia, fn ($d) => $d > 0));
        }
        $coords = DB::connection('recorreguts')->table('xarxa_nodes')->whereIn('id', $dins)->get(['id', 'lat', 'lon'])->keyBy('id');
        $origen = DB::connection('recorreguts')->table('xarxa_nodes')->find($inici);
        $caselles = [];
        foreach ($coords as $id => $n) {
            $angle = atan2($n->lat - $origen->lat, ($n->lon - $origen->lon) * cos(deg2rad($origen->lat / 1e6)));
            $direccio = (int) floor(($angle + M_PI) / (2 * M_PI) * self::DIRECCIONS) % self::DIRECCIONS;
            $banda = max(0, min(self::BANDES - 1, (int) floor(($distancia[$id] / $objectiuM - self::GIR_MIN) / (self::GIR_MAX - self::GIR_MIN) * self::BANDES)));
            $clau = "{$direccio},{$banda}";
            if (! isset($caselles[$clau]) || count($this->veins[$id]) > count($this->veins[$caselles[$clau]])) {
                $caselles[$clau] = $id;
            }
        }

        return array_values($caselles);
    }

    /** Els nodes de la volta, en ordre, amb km i desnivell; i l'error respecte del que es demanava. */
    private function avalua(array $cami, float $objectiuM, float $desnivell): array
    {
        $nodes = [];
        $llargada = 0.0;
        foreach ($cami as [$i, $sentit]) {
            $tram = json_decode($this->nodesTram[$i], true);
            $tram = $sentit === 0 ? $tram : array_reverse($tram);
            array_push($nodes, ...($nodes === [] ? $tram : array_slice($tram, 1)));
            $llargada += $this->llargada[$i];
        }
        $alts = $this->altituds($nodes);
        $pujada = Desnivell::calcula(array_map(fn ($a) => $a === null ? null : $a / 100, $alts))['pujada'];

        return [
            'cami'       => $cami,
            'nodes'      => $nodes,
            'longitud_m' => $llargada,
            'pujada_m'   => $pujada,
            'error'      => abs($llargada - $objectiuM) / $objectiuM + abs($pujada - $desnivell) / max($desnivell, 50),
        ];
    }

    private array $altitudsNodes = [];

    private function altituds(array $nodes): array
    {
        $falten = array_values(array_diff(array_unique($nodes), array_keys($this->altitudsNodes)));
        foreach (array_chunk($falten, 900) as $tros) {
            foreach (DB::connection('recorreguts')->table('xarxa_nodes')->whereIn('id', $tros)->get(['id', 'alt']) as $n) {
                $this->altitudsNodes[$n->id] = $n->alt;
            }
        }

        return array_map(fn ($n) => $this->altitudsNodes[$n] ?? null, $nodes);
    }

    /**
     * Quina part de la més curta comparteixen dues voltes, comptant cada tram un sol cop: una volta d'anar
     * i tornar pel mateix camí passa dues vegades per cada tram, i comparada amb la llargada total, dues
     * voltes idèntiques sortien amb un 50 %.
     */
    private function solapament(array $p, array $q): float
    {
        $trams = fn (array $v) => array_values(array_unique(array_column($v['cami'], 0)));
        $llarg = fn (array $ids) => array_sum(array_map(fn ($i) => $this->llargada[$i], $ids));
        [$tp, $tq] = [$trams($p), $trams($q)];

        return $llarg(array_intersect($tp, $tq)) / max(1.0, min($llarg($tp), $llarg($tq)));
    }

    /** El que es mostra d'una proposta: traçat, xifres, trams antics i de quines sortides surt. */
    private function detalla(array $v): array
    {
        $coords = [];
        foreach (array_chunk(array_values(array_unique($v['nodes'])), 900) as $tros) {
            foreach (DB::connection('recorreguts')->table('xarxa_nodes')->whereIn('id', $tros)->get(['id', 'lat', 'lon']) as $n) {
                $coords[$n->id] = [$n->lat / 1e6, $n->lon / 1e6];
            }
        }
        $limit = date('Y-m-d', strtotime('-' . self::ANYS_ANTIC . ' years'));
        $antics = array_sum(array_map(fn ($c) => $this->darrera[$c[0]] < $limit ? $this->llargada[$c[0]] : 0, $v['cami']));
        // Els trams que la volta fa dues vegades (anar i tornar pel mateix camí), comptats un cop
        $vegades = array_count_values(array_column($v['cami'], 0));
        $repetits = array_sum(array_map(fn ($i) => $this->llargada[$i], array_keys(array_filter($vegades, fn ($n) => $n > 1))));

        // De quines sortides surt: les que comparteixen més km amb la volta
        $perSortida = [];
        foreach (DB::connection('recorreguts')->table('xarxa_tram_sortida')->whereIn('tram_id', array_unique(array_column($v['cami'], 0)))->get() as $r) {
            $perSortida[$r->sortida_id] = ($perSortida[$r->sortida_id] ?? 0) + $this->llargada[$r->tram_id];
        }
        arsort($perSortida);

        return [
            'km'          => round($v['longitud_m'] / 1000, 1),
            'desnivell'   => (int) round($v['pujada_m']),
            'error'       => round($v['error'], 3),
            'km_antics'   => round($antics / 1000, 1),
            'km_repetits' => round($repetits / 1000, 1),
            'trams'       => count($v['cami']),
            'punts'       => array_map(fn ($n, $alt) => [...$coords[$n], $alt === null ? null : round($alt / 100, 1)], $v['nodes'], $this->altituds($v['nodes'])),
            'sortides'    => array_map(fn ($id, $m) => ['id' => $id, 'km' => round($m / 1000, 1)], array_keys(array_slice($perSortida, 0, 10, true)), array_slice($perSortida, 0, 10, true)),
        ];
    }

    /** Un GPX amb el traçat i l'altitud d'una proposta: el que importen Wikiloc, Garmin Connect i Komoot. */
    public static function gpx(array $proposta, string $nom): string
    {
        $punts = implode("\n", array_map(fn ($p) => sprintf('      <trkpt lat="%.6f" lon="%.6f">%s</trkpt>', $p[0], $p[1],
            $p[2] === null ? '' : sprintf('<ele>%.1f</ele>', $p[2])), $proposta['punts']));
        $nom = htmlspecialchars($nom, ENT_XML1);

        return <<<GPX
<?xml version="1.0" encoding="UTF-8"?>
<gpx version="1.1" creator="gestions" xmlns="http://www.topografix.com/GPX/1/1">
  <metadata><name>{$nom}</name></metadata>
  <trk>
    <name>{$nom}</name>
    <trkseg>
{$punts}
    </trkseg>
  </trk>
</gpx>

GPX;
    }
}
