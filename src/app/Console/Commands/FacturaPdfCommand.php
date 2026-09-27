<?php

namespace App\Console\Commands;

use App\Models\Factura;
use App\Services\FacturaPdfService;
use Illuminate\Console\Command;

/** Genera el PDF d'una factura per poder-lo comparar amb els que s'han emès a mà. */
class FacturaPdfCommand extends Command
{
    protected $signature = 'factures:pdf {factura : id de la factura} {--desa= : carpeta on escriure el PDF}';

    protected $description = 'Genera el PDF d\'una factura';

    public function handle(FacturaPdfService $servei): int
    {
        $factura = Factura::with('lloguer')->find($this->argument('factura'));

        if (!$factura) {
            $this->error('No hi ha cap factura amb aquest id.');

            return self::FAILURE;
        }

        $dir = $this->option('desa') ?: sys_get_temp_dir();
        $cami = rtrim($dir, '/') . '/' . $servei->nomFitxer($factura);

        file_put_contents($cami, $servei->pdf($factura)->output());

        $this->info($cami);

        return self::SUCCESS;
    }
}
