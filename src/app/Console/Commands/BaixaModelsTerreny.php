<?php

namespace App\Console\Commands;

use App\Support\TiffSimple;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Els models del terreny per corregir l'altitud de les sortides (especificació a
 * .claude/specs/bicis-altitud-corregida.md): l'ICGC de 2 m per a Catalunya i l'IGN de 5 m per a tot
 * Espanya. Van a storage/app/models/, fora del repositori.
 *
 * - **ICGC**: el model de 2 m es reparteix en 4.275 fulls (el tall 1:5.000), i el seu servidor de
 *   descàrregues en fa ZIP a partir d'una llista de fulls, de com a molt 767 MB; abans en diu la mida
 *   (`checkZipSize`).
 * - **IGN**: el WCS de l'IDEE dona el model de 5 m per qualsevol rectangle en coordenades geogràfiques,
 *   de com a molt 0,1° de costat, i el comprimeix si se li demana. Com que el rectangle d'Espanya és
 *   sobretot mar i països veïns, els trossos amb terra es trien amb el mateix model a 1 km.
 *
 * Es pot interrompre i tornar a executar: el que ja és a disc no es torna a baixar. Amb --prova, diu
 * quants fitxers falten i quant ocupen sense baixar res (de l'IGN en baixa uns quants de mostra a
 * una carpeta temporal per estimar-ne la mida i el temps).
 *
 * Es desen tal com venen: de l'ICGC, el ZIP de cada full (una graella ESRI ASCII en UTM 31N, a 2 m i
 * al centímetre, ~5,4 MB comprimida i 22 MB sense); de l'IGN, el GeoTIFF comprimit de cada tros.
 */
class BaixaModelsTerreny extends Command
{
    protected $signature = 'sortides:baixa-models
                            {--font= : icgc o ign (per defecte, totes dues)}
                            {--mostres=8 : Trossos de l\'IGN que es baixen per estimar-ne la mida}
                            {--concurrencia=8 : Peticions simultànies a cada servidor}
                            {--prova : Només diu què baixaria i quant ocuparia}';

    protected $description = "Baixa els models del terreny de l'ICGC (Catalunya, 2 m) i de l'IGN (Espanya, 5 m)";

    private const ICGC_INDEX = 'https://visors.icgc.cat/appdownloads/lib/json/tall5m.json';

    private const ICGC_SERVIDOR = 'https://descarregues.icgc.cat';

    private const ICGC_SERIE_MET2 = 25;

    /**
     * Fulls per petició (uns 5,4 MB cadascun). El servidor limita cada connexió a uns 200–400 KB/s però
     * no el total (mesurat: 8 connexions, 1,3 MB/s): val més molts ZIP petits alhora que pocs de grans.
     */
    private const ICGC_FULLS_PER_ZIP = 5;

    private const IGN_WCS = 'https://servicios.idee.es/wcs-inspire/mdt';

    /** El tros més gran que el WCS accepta (0,25° ja el rebutja). */
    private const IGN_TROS = 0.1;

    /** Rectangles on hi ha Espanya: la Península i les Balears, i les Canàries. */
    private const IGN_ZONES = [
        'Península i Balears' => [35.8, 44.0, -9.5, 4.5],
        'Canàries'            => [27.5, 29.5, -18.3, -13.3],
    ];

    public function handle(): int
    {
        $font = $this->option('font');
        if ($font !== null && ! in_array($font, ['icgc', 'ign'], true)) {
            $this->error('--font ha de ser icgc o ign.');

            return self::FAILURE;
        }

        if (! $this->option('prova')) {
            ($font === null || $font === 'icgc') && $this->baixaIcgc();
            ($font === null || $font === 'ign') && $this->baixaIgn();

            return self::SUCCESS;
        }

        $files = [];
        if ($font === null || $font === 'icgc') {
            $files[] = $this->provaIcgc();
        }
        if ($font === null || $font === 'ign') {
            $files[] = $this->provaIgn();
        }

        $this->newLine();
        $this->table(['Font', 'Fitxers que falten', 'Ja baixats', 'Mida', 'Temps estimat'], $files);
        $lliure = disk_free_space(storage_path());
        $this->line('Espai lliure al disc: ' . $this->mida($lliure) . '. Destí: storage/app/models/.');
        $this->info('Amb --prova no s\'ha desat res.');

        return self::SUCCESS;
    }

    private function provaIcgc(): array
    {
        $this->info('ICGC: índex de fulls i mida al servidor…');
        $fulls = $this->fullsIcgc();

        $falten = $this->fullsIcgcQueFalten($fulls);

        $total = 0;
        $barra = $this->output->createProgressBar(count($falten));
        foreach (array_chunk($falten, self::ICGC_FULLS_PER_ZIP) as $grup) {
            $url = self::ICGC_SERVIDOR . '/checkZipSize/' . implode('&', $grup) . '&/' . self::ICGC_SERIE_MET2 . '/tif';
            $total += (int) Http::timeout(60)->get($url)->throw()->json('zipsize');
            $barra->advance(count($grup));
        }
        $barra->finish();
        $this->newLine();

        return ['ICGC 2 m (Catalunya)', number_format(count($falten), 0, ',', '.') . ' fulls', count($fulls) - count($falten),
            $this->mida($total), '—'];
    }

    /** @return array<int, string> identificadors dels fulls de l'ICGC */
    private function fullsIcgc(): array
    {
        $index = Http::timeout(60)->get(self::ICGC_INDEX)->throw()->json('features');

        // L'identificador del full (IDABS) va sense el guionet a les peticions: «253-055» → «253055»
        return array_map(fn ($f) => str_replace('-', '', $f['properties']['IDABS']), $index);
    }

    private function fullsIcgcQueFalten(array $fulls): array
    {
        $carpeta = storage_path('app/models/icgc');

        return array_values(array_filter($fulls, fn ($f) => ! is_file("{$carpeta}/{$f}.zip")));
    }

    /**
     * Els fulls de l'ICGC, en grups: el servidor en prepara un ZIP i torna una pàgina amb l'enllaç;
     * el ZIP porta un ZIP per full, que és el que es desa. Hi ha uns quants grups en curs alhora, en una
     * cua: quan un acaba, n'entra un altre, sense esperar el més lent. Un grup que falla es deixa per a
     * la propera vegada.
     */
    private function baixaIcgc(): void
    {
        $carpeta = storage_path('app/models/icgc');
        File::ensureDirectoryExists($carpeta);
        $falten = $this->fullsIcgcQueFalten($this->fullsIcgc());
        $grups = array_chunk($falten, self::ICGC_FULLS_PER_ZIP);
        $this->info('ICGC: ' . count($falten) . ' fulls per baixar, en ' . count($grups) . ' grups.');
        $temporal = storage_path('app/tmp/icgc-' . uniqid());
        File::ensureDirectoryExists($temporal);

        $client = new \GuzzleHttp\Client(['connect_timeout' => 30, 'timeout' => 1800]);
        $desats = 0;
        $errors = 0;
        $peticions = function () use ($grups, $client, $temporal, $carpeta, &$desats, &$errors) {
            foreach ($grups as $n => $grup) {
                $fitxer = "{$temporal}/grup-{$n}.zip";
                $url = self::ICGC_SERVIDOR . '/generateZip/' . md5(uniqid('', true)) . '/' . implode('&', $grup) . '&/' . self::ICGC_SERIE_MET2 . '/tif';
                yield $client->getAsync($url, ['timeout' => 600])
                    ->then(function ($pagina) use ($client, $fitxer) {
                        if (! preg_match('/href="([^"]+\.zip)"/', (string) $pagina->getBody(), $m)) {
                            throw new \RuntimeException('cap enllaç: ' . strip_tags((string) $pagina->getBody()));
                        }

                        return $client->getAsync($m[1], ['sink' => $fitxer]);
                    })
                    ->then(function () use ($fitxer, $carpeta, &$desats) {
                        $desats += $this->desaFullsIcgc($fitxer, $carpeta);
                    })
                    ->otherwise(function ($e) use ($fitxer, $n, &$errors) {
                        @unlink($fitxer);
                        $errors++;
                        $this->warn("  grup {$n}: " . ($e instanceof \Throwable ? $e->getMessage() : (string) $e));
                    });
            }
        };

        try {
            $abans = 0;
            \GuzzleHttp\Promise\Each::ofLimit($peticions(), max(1, (int) $this->option('concurrencia')), function () use (&$abans, &$desats, &$errors, $falten) {
                if (intdiv($desats, 100) > intdiv($abans, 100)) {
                    $this->line(sprintf('  %d/%d fulls desats (%d grups amb error)', $desats, count($falten), $errors));
                }
                $abans = $desats;
            })->wait();
        } finally {
            File::deleteDirectory($temporal);
        }

        $this->info("ICGC: fet, {$desats} fulls ({$errors} grups amb error; es tornen a provar en tornar-la a executar).");
    }

    /** Treu els ZIP de cada full del ZIP d'un grup; torna quants n'ha desat. */
    private function desaFullsIcgc(string $fitxer, string $carpeta): int
    {
        $zip = new \ZipArchive();
        if ($zip->open($fitxer) !== true) {
            @unlink($fitxer);
            throw new \RuntimeException('ZIP malmès');
        }
        $desats = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            // met2v10as0f253055mr1r010.zip → 253055.zip; primer a .part, que un full a mitges no compti com a baixat
            if (preg_match('/f(\d{6})mr/', $zip->getNameIndex($i), $f)) {
                copy("zip://{$fitxer}#" . $zip->getNameIndex($i), "{$carpeta}/{$f[1]}.zip.part");
                rename("{$carpeta}/{$f[1]}.zip.part", "{$carpeta}/{$f[1]}.zip");
                $desats++;
            }
        }
        $zip->close();
        unlink($fitxer);

        return $desats;
    }

    /**
     * Els trossos de l'IGN pel WCS, uns quants alhora: el que triga és que el servidor generi cada tros
     * (~1,5 s per 2 MB), no la transferència, i en paral·lel el rendiment creix gairebé linealment
     * (mesurat: 1 → 1,25 MB/s, 12 → 12 MB/s). Un tros que falla es deixa per a la propera vegada.
     */
    private function baixaIgn(): void
    {
        $carpeta = storage_path('app/models/ign');
        File::ensureDirectoryExists($carpeta);

        $trossos = [];
        foreach (self::IGN_ZONES as [$lat0, $lat1, $lon0, $lon1]) {
            $trossos = array_merge($trossos, $this->trossosAmbTerra($lat0, $lat1, $lon0, $lon1));
        }
        $falten = array_values(array_filter($trossos, fn ($t) => ! is_file("{$carpeta}/{$this->nomTros($t)}.tif")));
        $this->info('IGN: ' . count($falten) . ' trossos per baixar.');

        $concurrencia = max(1, (int) $this->option('concurrencia'));
        $fets = 0;
        $errors = 0;
        foreach (array_chunk($falten, $concurrencia) as $grup) {
            $respostes = Http::pool(fn ($pool) => array_map(
                fn ($t) => $pool->timeout(300)->sink("{$carpeta}/{$this->nomTros($t)}.tif.part")->get($this->urlIgn($t, 'Elevacion4258_5', true)),
                $grup,
            ));
            foreach ($grup as $i => $t) {
                $desti = "{$carpeta}/{$this->nomTros($t)}.tif";
                $resposta = $respostes[$i];
                $bo = $resposta instanceof \Illuminate\Http\Client\Response && $resposta->successful()
                    && str_starts_with((string) @file_get_contents("{$desti}.part", false, null, 0, 2), 'II');
                if ($bo) {
                    rename("{$desti}.part", $desti);
                } else {
                    @unlink("{$desti}.part");
                    $errors++;
                }
            }
            $abans = intdiv($fets, 100);
            $fets += count($grup);
            if (intdiv($fets, 100) > $abans) {
                $this->line(sprintf('  %d/%d trossos (%d errors)', $fets, count($falten), $errors));
            }
        }

        $this->info("IGN: fet ({$errors} errors; es tornen a provar en tornar-la a executar).");
    }

    private function provaIgn(): array
    {
        $this->info('IGN: trossos amb terra, a partir del model d\'1 km…');
        $trossos = [];
        foreach (self::IGN_ZONES as $zona => [$lat0, $lat1, $lon0, $lon1]) {
            $trossos = array_merge($trossos, $this->trossosAmbTerra($lat0, $lat1, $lon0, $lon1));
        }

        $carpeta = storage_path('app/models/ign');
        $falten = array_values(array_filter($trossos, fn ($t) => ! is_file("{$carpeta}/{$this->nomTros($t)}.tif")));

        // Mostres repartides per tota la llista, a una carpeta temporal que s'esborra
        $temporal = storage_path('app/tmp/mostres-ign-' . uniqid());
        File::ensureDirectoryExists($temporal);
        $mides = [];
        $temps = [];
        try {
            $n = min((int) $this->option('mostres'), count($falten));
            for ($i = 0; $i < $n; $i++) {
                $t = $falten[(int) floor(($i + 0.5) * count($falten) / $n)];
                $inici = microtime(true);
                $resposta = Http::timeout(120)->sink("{$temporal}/mostra.tif")->get($this->urlIgn($t, 'Elevacion4258_5', true));
                $temps[] = microtime(true) - $inici;
                $mides[] = $resposta->successful() ? filesize("{$temporal}/mostra.tif") : 0;
            }
        } finally {
            File::deleteDirectory($temporal);
        }

        $mitjana = $mides ? array_sum($mides) / count($mides) : 0;
        $segons = $temps ? array_sum($temps) / count($temps) * count($falten) : 0;

        return ['IGN 5 m (Espanya)', number_format(count($falten), 0, ',', '.') . ' trossos de 0,1°', count($trossos) - count($falten),
            '~' . $this->mida($mitjana * count($falten)) . ' (estimat amb ' . count($mides) . ' mostres)',
            '~' . $this->durada($segons)];
    }

    /**
     * Els trossos de 0,1° d'un rectangle que tenen terra d'Espanya: el mar i els països veïns, al
     * model d'1 km, hi valen 0.
     *
     * @return array<int, array{float, float}> cantó sud-oest de cada tros
     */
    private function trossosAmbTerra(float $lat0, float $lat1, float $lon0, float $lon1): array
    {
        $resposta = Http::timeout(120)->get($this->urlIgn([$lat0, $lon0], 'Elevacion4258_1000', false, $lat1, $lon1))->throw();
        $mascara = TiffSimple::llegeix($resposta->body());
        $w = $mascara['amplada'];
        $h = $mascara['alcada'];
        $pasLat = ($lat1 - $lat0) / $h;
        $pasLon = ($lon1 - $lon0) / $w;

        $amb = [];
        foreach ($mascara['valors'] as $i => $v) {
            if ($v <= 0) {
                continue;
            }
            // Fila 0 és el nord
            $lat = $lat1 - (intdiv($i, $w) + 0.5) * $pasLat;
            $lon = $lon0 + ($i % $w + 0.5) * $pasLon;
            $clau = sprintf('%.1f,%.1f', floor($lat / self::IGN_TROS) * self::IGN_TROS, floor($lon / self::IGN_TROS) * self::IGN_TROS);
            $amb[$clau] = true;
        }

        return array_map(fn ($c) => array_map('floatval', explode(',', $c)), array_keys($amb));
    }

    /** L'adreça d'una petició al WCS: amb dos «subset», que un array de consulta no sap repetir. */
    private function urlIgn(array $sudoest, string $cobertura, bool $comprimit, ?float $lat1 = null, ?float $lon1 = null): string
    {
        [$lat0, $lon0] = $sudoest;
        $lat1 ??= $lat0 + self::IGN_TROS;
        $lon1 ??= $lon0 + self::IGN_TROS;

        return self::IGN_WCS . '?service=WCS&version=2.0.1&request=GetCoverage&CoverageId=' . $cobertura
            . sprintf('&subset=Lat(%.4F,%.4F)&subset=Long(%.4F,%.4F)', $lat0, $lat1, $lon0, $lon1)
            . '&format=image/tiff' . ($comprimit ? '&geotiff:compression=Deflate' : '');
    }

    private function nomTros(array $t): string
    {
        return sprintf('mdt05_%+05.1f_%+06.1f', $t[0], $t[1]);
    }

    private function mida(float $bytes): string
    {
        return $bytes >= 1e9 ? number_format($bytes / 1e9, 1, ',', '.') . ' GB' : number_format($bytes / 1e6, 0, ',', '.') . ' MB';
    }

    private function durada(float $segons): string
    {
        return $segons >= 3600 ? number_format($segons / 3600, 1, ',', '.') . ' h' : round($segons / 60) . ' min';
    }
}
