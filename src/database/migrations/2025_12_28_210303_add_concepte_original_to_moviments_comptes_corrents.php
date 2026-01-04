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
        Schema::table('g_moviments_comptes_corrents', function (Blueprint $table) {
            $table->string('concepte_original')->nullable()->after('concepte');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('g_moviments_comptes_corrents', function (Blueprint $table) {
            $table->dropColumn('concepte_original');
        });
    }
};
