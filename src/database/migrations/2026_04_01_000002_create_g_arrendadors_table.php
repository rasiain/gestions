<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('g_arrendadors', function (Blueprint $table) {
            $table->id();
            $table->string('arrendadorable_type');
            $table->unsignedBigInteger('arrendadorable_id');
            $table->string('adreca', 255)->nullable();
            $table->timestamps();

            $table->index(['arrendadorable_type', 'arrendadorable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g_arrendadors');
    }
};
