<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capital social: les aportacions que fan soci d'una cooperativa de crèdit.
 *
 * S'organitza com la renda fixa —un contracte per compte, i una sèrie de valors per data—
 * amb dues diferències que marca l'extracte:
 *
 *   - No hi ha catàleg de títols. El títol del capital social no és cap producte de mercat
 *     amb ISIN: és de l'entitat del compte, i el número de contracte és el del compte mateix.
 *   - El valor són DOS números, títols i nominal unitari, i no un import: així ve a
 *     l'extracte («Nre. títols 11 · Valor Nominal Unitari 100,00»). El total no es desa,
 *     que és el producte dels dos i desar-lo només permetria que es contradiguessin.
 *
 * Els titulars surten del compte, com a tot el grup d'inversions: el «N. titulars» de
 * l'extracte no s'escriu enlloc, es compta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('g_cs_contractes', function (Blueprint $table) {
            $table->id();
            // El compte del capital social: d'aquí surten l'entitat, el número i els titulars
            $table->foreignId('compte_corrent_id')->constrained('g_comptes_corrents')->cascadeOnDelete();
            // On arriben els interessos, que sol ser un compte corrent i no el del capital
            $table->foreignId('compte_rendiment_id')->nullable()->constrained('g_comptes_corrents')->nullOnDelete();
            $table->date('data_alta')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('g_cs_valors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contracte_id')->constrained('g_cs_contractes')->cascadeOnDelete();
            $table->date('data');
            $table->unsignedInteger('titols');
            $table->decimal('valor_unitari', 12, 2);
            $table->timestamps();

            $table->unique(['contracte_id', 'data']);
        });

        // El que la cooperativa paga cada any per les aportacions
        Schema::create('g_cs_rendiments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contracte_id')->constrained('g_cs_contractes')->cascadeOnDelete();
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
        Schema::dropIfExists('g_cs_rendiments');
        Schema::dropIfExists('g_cs_valors');
        Schema::dropIfExists('g_cs_contractes');
    }
};
