<?php

namespace App\Console\Commands;

use App\Models\SortidaBici;
use App\Services\PropostesService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/** Proposa voltes per la xarxa de camins de les sortides que s'acostin als km i al desnivell demanats. */
class ProposaSortida extends Command
{
    /** Casa: el punt d'inici més freqüent de les sortides. */
    public const CASA = [41.978, 2.820];

    protected $signature = 'sortides:proposa
                            {--km= : Quilòmetres}
                            {--desnivell= : Desnivell positiu, en metres}
                            {--tipus=btt : btt (tots els trams) o carretera (només els aptes)}
                            {--lat= : Latitud de l\'inici (per defecte, casa)}
                            {--lon= : Longitud de l\'inici}
                            {--gpx= : Carpeta on desar el GPX de cada proposta}';

    protected $description = 'Proposa voltes amb trams de les sortides pròpies, per km i desnivell';

    public function handle(PropostesService $servei): int
    {
        $km = (float) $this->option('km');
        $desnivell = (float) $this->option('desnivell');
        $tipus = $this->option('tipus');
        if ($km <= 0 || $this->option('desnivell') === null || ! in_array($tipus, ['btt', 'carretera'], true)) {
            $this->error('Cal --km, --desnivell i --tipus=btt|carretera.');

            return self::FAILURE;
        }
        $lat = $this->option('lat') !== null ? (float) $this->option('lat') : self::CASA[0];
        $lon = $this->option('lon') !== null ? (float) $this->option('lon') : self::CASA[1];

        $inici = microtime(true);
        $resultat = $servei->proposa($lat, $lon, $km, $desnivell, $tipus);
        $this->line(sprintf('Inici a la cruïlla %d, a %d m del punt demanat. %.1f s.', $resultat['inici']['node'], $resultat['inici']['distancia_m'], microtime(true) - $inici));

        $noms = SortidaBici::whereIn('id', collect($resultat['propostes'])->pluck('sortides')->flatten(1)->pluck('id'))->pluck('nom', 'id');
        $files = [];
        foreach ($resultat['propostes'] as $n => $p) {
            $principals = array_map(fn ($s) => ($noms[$s['id']] ?? $s['id']) . " ({$s['km']} km)", array_slice($p['sortides'], 0, 3));
            $files[] = [$n + 1, $p['km'], $p['desnivell'], $p['km_repetits'] ?: '', $p['km_antics'] ?: '', implode('; ', $principals)];
            if ($this->option('gpx')) {
                File::ensureDirectoryExists($this->option('gpx'));
                File::put($this->option('gpx') . '/proposta-' . ($n + 1) . '.gpx', PropostesService::gpx($p, "Proposta {$km} km {$desnivell} m · " . ($n + 1)));
            }
        }
        $this->table(['#', 'Km', 'Desnivell', 'Km repetits', 'Km antics', 'Surt sobretot de'], $files);
        if (count($files) < 5) {
            $this->warn('Només hi ha ' . count($files) . ' voltes prou diferents entre elles: la xarxa ' . ($tipus === 'carretera' ? 'de carretera ' : '') . 'no en dona més per aquí.');
        }

        return self::SUCCESS;
    }
}
