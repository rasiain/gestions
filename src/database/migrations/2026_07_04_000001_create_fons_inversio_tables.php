<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('g_fi_fons', function (Blueprint $table) {
            $table->id();
            $table->string('nom', 200);
            $table->string('isin', 20)->nullable();
            $table->date('data_creacio');
            $table->timestamps();
        });

        Schema::create('g_fi_fons_titulars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fons_id')->constrained('g_fi_fons')->cascadeOnDelete();
            $table->foreignId('persona_id')->constrained('g_persones')->cascadeOnDelete();
            $table->unique(['fons_id', 'persona_id']);
        });

        Schema::create('g_fi_aportacions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fons_id')->constrained('g_fi_fons')->cascadeOnDelete();
            $table->date('data');
            $table->decimal('import', 12, 2)->comment('Diners invertits en aquesta aportació');
            $table->decimal('participacions', 16, 6)->comment('Nombre de participacions adquirides');
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('g_fi_valors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fons_id')->constrained('g_fi_fons')->cascadeOnDelete();
            $table->date('data');
            $table->decimal('valor_participacio', 12, 6)->comment('Valor d\'una participació en aquesta data');
            $table->unique(['fons_id', 'data']);
            $table->timestamps();
        });

        Schema::create('g_fi_despeses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fons_id')->constrained('g_fi_fons')->cascadeOnDelete();
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
        Schema::dropIfExists('g_fi_fons_titulars');
        Schema::dropIfExists('g_fi_fons');
    }
};
