<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'recorreguts';

    /**
     * Els punts de cada recorregut, a la BD `recorreguts`. Els valors són els que va gravar el
     * dispositiu, sense cap pèrdua: les coordenades en semicercles (graus = v × 180 / 2³¹), i la
     * resta a l'escala nativa del FIT —altitud i distància en cm, velocitat en mm/s—. `t` són
     * els segons des de l'inici de la sessió.
     *
     * Aquestes columnes no s'actualitzen mai. Els ajustos que es facin en un futur aniran en
     * columnes a part.
     *
     * Ordenada per sortida i sense rowid: llegir una sortida sencera és un sol tram de l'arbre,
     * i no s'alenteix quan la taula creix (mesurat: 1 ms amb 2,6 i amb 10 milions de punts).
     */
    public function up(): void
    {
        DB::connection($this->connection)->statement('
            CREATE TABLE punts (
                sortida_id INTEGER NOT NULL,
                t          INTEGER NOT NULL,
                lat        INTEGER,
                lon        INTEGER,
                alt        INTEGER,
                dist       INTEGER,
                vel        INTEGER,
                fc         INTEGER,
                cad        INTEGER,
                temp       INTEGER,
                PRIMARY KEY (sortida_id, t)
            ) WITHOUT ROWID
        ');
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS punts');
    }
};
