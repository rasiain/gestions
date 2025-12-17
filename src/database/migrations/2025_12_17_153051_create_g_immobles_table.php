<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('g_immobles', function (Blueprint $table) {
            $table->id();
            $table->string('referencia_cadastral')->unique();
            $table->string('adreca');
            $table->decimal('superficie_construida', 10, 2)->nullable();
            $table->decimal('superficie_parcela', 10, 2)->nullable();
            $table->enum('us', ['residencial', 'oficines', 'magatzem_estacionament', 'agrari'])->nullable();
            $table->decimal('valor_sol', 12, 2)->nullable();
            $table->decimal('valor_construccio', 12, 2)->nullable();
            $table->decimal('valor_adquisicio', 12, 2)->nullable();
            $table->string('referencia_administracio', 50)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('g_immobles');
    }
};
