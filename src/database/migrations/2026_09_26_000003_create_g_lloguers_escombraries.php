<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Com es repercuteixen les escombraries industrials al llogater, any per any.
     *
     * L'import i el nombre de terminis es proposen des de `g_taxes_rebuts`, que és
     * qui sap què gira l'ajuntament, però es desen a part: el que es repercuteix és
     * el que s'ha pactat, i el 2026 són 1.618,59 × 3 = 4.855,77 contra els 4.856,25
     * del rebut.
     */
    public function up(): void
    {
        Schema::create('g_lloguers_escombraries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lloguer_id')->constrained('g_lloguers')->cascadeOnDelete();
            $table->integer('any');
            $table->decimal('import_total', 10, 2)->nullable();
            // A Juli Garreta cada fracció va en una factura pròpia, que consumeix
            // número; a Joan Maragall és una línia de la mensual.
            $table->boolean('factura_separada')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['lloguer_id', 'any']);
        });

        Schema::create('g_lloguers_escombraries_fraccions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('escombraries_id')->constrained('g_lloguers_escombraries')->cascadeOnDelete();
            $table->unsignedTinyInteger('ordre');
            $table->unsignedTinyInteger('mes');
            // Els imports de les fraccions poden no ser iguals per arrodoniment.
            $table->decimal('import', 10, 2);
            $table->timestamps();

            $table->unique(['escombraries_id', 'ordre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g_lloguers_escombraries_fraccions');
        Schema::dropIfExists('g_lloguers_escombraries');
    }
};
