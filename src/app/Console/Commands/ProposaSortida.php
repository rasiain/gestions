<?php

namespace App\Console\Commands;

use App\Models\SortidaBici;
use App\Services\PropostesService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/** Proposa voltes per la xarxa de camins de les sortides que s'acostin als km i al desnivell demanats. */
class ProposaSortida extends Command
{
    protected $signature = 'sortides:proposa
                            {--km= : Quilòmetres}
                            {--desnivell= : Desnivell positiu, en metres}
                            {--tipus=btt : btt (tots els trams) o carretera (només els aptes)}
                            {--quantes=5 : Quantes propostes (com a molt 10)}
                            {--lat= : Latitud de l\'inici (per defecte, casa)}
                            {--lon= : Longitud de l\'inici}
                            {--gpx= : Carpeta on desar el GPX de cada proposta}';

    protected $description = 'Proposa voltes amb trams de les sortides pròpies, per km i desnivell';

    public function handle(PropostesService $servei): int
    {
        $km = (float) $this->option('km');
        $desnivell = (float) $this->option('desnivell');
        $tipus = $this->option('tipus');
        $quantes = (int) $this->option('quantes');
        if ($km <= 0 || $this->option('desnivell') === null || ! in_array($tipus, ['btt', 'carretera'], true)
            || $quantes < 1 || $quantes > PropostesService::QUANTES_MAXIM) {
            $this->error('Cal --km, --desnivell, --tipus=btt|carretera i --quantes entre 1 i ' . PropostesService::QUANTES_MAXIM . '.');

            return self::FAILURE;
        }
        $lat = $this->option('lat') !== null ? (float) $this->option('lat') : PropostesService::CASA[0];
        $lon = $this->option('lon') !== null ? (float) $this->option('lon') : PropostesService::CASA[1];

        $inici = microtime(true);
        $resultat = $servei->proposa($lat, $lon, $km, $desnivell, $tipus, $quantes);
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
        if (count($files) < $quantes) {
            $this->warn('Només hi ha ' . count($files) . ' voltes prou diferents entre elles: la xarxa ' . ($tipus === 'carretera' ? 'de carretera ' : '') . 'no en dona més per aquí.');
        }

        return self::SUCCESS;
    }
}
