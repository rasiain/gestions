<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Com es mira l'altitud de les sortides: el mètode (dispositiu, terreny o combinat) i la font del
     * model (la millor disponible, ICGC o IGN). Per defecte, a `g_preferencies` —la primera
     * preferència de l'aplicació, i per això una taula clau-valor i no una columna—; per a una sortida
     * concreta, a les dues columnes noves (buides = la de per defecte).
     */
    public function up(): void
    {
        Schema::create('g_preferencies', function (Blueprint $table) {
            $table->string('clau', 100)->primary();
            $table->json('valor');
            $table->timestamps();
        });

        Schema::table('g_sortides_bici', function (Blueprint $table) {
            $table->string('altitud_metode', 20)->nullable();
            $table->string('altitud_font', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('g_sortides_bici', function (Blueprint $table) {
            $table->dropColumn(['altitud_metode', 'altitud_font']);
        });
        Schema::dropIfExists('g_preferencies');
    }
};
