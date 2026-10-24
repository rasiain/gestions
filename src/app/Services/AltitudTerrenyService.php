<?php

namespace App\Services;

use App\Models\SortidaBici;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * L'altitud del terreny als punts de les sortides, de cada model que les cobreixi senceres (ICGC,
 * IGN). La llegeix scripts/altitud-terreny.mjs i es desa a `altituds_terreny` de la BD de
 * recorreguts. Especificació a .claude/specs/bicis-altitud-corregida.md.
 */
class AltitudTerrenyService
{
    public const FONTS = ['icgc', 'ign'];

    private const SEMICERCLE = 180 / 2 ** 31;

    /** Mida de la quadrícula per ordenar les sortides per zona, en graus. */
    private const ZONA = 0.05;

    /** Les sortides que en poden tenir: amb recorregut real (els rodets no en tenen). */
    public function sortides()
    {
        return SortidaBici::where('punts', '>', 0)->where('tipus_activitat', '!=', 'indoor_cycling');
    }

    /** Les que encara no s'han llegit. */
    public function pendents(): array
    {
        $fetes = DB::connection('recorreguts')->table('altituds_terreny_sortides')->distinct()->pluck('sortida_id')->all();

        return array_values(array_diff($this->sortides()->pluck('id')->all(), $fetes));
    }

    /**
     * Llegeix l'altitud del terreny de les sortides i, si no és una prova, la desa (substituint el que
     * hi hagués d'aquelles sortides).
     *
     * @param  array<int, int>  $ids
     * @param  callable(int $id, array $resultat): void|null  $progres
     * @return array{sortides: array<int, array{punts: int, sense_dades: array<string, int>}>, fulls: array<string, int>}
     */
    public function calcula(array $ids, bool $desa = true, ?callable $progres = null): array
    {
        $ordre = $this->perZona($ids);
        if ($ordre === []) {
            return ['sortides' => [], 'fulls' => []];
        }

        $entrada = tempnam(sys_get_temp_dir(), 'alt-');
        $fitxer = fopen($entrada, 'w');
        foreach ($ordre as $id) {
            $punts = $this->punts($id)->map(fn ($p) => $p->lat === null || $p->lon === null
                ? null
                : [$p->lat * self::SEMICERCLE, $p->lon * self::SEMICERCLE]);
            fwrite($fitxer, json_encode(['id' => $id, 'punts' => $punts]) . "\n");
        }
        fclose($fitxer);

        $resultats = [];
        $pendent = '';
        $proces = new Process(['node', base_path('scripts/altitud-terreny.mjs'), storage_path('app/models')], base_path());
        $proces->setInput(fopen($entrada, 'r'))->setTimeout(null)->disableOutput();
        $estadistica = '';
        try {
            // Cada línia de sortida es desa en arribar: el resultat de totes alhora no cap a la memòria
            $proces->mustRun(function ($tipus, $tros) use (&$pendent, &$resultats, &$estadistica, $desa, $progres) {
                if ($tipus === Process::ERR) {
                    $estadistica .= $tros;

                    return;
                }
                $pendent .= $tros;
                while (($salt = strpos($pendent, "\n")) !== false) {
                    $linia = json_decode(substr($pendent, 0, $salt), true);
                    $pendent = substr($pendent, $salt + 1);
                    $resultat = ['punts' => 0, 'sense_dades' => $linia['sense_dades']];
                    $resultat['punts'] = $this->tracta($linia, $desa);
                    $resultats[$linia['id']] = $resultat;
                    $progres && $progres($linia['id'], $resultat);
                }
            });
        } finally {
            @unlink($entrada);
        }

        return ['sortides' => $resultats, 'fulls' => json_decode(trim($estadistica), true)['fulls_llegits'] ?? []];
    }

    /** Desa una línia del lector; torna quants punts amb posició té la sortida. */
    private function tracta(array $linia, bool $desa): int
    {
        $id = $linia['id'];
        $punts = $this->punts($id)->all();
        $ambPosicio = count(array_filter($punts, fn ($p) => $p->lat !== null && $p->lon !== null));
        if (! $desa) {
            return $ambPosicio;
        }

        $bd = DB::connection('recorreguts');
        $bd->transaction(function () use ($bd, $id, $punts, $linia, $ambPosicio) {
            $bd->table('altituds_terreny')->where('sortida_id', $id)->delete();
            $bd->table('altituds_terreny_sortides')->where('sortida_id', $id)->delete();
            $ara = now()->toDateTimeString();
            foreach (self::FONTS as $font) {
                $alts = $linia['fonts'][$font] ?? null;
                $bd->table('altituds_terreny_sortides')->insert([
                    'sortida_id' => $id, 'font' => $font, 'punts' => $ambPosicio,
                    'sense_dades' => $alts === null ? ($linia['sense_dades'][$font] ?? $ambPosicio) : 0,
                    'calculat_at' => $ara,
                ]);
                if ($alts === null) {
                    continue;
                }
                $files = [];
                foreach ($punts as $i => $p) {
                    if ($alts[$i] !== null) {
                        $files[] = ['sortida_id' => $id, 'font' => $font, 't' => $p->t, 'alt' => $alts[$i]];
                    }
                }
                foreach (array_chunk($files, 200) as $tros) {
                    $bd->table('altituds_terreny')->insert($tros);
                }
            }
        });

        return $ambPosicio;
    }

    private function punts(int $id)
    {
        return DB::connection('recorreguts')->table('punts')->where('sortida_id', $id)->orderBy('t')->get(['t', 'lat', 'lon']);
    }

    /**
     * Les sortides ordenades per zona —pel centre del rectangle que ocupen, en una quadrícula
     * recorreguda en zig-zag—: les veïnes passen pels mateixos fulls, i el lector, que guarda els
     * últims que ha llegit, els llegeix un sol cop.
     */
    private function perZona(array $ids): array
    {
        $sortides = SortidaBici::whereIn('id', $ids)->get(['id', 'lat_min', 'lat_max', 'lon_min', 'lon_max']);
        $clau = function ($s) {
            if ($s->lat_min === null) {
                return [PHP_INT_MAX, 0, $s->id];
            }
            $fila = (int) floor(($s->lat_min + $s->lat_max) / 2 / self::ZONA);
            $columna = (int) floor(($s->lon_min + $s->lon_max) / 2 / self::ZONA);

            return [$fila, $fila % 2 ? -$columna : $columna, $s->id];
        };

        return $sortides->sortBy(fn ($s) => $clau($s))->pluck('id')->values()->all();
    }
}
