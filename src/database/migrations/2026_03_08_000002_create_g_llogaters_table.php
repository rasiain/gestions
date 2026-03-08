<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('g_llogaters', function (Blueprint $table) {
            $table->id();
            $table->string('nom', 50);
            $table->string('cognoms', 100);
            $table->string('identificador', 20)->nullable();
            $table->foreignId('lloguer_id')->constrained('g_lloguers')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g_llogaters');
    }
};
