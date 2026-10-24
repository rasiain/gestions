<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'recorreguts';

    /**
     * La xarxa de camins que surt de les sortides, per proposar-ne de noves combinant trams
     * (especificació a .claude/specs/bicis-propostes.md). Dada derivada: `xarxa:calcula` la substitueix
     * sencera.
     *
     * - `xarxa_nodes`: punts del camí, cada ~10 m, on s'enganxen totes les passades. Coordenades en
     *   graus × 10⁶ i altitud en cm (la mitjana de l'altitud combinada de les passades).
     * - `xarxa_trams`: trossos de camí entre dues cruïlles. `nodes` és la llista dels nodes, de `a` a
     *   `b`, en JSON. Pujada en cada sentit, si hi ha passat alguna bici de carretera o urbana, i la data
     *   de l'última passada del tros menys recorregut.
     * - `xarxa_tram_sortida`: per quines sortides hi ha passat cada tram.
     */
    public function up(): void
    {
        $bd = DB::connection($this->connection);
        $bd->statement('
            CREATE TABLE xarxa_nodes (
                id       INTEGER PRIMARY KEY,
                lat      INTEGER NOT NULL,
                lon      INTEGER NOT NULL,
                alt      INTEGER,
                passades INTEGER NOT NULL
            )
        ');
        $bd->statement('
            CREATE TABLE xarxa_trams (
                id         INTEGER PRIMARY KEY,
                a          INTEGER NOT NULL,
                b          INTEGER NOT NULL,
                longitud_m REAL    NOT NULL,
                pujada_ab  REAL    NOT NULL,
                pujada_ba  REAL    NOT NULL,
                carretera  INTEGER NOT NULL,
                passades   INTEGER NOT NULL,
                darrera    TEXT    NOT NULL,
                nodes      TEXT    NOT NULL
            )
        ');
        $bd->statement('CREATE INDEX xarxa_trams_a ON xarxa_trams (a)');
        $bd->statement('CREATE INDEX xarxa_trams_b ON xarxa_trams (b)');
        $bd->statement('
            CREATE TABLE xarxa_tram_sortida (
                tram_id    INTEGER NOT NULL,
                sortida_id INTEGER NOT NULL,
                PRIMARY KEY (tram_id, sortida_id)
            ) WITHOUT ROWID
        ');
    }

    public function down(): void
    {
        $bd = DB::connection($this->connection);
        foreach (['xarxa_tram_sortida', 'xarxa_trams', 'xarxa_nodes'] as $taula) {
            $bd->statement("DROP TABLE IF EXISTS {$taula}");
        }
    }
};
