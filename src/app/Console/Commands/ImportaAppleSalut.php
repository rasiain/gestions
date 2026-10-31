<?php

namespace App\Console\Commands;

use App\Services\ImportacioAppleSalutService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * La càrrega inicial de les dades físiques des de l'exportació d'Apple Salut: un registre per mes, amb
 * la mediana del pes i de la pulsació en repòs. Es pot tornar a executar, i mai no toca un registre
 * apuntat a mà.
 */
class ImportaAppleSalut extends Command
{
    protected $signature = 'salut:importa-apple-salut
                            {fitxer : L\'exportació.xml (o export.xml) de l\'exportació d\'Apple Salut}
                            {--des-de=2024-01-01 : Des de quin dia}
                            {--prova : Només mostra què faria, no desa res}';

    protected $description = "Carrega les dades físiques (pes, pulsació en repòs) des d'una exportació d'Apple Salut";

    public function handle(ImportacioAppleSalutService $servei): int
    {
        try {
            $llegit = $servei->llegeix($this->argument('fitxer'), $this->option('des-de'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        ['mesos' => $mesos, 'descartades' => $descartades] = $servei->perMes($llegit);

        $this->table(
            ['Mes', 'Pes (kg)', 'Pesades', 'FC repòs'],
            collect($mesos)->map(fn ($m, $mes) => [$mes, $m['pes'] ?? '—', $m['pesades'], $m['fc_repos'] ?? '—'])->values()->all(),
        );
        foreach ($descartades as [$dia, $kg, $font]) {
            $this->line("Descartada: {$dia} {$kg} kg ({$font}), massa lluny de les del voltant");
        }
        if ($llegit['naixement']) {
            $this->line("Data de naixement: {$llegit['naixement']}");
        }

        if ($this->option('prova')) {
            $this->info('Prova: no s\'ha desat res.');

            return self::SUCCESS;
        }

        $compte = $servei->desa($mesos, $llegit['naixement']);
        $this->info("Creats: {$compte['creats']} · actualitzats: {$compte['actualitzats']} · saltats (n'hi havia un de manual): {$compte['saltats']}");

        return self::SUCCESS;
    }
}
