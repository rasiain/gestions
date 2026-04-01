<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('g_comunitats_bens', function (Blueprint $table) {
            $table->id();
            $table->string('nom', 255);
            $table->string('nif', 20)->unique()->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g_comunitats_bens');
    }
};
