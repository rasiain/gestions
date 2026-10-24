<?php

namespace App\Console\Commands;

use App\Models\SortidaBici;
use App\Models\Vehicle;
use App\Services\ImportacioRecorregutsService;
use App\Services\SortidesGarminService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Importa les sortides en bici d'una exportació completa de Garmin: el resum de cada una,
 * amb quin material es va fer, i els punts del FIT original.
 *
 * Es pot tornar a executar: les sortides es reconeixen per l'origen i el seu identificador,
 * i els punts d'una sortida que ja en té no es tornen a escriure. Abans cal haver importat
 * el material (`material:importa`), que és el que diu de quina bici és cada sortida.
 */
class ImportaSortides extends Command
{
    protected $signature = 'sortides:importa
                            {fitxer : ZIP de l\'exportació de Garmin}
                            {--assigna=* : Activitats sense bici a Garmin i la bici del catàleg amb què es van fer: «<id vehicle>:<activityId>,<activityId>…»}
                            {--nomes-assignades : Només importa les activitats de --assigna}
                            {--prova : Només mostra què faria, no desa res}';

    protected $description = "Importa les sortides en bici (resum, material i recorregut) d'una exportació de Garmin";

    /** Diferència entre el resum de Garmin i el del dispositiu que val la pena ensenyar. */
    private const DIFERENCIA_NOTABLE = 0.10;

    public function handle(SortidesGarminService $servei): int
    {
        $zip = $this->argument('fitxer');

        if (! is_readable($zip)) {
            $this->error("No es pot llegir {$zip}.");

            return self::FAILURE;
        }

        $temporal = storage_path('app/tmp/sortides-' . uniqid());

        try {
            return $this->importa($servei, $zip, $temporal);
        } finally {
            File::deleteDirectory($temporal);
        }
    }

    private function importa(SortidesGarminService $servei, string $zip, string $temporal): int
    {
        $this->info('Llegint l\'exportació…');
        $exportacio = $servei->llegeixExportacio($zip, "{$temporal}/fit");
        $assignacions = $this->assignacions();
        if ($assignacions === null) {
            return self::FAILURE;
        }

        $sortides = $servei->sortidesDeBici($exportacio['activitats'], $exportacio['gear'], $assignacions);
        if ($this->option('nomes-assignades')) {
            $sortides = array_intersect_key($sortides, $assignacions);
        }
        $noTrobades = array_diff(array_keys($assignacions), array_keys($sortides));
        if ($noTrobades !== []) {
            $this->warn('Activitats de --assigna que no són a l\'exportació o ja tenen bici a Garmin: ' . implode(', ', $noTrobades));
        }

        if ($sortides === []) {
            $this->error('Cap activitat no és d\'una bici del catàleg. S\'ha importat el material (material:importa)?');

            return self::FAILURE;
        }

        // Hora d'inici (s) → activityId, per aparellar els FIT
        $perInici = [];
        foreach ($sortides as $id => $s) {
            $perInici[intdiv((int) $s['activitat']['startTimeGmt'], 1000)] = $id;
        }
        file_put_contents("{$temporal}/inicis.json", json_encode(array_keys($perInici)));

        $this->info(count($sortides) . ' sortides en bici. Llegint els FIT…');

        $ambFit = [];
        $diferencies = [];
        $errors = [];
        $punts = 0;
        $prova = $this->option('prova');
        $barra = $this->output->createProgressBar(count($sortides));

        $this->llegeixFits("{$temporal}/fit", "{$temporal}/inicis.json", function (array $fit) use (
            $servei, $sortides, $perInici, $temporal, $prova, &$ambFit, &$diferencies, &$errors, &$punts, $barra
        ) {
            if (isset($fit['error'])) {
                $errors[] = "{$fit['fitxer']}: {$fit['error']}";

                return;
            }

            $id = $this->aparella($fit['inici'], $perInici);
            if ($id === null || isset($ambFit[$id])) {
                return;
            }

            $ambFit[$id] = true;
            $punts += count($fit['punts']);
            $this->comparaResums($sortides[$id]['activitat'], $fit['sessio'], $diferencies);

            if (! $prova) {
                $servei->desa($sortides[$id], $fit, "{$temporal}/fit/{$fit['fitxer']}");
            }
            $barra->advance();
        });

        // Les que no tenen FIT també hi són, amb el resum
        foreach ($sortides as $id => $s) {
            if (! isset($ambFit[$id])) {
                if (! $prova) {
                    $servei->desa($s, null);
                }
                $barra->advance();
            }
        }
        $barra->finish();
        $this->newLine(2);

        $this->resumFinal($sortides, $ambFit, $punts, $diferencies, $errors);

        if ($prova) {
            $this->info('Amb --prova no s\'ha desat res.');
        } else {
            // Com la pantalla d'importació: altitud, rutes i xarxa, en segon pla
            app(ImportacioRecorregutsService::class)->recalculaDerivats();
            $this->line('L\'altitud, les rutes i la xarxa es recalculen en segon pla (storage/logs/).');
        }

        return self::SUCCESS;
    }

    /**
     * Les activitats de --assigna, com a activityId → id de vehicle. Null si n'hi ha cap de mal escrita
     * o la bici no existeix.
     *
     * @return array<int, int>|null
     */
    private function assignacions(): ?array
    {
        $resultat = [];
        foreach ($this->option('assigna') as $text) {
            if (! preg_match('/^(\d+):([\d,]+)$/', $text, $m)) {
                $this->error("--assigna «{$text}»: ha de ser «<id vehicle>:<activityId>,<activityId>…».");

                return null;
            }
            if (! Vehicle::where('id', $m[1])->where('tipus', 'bici')->exists()) {
                $this->error("--assigna «{$text}»: no hi ha cap bici amb id {$m[1]}.");

                return null;
            }
            foreach (explode(',', $m[2]) as $id) {
                $resultat[(int) $id] = (int) $m[1];
            }
        }

        return $resultat;
    }

    /** Executa scripts/llegeix-fit.mjs i passa cada línia JSON a $perLinia a mesura que arriba. */
    private function llegeixFits(string $carpeta, string $inicis, callable $perLinia): void
    {
        $proces = new Process(['node', base_path('scripts/llegeix-fit.mjs'), $carpeta, $inicis], base_path());
        $proces->setTimeout(null);

        $pendent = '';
        $proces->run(function (string $tipus, string $tros) use (&$pendent, $perLinia) {
            if ($tipus === Process::ERR) {
                $this->getOutput()->getErrorStyle()->write($tros);

                return;
            }

            $pendent .= $tros;
            while (($salt = strpos($pendent, "\n")) !== false) {
                $linia = substr($pendent, 0, $salt);
                $pendent = substr($pendent, $salt + 1);
                if ($linia !== '') {
                    $perLinia(json_decode($linia, true));
                }
            }
        });

        if (! $proces->isSuccessful()) {
            throw new \RuntimeException('El lector de FIT ha fallat: ' . $proces->getErrorOutput());
        }
    }

    private function aparella(int $inici, array $perInici): ?int
    {
        for ($dt = -SortidesGarminService::MARGE_S; $dt <= SortidesGarminService::MARGE_S; $dt++) {
            if (isset($perInici[$inici + $dt])) {
                return $perInici[$inici + $dt];
            }
        }

        return null;
    }

    /** Apunta les sortides on el resum de Garmin i el del dispositiu no diuen el mateix. */
    private function comparaResums(array $a, array $sessio, array &$diferencies): void
    {
        // Als rodets el dispositiu no mesura distància: la de Garmin és virtual, i sempre difereix
        if (($a['activityType'] ?? '') === 'indoor_cycling') {
            return;
        }

        $parells = [
            'distància (km)' => [($a['distance'] ?? 0) / 100000, ($sessio['totalDistance'] ?? 0) / 1000],
            'desnivell (m)'  => [($a['elevationGain'] ?? 0) / 100, $sessio['totalAscent'] ?? 0],
        ];

        foreach ($parells as $camp => [$garmin, $dispositiu]) {
            if ($garmin > 0 && abs($dispositiu - $garmin) / $garmin > self::DIFERENCIA_NOTABLE) {
                $diferencies[] = [
                    gmdate('Y-m-d', (int) ($a['startTimeLocal'] / 1000)),
                    mb_strimwidth($a['name'] ?? '', 0, 30, '…'),
                    $a['activityType'] ?? '',
                    $camp,
                    round($garmin, 1),
                    round($dispositiu, 1),
                ];
            }
        }
    }

    private function resumFinal(array $sortides, array $ambFit, int $punts, array $diferencies, array $errors): void
    {
        $noms = Vehicle::whereIn('id', array_column($sortides, 'vehicle_id'))->pluck('nom', 'id');

        $files = collect($sortides)
            ->groupBy(fn ($s) => $s['vehicle_id'])
            ->map(fn ($grup, $vehicleId) => [
                $noms[$vehicleId] ?? $vehicleId,
                $grup->count(),
                $grup->filter(fn ($s) => isset($ambFit[$s['activitat']['activityId']]))->count(),
                number_format($grup->sum(fn ($s) => $s['activitat']['distance'] ?? 0) / 100000, 0, ',', '.'),
                $grup->map(fn ($s) => gmdate('Y', (int) ($s['activitat']['startTimeLocal'] / 1000)))->min()
                    . '–' . $grup->map(fn ($s) => gmdate('Y', (int) ($s['activitat']['startTimeLocal'] / 1000)))->max(),
            ])->values();

        $this->table(['Bici', 'Sortides', 'Amb FIT', 'km', 'Anys'], $files);
        $this->line(count($ambFit) . ' sortides amb recorregut, ' . number_format($punts, 0, ',', '.') . ' punts.');

        if ($diferencies !== []) {
            $this->newLine();
            $this->warn(count($diferencies) . ' diferències de més d\'un ' . (self::DIFERENCIA_NOTABLE * 100) . ' % entre el resum de Garmin i el del dispositiu:');
            $this->table(['Data', 'Nom', 'Tipus', 'Camp', 'Garmin', 'Dispositiu'], $diferencies);
        }

        foreach ($errors as $error) {
            $this->error($error);
        }

        if (! $this->option('prova')) {
            $this->line('A la BD: ' . SortidaBici::count() . ' sortides.');
        }
    }
}
