<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Les rutes canòniques: grups de sortides que fan el mateix recorregut, a cada nivell de
     * tolerància, amb una sortida real del grup com a representant. Són dades derivades: les
     * calcula `rutes:calcula` i es poden tornar a calcular sense perdre res.
     */
    public function up(): void
    {
        Schema::create('g_rutes', function (Blueprint $table) {
            $table->id();
            $table->string('nivell', 20);                 // identiques, mateixa, variants, zona
            $table->foreignId('canonica_id')->constrained('g_sortides_bici')->cascadeOnDelete();
            $table->unsignedInteger('sortides');
            $table->string('nom', 200)->nullable();
            $table->timestamps();

            $table->index(['nivell', 'sortides']);
        });

        // A quina ruta pertany cada sortida a cada nivell, i quant comparteix amb el canònic
        Schema::create('g_sortida_ruta', function (Blueprint $table) {
            $table->foreignId('sortida_id')->constrained('g_sortides_bici')->cascadeOnDelete();
            $table->string('nivell', 20);
            $table->foreignId('ruta_id')->constrained('g_rutes')->cascadeOnDelete();
            $table->decimal('semblanca', 4, 3);

            $table->primary(['sortida_id', 'nivell']);
            $table->index('ruta_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g_sortida_ruta');
        Schema::dropIfExists('g_rutes');
    }
};
