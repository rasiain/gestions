<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('g_lloguers', function (Blueprint $table) {
            $table->boolean('es_habitatge')->default(false)->after('gestoria_percentatge');
        });
    }

    public function down(): void
    {
        Schema::table('g_lloguers', function (Blueprint $table) {
            $table->dropColumn('es_habitatge');
        });
    }
};
