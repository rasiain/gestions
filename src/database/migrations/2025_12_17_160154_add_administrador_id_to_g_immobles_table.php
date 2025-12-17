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
        Schema::table('g_immobles', function (Blueprint $table) {
            $table->foreignId('administrador_id')->nullable()->constrained('g_proveidors')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('g_immobles', function (Blueprint $table) {
            $table->dropForeign(['administrador_id']);
            $table->dropColumn('administrador_id');
        });
    }
};
