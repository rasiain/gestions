<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catàleg de vehicles: cotxes, motos, bicicletes.
 *
 * De moment només el catàleg. El control de despeses i de quilòmetres hi anirà a sobre,
 * però encara no s'ha decidit ni d'on surten les despeses ni com s'apunten els km.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('g_vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('nom', 100);
            $table->string('tipus', 20)->default('cotxe');   // cotxe, moto, bici, altres
            $table->string('marca', 60)->nullable();
            $table->string('model', 60)->nullable();
            $table->string('matricula', 20)->nullable();
            $table->unsignedSmallInteger('any_fabricacio')->nullable();
            // Mentre és nostre: un vehicle venut no desapareix, deixa de comptar
            $table->date('data_alta')->nullable();
            $table->date('data_baixa')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedTinyInteger('ordre')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g_vehicles');
    }
};
