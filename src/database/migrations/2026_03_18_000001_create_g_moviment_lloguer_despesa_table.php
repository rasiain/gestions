<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('g_moviment_lloguer_despesa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('moviment_id')->unique()->constrained('g_moviments_comptes_corrents')->cascadeOnDelete();
            $table->foreignId('lloguer_id')->constrained('g_lloguers')->cascadeOnDelete();
            $table->string('categoria', 20); // comunitat|taxes|assegurança|compres|reparacions|altres
            $table->foreignId('proveidor_id')->nullable()->constrained('g_proveidors')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g_moviment_lloguer_despesa');
    }
};
