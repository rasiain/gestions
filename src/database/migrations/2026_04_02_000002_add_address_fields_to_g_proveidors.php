<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('g_proveidors', function (Blueprint $table) {
            $table->string('codi_postal', 10)->nullable()->after('adreca');
            $table->string('poblacio', 100)->nullable()->after('codi_postal');
            $table->string('provincia', 100)->nullable()->after('poblacio');
            $table->string('pais', 100)->nullable()->default('Espanya')->after('provincia');
        });
    }

    public function down(): void
    {
        Schema::table('g_proveidors', function (Blueprint $table) {
            $table->dropColumn(['codi_postal', 'poblacio', 'provincia', 'pais']);
        });
    }
};
