<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('g_lloguers', function (Blueprint $table) {
            $table->id();
            $table->string('nom', 100);
            $table->foreignId('immoble_id')->constrained('g_immobles')->cascadeOnDelete();
            $table->foreignId('compte_corrent_id')->constrained('g_comptes_corrents')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g_lloguers');
    }
};
