<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El material esportiu tal com el porta l'aplicació d'on ve (avui Garmin): bicis,
     * sabates, cadenes, cobertes… No és el catàleg de vehicles —això és `g_vehicles`—
     * sinó el registre de l'origen, que hi apunta quan és una bici.
     *
     * Serveix per dues coses: les sortides diuen amb quin material es van fer pel seu
     * identificador a l'origen (`id_extern`), i d'aquí a la bici de l'aplicació; i els
     * components queden desats per portar-ne el manteniment.
     *
     * Res de l'esquema no és de Garmin: el que és propi de cada origen (l'uuid de Garmin,
     * posem) va a `dades_originals`, tal com ve.
     */
    public function up(): void
    {
        Schema::create('g_material_esportiu', function (Blueprint $table) {
            $table->id();
            $table->string('origen', 20);
            $table->string('id_extern', 64);
            $table->string('tipus', 20);            // bici, sabates, component, altres
            $table->string('nom', 150);
            $table->string('model', 150)->nullable();
            $table->string('estat', 20);            // actiu, retirat
            $table->date('data_inici')->nullable();
            $table->date('data_fi')->nullable();
            $table->foreignId('vehicle_id')->nullable()->constrained('g_vehicles')->nullOnDelete();
            $table->json('dades_originals')->nullable();
            $table->timestamps();

            $table->unique(['origen', 'id_extern']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g_material_esportiu');
    }
};
