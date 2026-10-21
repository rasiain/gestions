<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Services\ImportacioRecorregutsService;
use Illuminate\Console\Command;

/**
 * Importa FIT baixats de Garmin Connect («Export Original»), com la pantalla d'importació: afegeix
 * el recorregut a les sortides que no en tenen i, amb --bici, crea les que no hi són.
 */
class AfegeixRecorregut extends Command
{
    protected $signature = 'sortides:afegeix-recorregut
                            {fitxers* : FIT originals o els ZIP que en baixa Garmin Connect}
                            {--bici= : Id de la bici per a les sortides que no hi són}';

    protected $description = 'Afegeix recorreguts de FIT a les sortides que no en tenen, i crea les que falten';

    public function handle(ImportacioRecorregutsService $servei): int
    {
        $fitxers = $this->argument('fitxers');
        foreach ($fitxers as $f) {
            if (! is_readable($f)) {
                $this->error("No es pot llegir {$f}.");

                return self::FAILURE;
            }
        }

        $bici = $this->option('bici') !== null ? (int) $this->option('bici') : null;
        if ($bici !== null && ! Vehicle::where('id', $bici)->where('tipus', 'bici')->exists()) {
            $this->error("No hi ha cap bici amb id {$bici}.");

            return self::FAILURE;
        }

        $resultats = $servei->importa($fitxers, $bici);
        $this->table(['Fitxer', 'Estat', ''], array_map(fn ($r) => [$r['fitxer'], $r['estat'], $r['missatge']], $resultats));

        if (array_intersect(['afegit', 'creada'], array_column($resultats, 'estat'))) {
            $this->line('Per tenir-les a les rutes: php artisan rutes:calcula');
        }

        return self::SUCCESS;
    }
}
