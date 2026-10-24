<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'recorreguts';

    /**
     * L'altitud del terreny als punts de les sortides, llegida dels models (ICGC, IGN): és el que és
     * car de calcular, i es desa una vegada per sortida i per font. La combinació amb l'altímetre i
     * tot el que se'n deriva es calcula a la pantalla. La columna `alt` dels punts no es toca mai.
     *
     * `altituds_terreny_sortides` diu, per sortida i font, si ja s'ha llegit i quants punts han quedat
     * sense dades: una font només es desa si cobreix tots els punts amb posició.
     */
    public function up(): void
    {
        DB::connection($this->connection)->statement('
            CREATE TABLE altituds_terreny (
                sortida_id INTEGER NOT NULL,
                font       TEXT    NOT NULL,
                t          INTEGER NOT NULL,
                alt        INTEGER NOT NULL,
                PRIMARY KEY (sortida_id, font, t)
            ) WITHOUT ROWID
        ');
        DB::connection($this->connection)->statement('
            CREATE TABLE altituds_terreny_sortides (
                sortida_id  INTEGER NOT NULL,
                font        TEXT    NOT NULL,
                punts       INTEGER NOT NULL,
                sense_dades INTEGER NOT NULL,
                calculat_at TEXT    NOT NULL,
                PRIMARY KEY (sortida_id, font)
            ) WITHOUT ROWID
        ');
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS altituds_terreny');
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS altituds_terreny_sortides');
    }
};
