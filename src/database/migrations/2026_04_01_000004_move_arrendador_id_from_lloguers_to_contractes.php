<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Afegir arrendador_id a g_contractes
        Schema::table('g_contractes', function (Blueprint $table) {
            $table->foreignId('arrendador_id')
                ->nullable()
                ->constrained('g_arrendadors')
                ->nullOnDelete();
        });

        // Treure arrendador_id de g_lloguers
        Schema::table('g_lloguers', function (Blueprint $table) {
            $table->dropForeign(['arrendador_id']);
            $table->dropColumn('arrendador_id');
        });
    }

    public function down(): void
    {
        Schema::table('g_lloguers', function (Blueprint $table) {
            $table->foreignId('arrendador_id')
                ->nullable()
                ->constrained('g_arrendadors')
                ->nullOnDelete();
        });

        Schema::table('g_contractes', function (Blueprint $table) {
            $table->dropForeign(['arrendador_id']);
            $table->dropColumn('arrendador_id');
        });
    }
};
