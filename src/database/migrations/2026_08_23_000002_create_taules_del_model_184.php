<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La declaració del 184 tal com es va presentar.
 *
 * Recalcular-la anys després des dels moviments no dona el mateix: una despesa canvia de
 * categoria, una factura es corregeix, i el número ja no és el que va anar a Hisenda. Per
 * això la declaració es congela, com `g_taxes_rebuts` congela el total del rebut.
 *
 * Els noms —del lloguer, del comuner, la referència cadastral— s'hi desen copiats i no
 * per clau forana: una declaració presentada no pot canviar perquè després es reanomeni
 * un immoble.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('g_184_declaracions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comunitat_bens_id')->constrained('g_comunitats_bens')->cascadeOnDelete();
            $table->unsignedSmallInteger('any');
            $table->string('comunitat_nom', 200);
            $table->string('comunitat_nif', 20)->nullable();
            $table->decimal('retencions', 12, 2);
            // El que dona l'AEAT en presentar-la
            $table->string('numero_identificatiu', 40)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('materialitzada_el');
            $table->timestamps();

            $table->unique(['comunitat_bens_id', 'any']);
        });

        // Un registre de clau C per referència cadastral
        Schema::create('g_184_immobles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('declaracio_id')->constrained('g_184_declaracions')->cascadeOnDelete();
            $table->foreignId('immoble_id')->nullable()->constrained('g_immobles')->nullOnDelete();
            $table->string('referencia_cadastral', 40)->nullable();
            $table->string('lloguer_nom', 200);
            $table->decimal('ingressos', 12, 2);
            $table->decimal('despeses', 12, 2);
            $table->decimal('rendiment_net', 12, 2);
            $table->decimal('retencions', 12, 2);
            $table->decimal('amortitzacio', 12, 2);
            $table->decimal('base_repartible', 12, 2);
            $table->timestamps();
        });

        // Les deu caselles de despesa: una fila per casella amb import
        Schema::create('g_184_caselles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('immoble_184_id')->constrained('g_184_immobles')->cascadeOnDelete();
            $table->unsignedTinyInteger('casella');
            $table->decimal('import', 12, 2);
            $table->timestamps();

            $table->unique(['immoble_184_id', 'casella']);
        });

        Schema::create('g_184_comuners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('immoble_184_id')->constrained('g_184_immobles')->cascadeOnDelete();
            $table->foreignId('persona_id')->nullable()->constrained('g_persones')->nullOnDelete();
            $table->string('nom', 200);
            $table->string('nif', 20)->nullable();
            $table->decimal('quota', 7, 4)->nullable();
            $table->decimal('amortitzacio', 12, 2);
            $table->decimal('rendiment', 12, 2)->nullable();
            $table->decimal('participacio', 7, 4)->nullable();
            $table->decimal('retencio', 12, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g_184_comuners');
        Schema::dropIfExists('g_184_caselles');
        Schema::dropIfExists('g_184_immobles');
        Schema::dropIfExists('g_184_declaracions');
    }
};
