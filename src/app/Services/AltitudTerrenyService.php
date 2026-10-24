<?php

namespace App\Services;

use App\Models\Preferencia;
use App\Models\SortidaBici;
use App\Support\AltitudCombinada;
use App\Support\Desnivell;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * L'altitud del terreny als punts de les sortides, de cada model que les cobreixi senceres (ICGC,
 * IGN). La llegeix scripts/altitud-terreny.mjs i es desa a `altituds_terreny` de la BD de
 * recorreguts. Especificació a .claude/specs/bicis-altitud-corregida.md.
 */
class AltitudTerrenyService
{
    /** Per ordre de preferència: la primera que cobreix la sortida és «la millor disponible». */
    public const FONTS = ['icgc', 'ign'];

    public const METODES = ['dispositiu', 'terreny', 'combinat'];

    /** Fins que no es tria res, l'altitud és la del dispositiu, com abans de tenir els models. */
    public const PER_DEFECTE = ['metode' => 'dispositiu', 'font' => 'millor'];

    public const CLAU_PREFERENCIA = 'bicis.altitud';

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
        $this->desaDesnivells($id);

        return $ambPosicio;
    }

    /**
     * Els totals de desnivell d'una sortida, de l'altitud del dispositiu, de cada model que la cobreix
     * i de la combinació de tots dos (`AltitudCombinada`), amb el mateix llindar que la pantalla
     * (`Desnivell`). Substitueix els que hi hagués.
     *
     * @return array<string, array{pujada: float, baixada: float}> per "metode/font"
     */
    public function desaDesnivells(int $id): array
    {
        $totals = [];
        foreach ($this->perfils($id) as $clau => $alts) {
            if (array_filter($alts, fn ($a) => $a !== null) !== []) {
                $totals[$clau] = Desnivell::calcula($alts);
            }
        }

        DB::transaction(function () use ($id, $totals) {
            DB::table('g_sortida_desnivells')->where('sortida_id', $id)->delete();
            foreach ($totals as $clau => $t) {
                [$metode, $font] = explode('/', $clau);
                DB::table('g_sortida_desnivells')->insert(['sortida_id' => $id, 'metode' => $metode, 'font' => $font,
                    'pujada_m' => $t['pujada'], 'baixada_m' => $t['baixada']]);
            }
        });

        return $totals;
    }

    /** El mètode i la font per defecte. */
    public function perDefecte(): array
    {
        return Preferencia::valor(self::CLAU_PREFERENCIA, self::PER_DEFECTE);
    }

    /**
     * El desnivell positiu de cada sortida tal com es mira, per a les llistes (fitxa de la bici,
     * rutes). Sense `$metode`, cada sortida amb la seva preferència o, si no en té, la de per defecte;
     * amb `$metode`, totes amb aquell mètode i la millor font que les cobreixi. Una font que no
     * cobreix la sortida cau a la que sí, i sense cap, al dispositiu. Amb el dispositiu, el desnivell
     * és el de Garmin Connect, com a la pàgina de la sortida.
     *
     * @param  iterable<SortidaBici>  $sortides  amb `id`, `desnivell_pos_m`, `altitud_metode` i `altitud_font`
     * @return array<int, array{pujada: ?float, metode: string}> per id de sortida
     */
    public function desnivellsTriats(iterable $sortides, ?string $metode = null): array
    {
        $sortides = collect($sortides);
        $desats = [];
        foreach ($sortides->pluck('id')->chunk(500) as $ids) {
            DB::table('g_sortida_desnivells')->whereIn('sortida_id', $ids->all())->where('metode', '!=', 'dispositiu')
                ->get(['sortida_id', 'metode', 'font', 'pujada_m'])
                ->each(function ($d) use (&$desats) {
                    $desats[$d->sortida_id]["{$d->metode}/{$d->font}"] = (float) $d->pujada_m;
                });
        }

        $defecte = $this->perDefecte();
        $resultat = [];
        foreach ($sortides as $s) {
            $tria = match (true) {
                $metode !== null                => ['metode' => $metode, 'font' => 'millor'],
                $s->altitud_metode !== null     => ['metode' => $s->altitud_metode, 'font' => $s->altitud_font ?? 'millor'],
                default                         => $defecte,
            };
            $pujada = null;
            if ($tria['metode'] !== 'dispositiu') {
                foreach (array_unique([$tria['font'], ...self::FONTS]) as $font) {
                    if (isset($desats[$s->id]["{$tria['metode']}/{$font}"])) {
                        $pujada = $desats[$s->id]["{$tria['metode']}/{$font}"];
                        break;
                    }
                }
            }
            $resultat[$s->id] = $pujada !== null
                ? ['pujada' => $pujada, 'metode' => $tria['metode']]
                : ['pujada' => $s->desnivell_pos_m !== null ? (float) $s->desnivell_pos_m : null, 'metode' => 'dispositiu'];
        }

        return $resultat;
    }

    /** El mètode demanat a una llista (`?altitud=`): buit o desconegut, el de cada sortida. */
    public static function metodeDemanat(?string $valor): ?string
    {
        return in_array($valor, self::METODES, true) ? $valor : null;
    }

    /**
     * El que la pantalla d'una sortida necessita per triar l'altitud: les fonts que la cobreixen,
     * la preferència per defecte i la de la sortida, i els totals de desnivell de cada perfil.
     */
    public function opcions(SortidaBici $sortida): array
    {
        $cobertes = DB::connection('recorreguts')->table('altituds_terreny_sortides')
            ->where('sortida_id', $sortida->id)->where('sense_dades', 0)->pluck('font')->all();

        return [
            'fonts'      => array_values(array_intersect(self::FONTS, $cobertes)),
            'defecte'    => $this->perDefecte(),
            'sortida'    => $sortida->altitud_metode === null ? null
                : ['metode' => $sortida->altitud_metode, 'font' => $sortida->altitud_font ?? 'millor'],
            'desnivells' => DB::table('g_sortida_desnivells')->where('sortida_id', $sortida->id)->get()
                ->mapWithKeys(fn ($d) => ["{$d->metode}/{$d->font}" => ['pujada' => (float) $d->pujada_m, 'baixada' => (float) $d->baixada_m]]),
        ];
    }

    /**
     * Els perfils d'altitud d'una sortida, en m i alineats amb els seus punts: el del dispositiu i,
     * per cada model que la cobreix, el del terreny i el combinat.
     *
     * @return array<string, array<int, ?float>> per "metode/font"
     */
    public function perfils(int $id): array
    {
        $recorreguts = DB::connection('recorreguts');
        $punts = $recorreguts->table('punts')->where('sortida_id', $id)->orderBy('t')->get(['t', 'dist', 'alt']);
        $perfils = ['dispositiu/dispositiu' => $punts->map(fn ($p) => $p->alt === null ? null : $p->alt / 100)->all()];

        $cobertes = $recorreguts->table('altituds_terreny_sortides')->where('sortida_id', $id)->where('sense_dades', 0)->pluck('font');
        foreach ($cobertes as $font) {
            $terreny = $recorreguts->table('altituds_terreny')->where('sortida_id', $id)->where('font', $font)->pluck('alt', 't');
            $entrada = $punts->map(fn ($p) => [
                $p->dist === null ? null : $p->dist / 100,
                $p->alt === null ? null : $p->alt / 100,
                isset($terreny[$p->t]) ? $terreny[$p->t] / 100 : null,
            ])->all();
            // El terreny, també sense les punxes del model: si no, a ciutat cada sot d'un punt és una pujada
            $perfils["terreny/{$font}"] = AltitudCombinada::terreny($entrada);
            $perfils["combinat/{$font}"] = AltitudCombinada::calcula($entrada);
        }

        return $perfils;
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
