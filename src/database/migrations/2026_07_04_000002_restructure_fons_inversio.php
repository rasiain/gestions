<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Afegir tipus a comptes corrents
        Schema::table('g_comptes_corrents', function (Blueprint $table) {
            $table->string('tipus', 30)->default('corrent')->after('ordre');
        });

        // Eliminar taules g_fi_* anteriors (en ordre invers a les FKs)
        Schema::dropIfExists('g_fi_despeses');
        Schema::dropIfExists('g_fi_aportacions');
        Schema::dropIfExists('g_fi_valors');
        Schema::dropIfExists('g_fi_fons_titulars');
        Schema::dropIfExists('g_fi_fons');

        // Catàleg de fons (només nom)
        Schema::create('g_fi_fons', function (Blueprint $table) {
            $table->id();
            $table->string('nom', 200);
            $table->timestamps();
        });

        // Contractació d'un fons per un compte bancari
        Schema::create('g_fi_contractes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fons_id')->constrained('g_fi_fons')->cascadeOnDelete();
            $table->foreignId('compte_corrent_id')->constrained('g_comptes_corrents')->cascadeOnDelete();
            $table->date('data_inici');
            $table->date('data_fi')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });

        // Aportacions per contracte
        Schema::create('g_fi_aportacions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contracte_id')->constrained('g_fi_contractes')->cascadeOnDelete();
            $table->date('data');
            $table->decimal('import', 12, 2)->comment('Diners invertits');
            $table->decimal('participacions', 16, 6)->comment('Participacions adquirides');
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });

        // Historial de valors per participació (a nivell de fons)
        Schema::create('g_fi_valors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fons_id')->constrained('g_fi_fons')->cascadeOnDelete();
            $table->date('data');
            $table->decimal('valor_participacio', 12, 6);
            $table->unique(['fons_id', 'data']);
            $table->timestamps();
        });

        // Despeses per contracte
        Schema::create('g_fi_despeses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contracte_id')->constrained('g_fi_contractes')->cascadeOnDelete();
            $table->date('data');
            $table->decimal('import', 12, 2);
            $table->string('concepte', 300)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g_fi_despeses');
        Schema::dropIfExists('g_fi_valors');
        Schema::dropIfExists('g_fi_aportacions');
        Schema::dropIfExists('g_fi_contractes');
        Schema::dropIfExists('g_fi_fons');

        Schema::table('g_comptes_corrents', function (Blueprint $table) {
            $table->dropColumn('tipus');
        });
    }
};
