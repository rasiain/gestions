<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El desnivell de cada sortida per mètode i per font, per a les llistes, les rutes i la fitxa de
     * la bici, que no poden llegir els punts de milers de sortides. Són dades derivades dels punts i
     * de `altituds_terreny`; es calculen amb el mateix llindar que la pantalla de la sortida.
     *
     * - metode `dispositiu`: l'altitud dels punts (font `dispositiu`);
     * - metode `terreny`: la d'un model (font `icgc` o `ign`), només si cobreix tota la sortida;
     * - metode `combinat`: pendent.
     */
    public function up(): void
    {
        Schema::create('g_sortida_desnivells', function (Blueprint $table) {
            $table->foreignId('sortida_id')->constrained('g_sortides_bici')->cascadeOnDelete();
            $table->string('metode', 20);
            $table->string('font', 20);
            $table->decimal('pujada_m', 8, 1);
            $table->decimal('baixada_m', 8, 1);

            $table->primary(['sortida_id', 'metode', 'font']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g_sortida_desnivells');
    }
};
