<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Renda fixa: bons, obligacions i estructurats.
 *
 * S'organitza com els fons —un catàleg de títols i un contracte per compte— però amb dues
 * diferències que marca l'extracte del banc:
 *
 *   - Hi conviuen el NOMINAL (el que es va contractar) i el VALOR PATRIMONIAL (el que val
 *     ara). En un estructurat a tres anys es poden separar força.
 *   - El valor es desa per CONTRACTE i no per títol, en euros i no com a preu unitari,
 *     perquè és així com ve a l'extracte i és el que s'hi copiarà.
 *
 * La sèrie de valors per data és el que permetrà saber què valia el 31 de desembre, que és
 * el que demana la declaració de patrimoni.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('g_rf_titols', function (Blueprint $table) {
            $table->id();
            $table->string('isin', 12)->unique();
            $table->string('nom', 200);
            $table->string('emissor', 150)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('g_rf_contractes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('titol_id')->constrained('g_rf_titols')->cascadeOnDelete();
            // El compte del contracte: d'aquí surten els titulars, com als fons
            $table->foreignId('compte_corrent_id')->constrained('g_comptes_corrents')->cascadeOnDelete();
            // On arriben els cupons, que sol ser un compte corrent i no el del títol
            $table->foreignId('compte_rendibilitat_id')->nullable()->constrained('g_comptes_corrents')->nullOnDelete();
            $table->decimal('nominal', 14, 2);
            $table->date('data_compra')->nullable();
            $table->date('data_venciment')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('g_rf_valors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contracte_id')->constrained('g_rf_contractes')->cascadeOnDelete();
            $table->date('data');
            $table->decimal('valor_patrimonial', 14, 2);
            $table->timestamps();

            $table->unique(['contracte_id', 'data']);
        });

        // Cupons i interessos: el benefici de l'any
        Schema::create('g_rf_rendibilitats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contracte_id')->constrained('g_rf_contractes')->cascadeOnDelete();
            $table->date('data');
            $table->decimal('import', 14, 2);
            // Quan es pugui lligar amb el moviment del compte on ha arribat
            $table->foreignId('moviment_id')->nullable()->constrained('g_moviments_comptes_corrents')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g_rf_rendibilitats');
        Schema::dropIfExists('g_rf_valors');
        Schema::dropIfExists('g_rf_contractes');
        Schema::dropIfExists('g_rf_titols');
    }
};
