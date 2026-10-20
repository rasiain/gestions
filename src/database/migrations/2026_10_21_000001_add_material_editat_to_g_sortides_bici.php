<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El material d'una sortida (la bici i els components) es pot corregir a mà. Una sortida
     * corregida ho porta marcat, i una importació posterior n'actualitza el resum però no li
     * torna a posar el material que diu Garmin.
     */
    public function up(): void
    {
        Schema::table('g_sortides_bici', function (Blueprint $table) {
            $table->boolean('material_editat')->default(false)->after('punts');
        });
    }

    public function down(): void
    {
        Schema::table('g_sortides_bici', function (Blueprint $table) {
            $table->dropColumn('material_editat');
        });
    }
};
