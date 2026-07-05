<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('g_pp_plans', function (Blueprint $table) {
            $table->id();
            $table->string('nom', 200);
            $table->timestamps();
        });

        Schema::create('g_pp_contractes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pla_id')->constrained('g_pp_plans')->cascadeOnDelete();
            $table->foreignId('compte_corrent_id')->constrained('g_comptes_corrents')->cascadeOnDelete();
            $table->date('data_inici');
            $table->date('data_fi')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('g_pp_aportacions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contracte_id')->constrained('g_pp_contractes')->cascadeOnDelete();
            $table->date('data');
            $table->decimal('import', 12, 2)->comment('Diners invertits');
            $table->decimal('participacions', 16, 6)->comment('Participacions adquirides');
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('g_pp_valors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pla_id')->constrained('g_pp_plans')->cascadeOnDelete();
            $table->date('data');
            $table->decimal('valor_participacio', 12, 6);
            $table->unique(['pla_id', 'data']);
            $table->timestamps();
        });

        Schema::create('g_pp_despeses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contracte_id')->constrained('g_pp_contractes')->cascadeOnDelete();
            $table->date('data');
            $table->decimal('import', 12, 2);
            $table->string('concepte', 300)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g_pp_despeses');
        Schema::dropIfExists('g_pp_valors');
        Schema::dropIfExists('g_pp_aportacions');
        Schema::dropIfExists('g_pp_contractes');
        Schema::dropIfExists('g_pp_plans');
    }
};
