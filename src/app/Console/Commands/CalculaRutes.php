<?php

namespace App\Console\Commands;

use App\Models\SortidaBici;
use App\Services\RutesCanoniquesService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Calcula les rutes canòniques de totes les sortides amb recorregut: empremtes, semblances,
 * agrupament i representants a cada nivell. Ho substitueix tot: són dades derivades.
 */
class CalculaRutes extends Command
{
    protected $signature = 'rutes:calcula {--prova : Calcula i ho mostra, però no desa res}';

    protected $description = 'Agrupa les sortides en bici en rutes canòniques, a cada nivell de tolerància';

    public function handle(RutesCanoniquesService $servei): int
    {
        // Una sola alhora: la pantalla d'importació la llança en segon pla, i dues que desessin
        // a la vegada es trepitjarien
        $bloqueig = fopen(storage_path('app/rutes-calcula.lock'), 'c');
        if (! flock($bloqueig, LOCK_EX | LOCK_NB)) {
            $this->warn('Ja s\'estan calculant les rutes.');

            return self::SUCCESS;
        }

        $inici = microtime(true);
        $sortides = $servei->sortides()->get(['id']);
        $this->info($sortides->count() . ' sortides amb recorregut. Calculant empremtes…');

        $empremtes = [];
        $barra = $this->output->createProgressBar($sortides->count());
        foreach ($sortides as $s) {
            $punts = DB::connection('recorreguts')->table('punts')->where('sortida_id', $s->id)->orderBy('t')->get(['t', 'lat', 'lon']);
            if ($e = $servei->empremta($punts)) {
                $empremtes[$s->id] = $e;
            }
            $barra->advance();
        }
        $barra->finish();
        $this->newLine();
        $this->line(sprintf('  %d empremtes, %s cel·les de mitjana (%.0f s)', count($empremtes),
            number_format(array_sum(array_column($empremtes, 'n')) / max(1, count($empremtes)), 0, ',', '.'), microtime(true) - $inici));

        $this->info('Comparant parelles…');
        $t = microtime(true);
        $barra = $this->output->createProgressBar(count($empremtes));
        $parelles = $servei->semblances($empremtes, fn () => $barra->advance());
        $barra->finish();
        $this->newLine();
        $this->line(sprintf('  %s parelles semblants (%.0f s)', number_format(count($parelles), 0, ',', '.'), microtime(true) - $t));

        $ids = array_keys($empremtes);
        $fusions = $servei->fusions($ids, $parelles);
        $qualitat = array_map(fn ($e) => $e['qualitat'], $empremtes);

        $files = [];
        foreach (RutesCanoniquesService::NIVELLS as $nivell => $llindar) {
            $grups = $servei->grups($ids, $fusions, $llindar);
            $mides = array_map('count', $grups);
            rsort($mides);
            $files[] = [
                $nivell, '≥ ' . ($llindar * 100) . ' %', count($grups),
                count(array_filter($mides, fn ($m) => $m >= 2)),
                count(array_filter($mides, fn ($m) => $m >= 5)),
                implode(', ', array_slice($mides, 0, 6)),
            ];
        }
        $this->table(['Nivell', 'Semblança', 'Rutes', 'Amb ≥ 2 sortides', 'Amb ≥ 5', 'Els grups més grans'], $files);

        if ($this->option('prova')) {
            $this->info('Amb --prova no s\'ha desat res.');

            return self::SUCCESS;
        }

        $resultat = $servei->desa($ids, $parelles, $fusions, $qualitat);
        $this->mostraPrincipals($resultat['mateixa']);
        $this->info(sprintf('Desat en %.0f s.', microtime(true) - $inici));

        return self::SUCCESS;
    }

    /** Les rutes més repetides al nivell «mateixa ruta», per fer-se'n una idea. */
    private function mostraPrincipals(array $rutes): void
    {
        usort($rutes, fn ($a, $b) => count($b['membres']) <=> count($a['membres']));
        $principals = array_slice($rutes, 0, 12);
        $canoniques = SortidaBici::with('vehicle:id,nom')->whereIn('id', array_column($principals, 'canonica'))->get()->keyBy('id');

        $this->newLine();
        $this->line('Les rutes més repetides («mateixa ruta»):');
        $this->table(['Sortides', 'Canònica', 'Nom', 'Bici', 'Km', 'Desnivell'], array_map(function ($r) use ($canoniques) {
            $c = $canoniques[$r['canonica']];

            return [count($r['membres']), $c->data_local->format('Y-m-d'), mb_strimwidth((string) $c->nom, 0, 32, '…'), $c->vehicle->nom,
                number_format($c->distancia_m / 1000, 1, ',', '.'), (int) $c->desnivell_pos_m . ' m'];
        }, $principals));
    }
}
