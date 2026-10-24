<?php

namespace App\Services;

use App\Models\SortidaBici;
use App\Support\LectorGpx;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Importa fitxers FIT baixats de Garmin Connect («Export Original»): el ZIP o el .fit de cada
 * activitat. També GPX (d'altres aplicacions: Runtastic, OruxMaps, Strava…), que `LectorGpx` posa en
 * la mateixa forma. Per a cada sessió de ciclisme:
 *
 * - si la sortida ja hi és i no té recorregut (l'exportació completa no en va portar el FIT),
 *   l'hi afegeix;
 * - si ja en té, no la toca: els punts originals no es reescriuen;
 * - si no hi és, la crea amb el resum del dispositiu, a la bici que s'hagi triat.
 *
 * La sortida es busca pel nom del fitxer, que Garmin Connect fa «<activityId>_ACTIVITY.fit»,
 * comprovant que l'hora i la distància quadrin —el resum de Garmin de vegades té l'hora arrodonida
 * al minut—; si el nom no porta l'identificador, per l'hora d'inici (±5 s, com a la importació).
 */
class ImportacioRecorregutsService
{
    private const MARGE_INICI_S = 120;

    private const MARGE_DISTANCIA = 0.02;

    public function __construct(private SortidesGarminService $garmin) {}

    /**
     * @param  array<int, string>  $fitxers  rutes de .fit, .gpx o .zip
     * @return array<int, array{fitxer: string, estat: string, missatge: string, sortida_id: ?int}>
     */
    public function importa(array $fitxers, ?int $biciPerNoves): array
    {
        $carpeta = storage_path('app/tmp/importacio-' . uniqid());
        File::ensureDirectoryExists($carpeta);

        try {
            foreach ($fitxers as $fitxer) {
                $this->desplega($fitxer, $carpeta);
            }

            $resultats = [];
            foreach ($this->llegeix($carpeta) as $fit) {
                $resultats[] = ['fitxer' => $fit['fitxer']] + $this->importaSessio($fit, "{$carpeta}/{$fit['fitxer']}", $biciPerNoves);
            }

            return $resultats;
        } finally {
            File::deleteDirectory($carpeta);
        }
    }

    /** Un .fit o un .gpx es copia tal qual; d'un ZIP, se'n treuen els que porti. */
    private function desplega(string $fitxer, string $carpeta): void
    {
        if (str_ends_with(strtolower($fitxer), '.zip')) {
            $zip = new \ZipArchive();
            if ($zip->open($fitxer) === true) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $nom = $zip->getNameIndex($i);
                    if (preg_match('/\.(fit|gpx)$/i', $nom)) {
                        copy("zip://{$fitxer}#{$nom}", $carpeta . '/' . basename($nom));
                    }
                }
                $zip->close();
            }

            return;
        }

        copy($fitxer, $carpeta . '/' . basename($fitxer));
    }

    /** @return array<int, array> les sessions d'activitat, en la forma de scripts/llegeix-fit.mjs */
    private function llegeix(string $carpeta): array
    {
        $proces = new Process(['node', base_path('scripts/llegeix-fit.mjs'), $carpeta], base_path());
        $proces->setTimeout(300)->mustRun();

        $sessions = array_values(array_filter(array_map(
            fn ($l) => json_decode($l, true),
            explode("\n", trim($proces->getOutput())),
        )));

        foreach (glob("{$carpeta}/*.[gG][pP][xX]") as $gpx) {
            $sessions[] = LectorGpx::llegeix($gpx) ?? ['fitxer' => basename($gpx), 'error' => 'no porta cap punt amb hora'];
        }

        return $sessions;
    }

    /** @return array{estat: string, missatge: string, sortida_id: ?int} */
    public function importaSessio(array $fit, string $ruta, ?int $biciPerNoves): array
    {
        $resultat = fn (string $estat, string $missatge, ?SortidaBici $s = null) =>
            ['estat' => $estat, 'missatge' => $missatge, 'sortida_id' => $s?->id];

        if (isset($fit['error'])) {
            return $resultat('error', 'No s\'ha pogut llegir: ' . $fit['error']);
        }
        if ($this->garmin->tipusActivitat($fit['sessio']) === null) {
            return $resultat('descartat', 'No és una sortida en bici (' . ($fit['sessio']['sport'] ?? '?') . ').');
        }

        $idActivitat = preg_match('/^(\d+)_ACTIVITY\.fit$/i', $fit['fitxer'], $m) ? $m[1] : null;
        [$sortida, $error] = $this->troba($fit, $idActivitat);

        if ($error !== null) {
            return $resultat('error', $error);
        }

        if ($sortida !== null) {
            if ($sortida->punts > 0) {
                return $resultat('ja_hi_era', 'Ja tenia el recorregut: no s\'ha tocat.', $sortida);
            }
            $this->garmin->afegeixRecorregut($sortida, $fit, $ruta);

            return $resultat('afegit', sprintf('Recorregut afegit (%s punts).', number_format($sortida->punts, 0, ',', '.')), $sortida);
        }

        $esGpx = ($fit['sessio']['format'] ?? null) === 'gpx';

        if ($biciPerNoves === null) {
            return $resultat('cal_bici', $esGpx
                ? 'No hi ha cap sortida a aquesta hora. Un GPX no diu de quin esport és: si és en bici, tria-la per importar-lo.'
                : 'És una sortida nova: cal triar-ne la bici per importar-la.');
        }

        $nova = $this->garmin->creaDesDeFit($fit, $ruta, $biciPerNoves, $idActivitat ?? 'fit-' . $fit['inici']);

        return $resultat('creada', sprintf('Sortida nova (%s km).', number_format($nova->distancia_m / 1000, 1, ',', '.')), $nova);
    }

    /**
     * La sortida del fitxer: per l'identificador del nom, comprovant hora i distància, o per l'hora.
     *
     * @return array{0: ?SortidaBici, 1: ?string} [sortida, error]
     */
    public function troba(array $fit, ?string $idActivitat): array
    {
        if ($idActivitat !== null) {
            $sortida = SortidaBici::where('origen', SortidesGarminService::ORIGEN)->where('id_extern', $idActivitat)->first();
            if ($sortida === null) {
                // Una activitat que l'exportació no portava; si tampoc no hi és per l'hora, és nova
                return [$this->perHora($fit), null];
            }

            $dInici = abs($sortida->inici->getTimestamp() - $fit['inici']);
            $km = (float) $sortida->distancia_m;
            $kmFit = (float) ($fit['sessio']['totalDistance'] ?? 0);
            if ($dInici > self::MARGE_INICI_S || ($km > 0 && abs($kmFit - $km) / $km > self::MARGE_DISTANCIA)) {
                return [null, sprintf('Diu que és l\'activitat %s, però no hi quadra: comença %d s abans o després i fa %.1f km (la sortida, %.1f).',
                    $idActivitat, $dInici, $kmFit / 1000, $km / 1000)];
            }

            return [$sortida, null];
        }

        return [$this->perHora($fit), null];
    }

    private function perHora(array $fit): ?SortidaBici
    {
        return SortidaBici::whereBetween('inici', [
            gmdate('Y-m-d H:i:s', $fit['inici'] - SortidesGarminService::MARGE_S),
            gmdate('Y-m-d H:i:s', $fit['inici'] + SortidesGarminService::MARGE_S),
        ])->first();
    }

    /**
     * Torna a calcular en segon pla el que es deriva dels punts, que la pantalla no ha d'esperar:
     * l'altitud del terreny de les sortides noves (i els seus totals de desnivell), les rutes, que
     * triguen més d'un minut, i la xarxa de camins de les propostes. `rutes:calcula` té un bloqueig: si ja n'hi ha una en marxa, no se'n
     * posa cap altra.
     */
    public function recalculaDerivats(): void
    {
        Process::fromShellCommandline(
            "nohup sh -c 'php -d memory_limit=1G artisan sortides:corregeix-altitud > storage/logs/corregeix-altitud.log 2>&1;"
            . " php -d memory_limit=2G artisan rutes:calcula > storage/logs/rutes-calcula.log 2>&1;"
            . " php -d memory_limit=3G artisan xarxa:calcula > storage/logs/xarxa-calcula.log 2>&1' > /dev/null 2>&1 &",
            base_path(),
        )->run();
    }
}
