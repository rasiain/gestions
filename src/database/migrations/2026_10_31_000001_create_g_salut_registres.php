<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Les dades físiques de l'usuari, per dates: el que necessita el càlcul de l'estat de forma.
     * Totes opcionals, i cada una val des de la seva data fins al registre següent que la porti.
     * Sense persona, com les sortides en bici: totes són de l'usuari.
     */
    public function up(): void
    {
        Schema::create('g_salut_registres', function (Blueprint $table) {
            $table->id();
            $table->date('data')->unique();
            $table->decimal('pes', 5, 2)->nullable();
            $table->unsignedSmallInteger('fc_repos')->nullable();
            // Buida: es dedueix de les sortides
            $table->unsignedSmallInteger('fc_maxima')->nullable();
            $table->text('notes')->nullable();
            // `manual` o `apple_salut` (la càrrega inicial, que mai no reescriu un de manual)
            $table->string('origen', 20)->default('manual');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g_salut_registres');
    }
};
