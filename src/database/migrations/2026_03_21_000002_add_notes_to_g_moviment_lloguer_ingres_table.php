<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('g_moviment_lloguer_ingres', function (Blueprint $table) {
            $table->string('notes', 500)->nullable()->after('gestoria_import');
        });
    }

    public function down(): void
    {
        Schema::table('g_moviment_lloguer_ingres', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
