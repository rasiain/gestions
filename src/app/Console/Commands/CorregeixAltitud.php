<?php

namespace App\Console\Commands;

use App\Services\AltitudTerrenyService;
use Illuminate\Console\Command;

/**
 * Llegeix l'altitud del terreny als punts de les sortides, de cada model que les cobreixi senceres,
 * i la desa a la BD de recorreguts. Per defecte, només les sortides que encara no s'han llegit.
 */
class CorregeixAltitud extends Command
{
    protected $signature = 'sortides:corregeix-altitud
                            {--sortida=* : Només aquestes sortides (es tornen a llegir encara que ja ho estiguin)}
                            {--totes : Torna a llegir totes les sortides, no només les pendents}
                            {--prova : Llegeix i diu què cobreix cada font, però no desa res}
                            {--desnivells : No llegeix els models: només recalcula els totals de desnivell amb el que ja hi ha desat}';

    protected $description = "Llegeix l'altitud del terreny (ICGC, IGN) als punts de les sortides en bici";

    public function handle(AltitudTerrenyService $servei): int
    {
        if ($this->option('desnivells')) {
            // Sense --sortida, «pendents» no vol dir res aquí: es recalculen totes
            $ids = $this->option('sortida') !== [] ? array_map('intval', $this->option('sortida')) : $servei->sortides()->pluck('id')->all();
            $barra = $this->output->createProgressBar(count($ids));
            foreach ($ids as $id) {
                $servei->desaDesnivells($id);
                $barra->advance();
            }
            $barra->finish();
            $this->newLine();
            $this->info(count($ids) . ' sortides amb els totals de desnivell recalculats.');

            return self::SUCCESS;
        }

        $ids = match (true) {
            $this->option('sortida') !== [] => array_map('intval', $this->option('sortida')),
            $this->option('totes')          => $servei->sortides()->pluck('id')->all(),
            default                         => $servei->pendents(),
        };
        if ($ids === []) {
            $this->info('No hi ha cap sortida per llegir.');

            return self::SUCCESS;
        }

        $this->info(count($ids) . ' sortides per llegir.');
        $inici = microtime(true);
        $barra = $this->output->createProgressBar(count($ids));
        $resultat = $servei->calcula($ids, ! $this->option('prova'), fn () => $barra->advance());
        $barra->finish();
        $this->newLine();

        $files = [];
        foreach (AltitudTerrenyService::FONTS as $font) {
            $cobertes = array_filter($resultat['sortides'], fn ($r) => ! isset($r['sense_dades'][$font]));
            $files[] = [strtoupper($font), count($cobertes), count($resultat['sortides']) - count($cobertes),
                number_format(array_sum(array_column($cobertes, 'punts')), 0, ',', '.'), $resultat['fulls'][$font] ?? '—'];
        }
        $this->table(['Font', 'Sortides cobertes', 'No cobertes', 'Punts', 'Fulls llegits'], $files);
        $this->line(sprintf('Temps: %.0f s.', microtime(true) - $inici));

        if (count($ids) <= 10) {
            foreach ($resultat['sortides'] as $id => $r) {
                $falta = $r['sense_dades'] === [] ? 'totes dues fonts' : implode(', ', array_map(fn ($f, $n) => "{$f}: {$n} punts sense dades", array_keys($r['sense_dades']), $r['sense_dades']));
                $this->line("  {$id}: {$r['punts']} punts — {$falta}");
            }
        }
        if ($this->option('prova')) {
            $this->info('Amb --prova no s\'ha desat res.');
        }

        return self::SUCCESS;
    }
}
