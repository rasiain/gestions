<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * RefreshDatabase només conserva la connexió principal: la BD de recorreguts (també en memòria)
 * arriba buida. Aquí se'n creen les taules que fan servir les sortides, amb les seves migracions.
 */
trait BdRecorreguts
{
    protected function preparaBdRecorreguts(): void
    {
        $recorreguts = DB::connection('recorreguts');
        $migracions = [
            'punts'            => '2026_10_20_000004_create_punts_recorreguts',
            'altituds_terreny' => '2026_10_24_000001_create_altituds_terreny',
        ];
        foreach ($migracions as $taula => $migracio) {
            if (! $recorreguts->getSchemaBuilder()->hasTable($taula)) {
                (require database_path("migrations/{$migracio}.php"))->up();
            }
        }
        foreach (['punts', 'altituds_terreny', 'altituds_terreny_sortides'] as $taula) {
            $recorreguts->table($taula)->delete();
        }
    }
}
