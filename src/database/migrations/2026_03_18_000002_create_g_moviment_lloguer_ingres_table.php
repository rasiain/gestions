<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('g_moviment_lloguer_ingres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('moviment_id')->unique()->constrained('g_moviments_comptes_corrents')->cascadeOnDelete();
            $table->foreignId('lloguer_id')->constrained('g_lloguers')->cascadeOnDelete();
            $table->decimal('base_lloguer', 10, 2);
            $table->decimal('gestoria_import', 10, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g_moviment_lloguer_ingres');
    }
};
