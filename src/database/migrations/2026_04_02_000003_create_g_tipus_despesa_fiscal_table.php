<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('g_tipus_despesa_fiscal', function (Blueprint $table) {
            $table->id();
            $table->string('codi', 10)->unique();
            $table->string('descripcio', 255);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g_tipus_despesa_fiscal');
    }
};
