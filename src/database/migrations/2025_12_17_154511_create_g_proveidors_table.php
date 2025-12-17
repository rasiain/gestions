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
        Schema::create('g_proveidors', function (Blueprint $table) {
            $table->id();
            $table->string('nom_rao_social');
            $table->string('nif_cif', 20)->nullable();
            $table->string('adreca')->nullable();
            $table->string('correu_electronic')->nullable();
            $table->string('telefons')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('g_proveidors');
    }
};
