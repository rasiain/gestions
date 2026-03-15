<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('g_contracte_llogater', function (Blueprint $table) {
            $table->foreignId('contracte_id')->constrained('g_contractes')->cascadeOnDelete();
            $table->foreignId('llogater_id')->constrained('g_llogaters')->cascadeOnDelete();
            $table->primary(['contracte_id', 'llogater_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g_contracte_llogater');
    }
};
