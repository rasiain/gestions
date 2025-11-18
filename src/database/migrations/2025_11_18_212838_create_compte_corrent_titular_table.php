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
        Schema::create('g_compte_corrent_titular', function (Blueprint $table) {
            $table->foreignId('compte_corrent_id')->constrained('g_comptes_corrents')->onDelete('cascade');
            $table->foreignId('titular_id')->constrained('g_titulars')->onDelete('cascade');
            $table->primary(['compte_corrent_id', 'titular_id']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('g_compte_corrent_titular');
    }
};
