<?php

namespace App\Console\Commands;

use App\Services\XarxaService;
use Illuminate\Console\Command;

/**
 * Construeix la xarxa de camins de les sortides, on es busquen les propostes de sortida, i la
 * substitueix sencera: és dada derivada dels punts.
 */
class CalculaXarxa extends Command
{
    protected $signature = 'xarxa:calcula {--prova : Calcula i ho mostra, però no desa res}';

    protected $description = 'Construeix la xarxa de camins de les sortides en bici, per proposar-ne de noves';

    public function handle(XarxaService $servei): int
    {
        $inici = microtime(true);
        $barra = $this->output->createProgressBar();
        $xarxa = $servei->construeix(fn () => $barra->advance());
        $barra->finish();
        $this->newLine();
        $trams = $xarxa['trams'];

        // Peces connectades, per trams: si la xarxa queda partida, una volta no hi pot passar d'una a l'altra
        $pare = [];
        $arrel = function (int $n) use (&$pare, &$arrel): int {
            $pare[$n] ??= $n;

            return $pare[$n] === $n ? $n : ($pare[$n] = $arrel($pare[$n]));
        };
        foreach ($trams as $t) {
            $pare[$arrel($t['nodes'][0])] = $arrel(end($t['nodes']));
        }
        $km = [];
        foreach ($trams as $t) {
            $r = $arrel($t['nodes'][0]);
            $km[$r] = ($km[$r] ?? 0) + $t['longitud_m'] / 1000;
        }
        arsort($km);
        $total = array_sum(array_column($trams, 'longitud_m')) / 1000;
        $carretera = array_sum(array_map(fn ($t) => $t['carretera'] ? $t['longitud_m'] : 0, $trams)) / 1000;
        $llargades = array_column($trams, 'longitud_m');
        sort($llargades);

        $this->table(['', ''], [
            ['Nodes', number_format($xarxa['nodes'], 0, ',', '.')],
            ['Arestes', number_format($xarxa['arestes'], 0, ',', '.')],
            ['Trams', number_format(count($trams), 0, ',', '.')],
            ['Km de xarxa', number_format($total, 0, ',', '.')],
            ['Km aptes per a carretera', number_format($carretera, 0, ',', '.')],
            ['Llargada dels trams (mediana)', number_format($llargades[intdiv(count($llargades), 2)] ?? 0, 0, ',', '.') . ' m'],
            ['Peces connectades', number_format(count($km), 0, ',', '.')],
            ['La més gran', number_format(reset($km) ?: 0, 0, ',', '.') . ' km (' . round(100 * (reset($km) ?: 0) / max($total, 1)) . ' %)'],
        ]);
        $this->line(sprintf('Temps: %.0f s.', microtime(true) - $inici));

        if ($this->option('prova')) {
            $this->info('Amb --prova no s\'ha desat res.');

            return self::SUCCESS;
        }
        $servei->desa($trams);
        $this->info('Xarxa desada.');

        return self::SUCCESS;
    }
}
