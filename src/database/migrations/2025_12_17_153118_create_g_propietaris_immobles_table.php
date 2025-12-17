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
        Schema::create('g_propietaris_immobles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('immoble_id')->constrained('g_immobles')->onDelete('cascade');
            $table->foreignId('persona_id')->constrained('g_persones')->onDelete('cascade');
            $table->date('data_inici');
            $table->date('data_fi')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('g_propietaris_immobles');
    }
};
