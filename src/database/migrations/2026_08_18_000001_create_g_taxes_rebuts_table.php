<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El total anual que l'ajuntament gira per un immoble i un concepte.
 *
 * La vista de taxes només sap què s'ha pagat; sense el total no pot dir si els
 * 3.237,18 € d'escombraries d'un local són tot el rebut o dos terços. Aquí es
 * desa aquest total, i d'ell surten el percentatge pagat, el que queda i —als
 * immobles de lloguer— el que el llogater ha de retornar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('g_taxes_rebuts', function (Blueprint $table) {
            $table->id();
            // Clau d'agrupació de la vista (TaxesService: `nom:<ETIQUETA>|<POBLACIÓ>` o `immoble:<id>`)
            $table->string('grup', 180);
            $table->foreignId('immoble_id')->nullable()->constrained('g_immobles')->nullOnDelete();
            $table->string('tipus', 60);
            $table->unsignedSmallInteger('any');
            // Buida = un sol rebut per grup, tipus i any. Reservada per si cal
            // distingir dos rebuts del mateix tipus (dues referències cadastrals).
            $table->string('referencia', 80)->default('');
            $table->decimal('import_total', 12, 2);
            $table->unsignedTinyInteger('terminis_previstos')->nullable();
            // El llogater el retorna: avui només l'escombraries
            $table->boolean('repercutible')->default(false);
            $table->string('concepte_repercussio', 40)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['grup', 'tipus', 'any', 'referencia']);
            $table->index(['any', 'tipus']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g_taxes_rebuts');
    }
};
