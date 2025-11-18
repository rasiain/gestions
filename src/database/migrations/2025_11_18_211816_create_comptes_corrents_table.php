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
        Schema::create('g_comptes_corrents', function (Blueprint $table) {
            $table->id();
            $table->char('compte_corrent', 24);
            $table->string('entitat', 200);
            $table->unsignedTinyInteger('ordre')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('g_comptes_corrents');
    }
};
