<?php

namespace App\Console\Commands;

use App\Models\MovimentCompteCorrent;
use Illuminate\Console\Command;

class DiagnoseMovementHash extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'movements:diagnose-hash
                            {compte_corrent_id : ID del compte corrent}
                            {data : Data del moviment (Y-m-d)}
                            {import : Import del moviment}
                            {concepte : Concepte del moviment}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Diagnostica per què un hash de moviment no coincideix amb la BD';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $compteCorrentId = (int) $this->argument('compte_corrent_id');
        $data = $this->argument('data');
        $import = (float) $this->argument('import');
        $concepte = $this->argument('concepte');

        $this->info("=== DIAGNÒSTIC DE HASH ===\n");

        // Generar hash amb les dades proporcionades
        $hashCalculat = MovimentCompteCorrent::generateHash($data, $concepte, $import, $compteCorrentId);

        $this->info("Dades proporcionades:");
        $this->line("  Data: {$data}");
        $this->line("  Concepte: [{$concepte}]");
        $this->line("  Import: " . number_format($import, 2, '.', ''));
        $this->line("  Compte: {$compteCorrentId}");
        $this->line("  Hash calculat: {$hashCalculat}\n");

        // Buscar moviment amb aquest hash
        $moviment = MovimentCompteCorrent::where('compte_corrent_id', $compteCorrentId)
            ->where('hash', $hashCalculat)
            ->first();

        if ($moviment) {
            $this->info("✅ TROBAT! El hash coincideix amb un moviment existent:");
            $this->line("  ID: {$moviment->id}");
            $this->line("  Data BD: " . $moviment->data_moviment->format('Y-m-d'));
            $this->line("  Concepte BD: [{$moviment->concepte}]");
            $this->line("  Import BD: " . number_format($moviment->import, 2, '.', ''));
            return 0;
        }

        $this->error("❌ NO TROBAT. El hash no coincideix amb cap moviment.\n");

        // Buscar moviments similars (mateixa data i import aproximat)
        $similar = MovimentCompteCorrent::where('compte_corrent_id', $compteCorrentId)
            ->where('data_moviment', $data)
            ->whereBetween('import', [$import - 0.01, $import + 0.01])
            ->get();

        if ($similar->count() > 0) {
            $this->warn("Moviments similars trobats (mateixa data i import similar):\n");

            foreach ($similar as $m) {
                $this->line("  ID: {$m->id}");
                $this->line("  Concepte BD: [{$m->concepte}]");
                $this->line("  Concepte fitxer: [{$concepte}]");
                $this->line("  Import BD: " . number_format($m->import, 2, '.', ''));
                $this->line("  Import fitxer: " . number_format($import, 2, '.', ''));

                // Comparar byte per byte el concepte
                if ($m->concepte !== $concepte) {
                    $this->line("  DIFERÈNCIA EN CONCEPTE:");
                    $this->line("    Longitud BD: " . mb_strlen($m->concepte));
                    $this->line("    Longitud fitxer: " . mb_strlen($concepte));

                    // Mostrar caràcters diferents
                    $maxLen = max(mb_strlen($m->concepte), mb_strlen($concepte));
                    for ($i = 0; $i < $maxLen; $i++) {
                        $charBd = mb_substr($m->concepte, $i, 1);
                        $charFile = mb_substr($concepte, $i, 1);
                        if ($charBd !== $charFile) {
                            $this->line("    Posició {$i}: BD='" . ($charBd ?: 'FI') . "' vs Fitxer='" . ($charFile ?: 'FI') . "'");
                        }
                    }
                }

                $this->line("  Hash BD: {$m->hash}");
                $this->line("  Hash calculat: {$hashCalculat}");
                $this->line("");
            }
        } else {
            $this->warn("No s'han trobat moviments similars amb la mateixa data i import.");
        }

        return 1;
    }
}
