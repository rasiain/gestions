<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Les sortides en bici, una fila per sortida, amb el resum. Els punts del recorregut
     * no hi són: van a la BD `recorreguts`, i l'original, a storage/app/sortides.
     *
     * El resum de les columnes és el de l'aplicació d'origen (Garmin Connect), que és el que
     * l'usuari veu allà i té el desnivell corregit quan escau. El del dispositiu es desa sencer
     * a `resum_dispositiu`, i el registre de l'origen, a `dades_originals`. Tot en SI: m, s, m/s.
     */
    public function up(): void
    {
        Schema::create('g_sortides_bici', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('g_vehicles')->cascadeOnDelete();
            $table->string('origen', 20);
            $table->string('id_extern', 64);
            $table->string('nom', 200)->nullable();
            $table->string('tipus_activitat', 40)->nullable();
            $table->dateTime('inici');                  // UTC
            $table->date('data_local');
            $table->decimal('distancia_m', 10, 1)->nullable();
            $table->unsignedInteger('temps_s')->nullable();           // cronòmetre en marxa
            $table->unsignedInteger('temps_moviment_s')->nullable();
            $table->unsignedInteger('temps_total_s')->nullable();     // de l'inici al final
            $table->decimal('desnivell_pos_m', 7, 1)->nullable();
            $table->decimal('desnivell_neg_m', 7, 1)->nullable();
            $table->decimal('alt_min_m', 7, 1)->nullable();
            $table->decimal('alt_max_m', 7, 1)->nullable();
            $table->decimal('vel_mitjana', 6, 3)->nullable();         // m/s
            $table->decimal('vel_max', 6, 3)->nullable();
            $table->unsignedSmallInteger('fc_mitjana')->nullable();
            $table->unsignedSmallInteger('fc_max')->nullable();
            $table->unsignedSmallInteger('cadencia_mitjana')->nullable();
            $table->unsignedInteger('calories')->nullable();          // kcal
            $table->decimal('temp_min', 4, 1)->nullable();
            $table->decimal('temp_max', 4, 1)->nullable();
            $table->decimal('lat_inici', 9, 6)->nullable();
            $table->decimal('lon_inici', 9, 6)->nullable();
            // El rectangle que ocupa: filtre previ de les consultes per zona, sense llegir punts
            $table->decimal('lat_min', 9, 6)->nullable();
            $table->decimal('lat_max', 9, 6)->nullable();
            $table->decimal('lon_min', 9, 6)->nullable();
            $table->decimal('lon_max', 9, 6)->nullable();
            // Polilínia codificada (format de Google, 5 decimals) per dibuixar el mapa d'una llista
            $table->text('recorregut_simplificat')->nullable();
            $table->boolean('desnivell_corregit')->default(false);
            $table->string('fitxer')->nullable();                     // relatiu a storage/app/sortides
            $table->unsignedInteger('punts')->default(0);
            $table->json('resum_dispositiu')->nullable();
            $table->json('dades_originals')->nullable();
            $table->timestamps();

            $table->unique(['origen', 'id_extern']);
            $table->index(['vehicle_id', 'data_local']);
        });

        // Amb quin material es va fer cada sortida: la bici i els components. D'aquí surten
        // els quilòmetres de cada cadena o coberta.
        Schema::create('g_sortida_material', function (Blueprint $table) {
            $table->foreignId('sortida_id')->constrained('g_sortides_bici')->cascadeOnDelete();
            $table->foreignId('material_id')->constrained('g_material_esportiu')->cascadeOnDelete();
            $table->primary(['sortida_id', 'material_id']);
            $table->index('material_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g_sortida_material');
        Schema::dropIfExists('g_sortides_bici');
    }
};
