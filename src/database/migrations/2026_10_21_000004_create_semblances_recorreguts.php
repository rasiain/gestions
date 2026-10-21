<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'recorreguts';

    /**
     * Quant s'assemblen dues sortides: la part del recorregut que comparteixen, presa del costat
     * que en comparteix menys (0–1). Només les parelles que arriben al nivell més ample: la resta
     * no s'agrupen mai. Va a la BD de recorreguts perquè en poden ser centenars de milers i és
     * dada derivada dels punts.
     */
    public function up(): void
    {
        DB::connection($this->connection)->statement('
            CREATE TABLE semblances (
                a     INTEGER NOT NULL,
                b     INTEGER NOT NULL,
                valor REAL    NOT NULL,
                PRIMARY KEY (a, b)
            ) WITHOUT ROWID
        ');
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS semblances');
    }
};
