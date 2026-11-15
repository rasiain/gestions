<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les despeses de les bicis van a la mateixa taula que les dels vehicles a motor (el nom de la taula
 * ve d'abans: `vehicle_id` ja admetia una bici). A una bici, però, sovint la despesa és d'una peça
 * —una cadena, una coberta—, i amb la peça es pot saber què costa cada component per km.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('g_vehicles_motor_despeses', function (Blueprint $table) {
            $table->foreignId('material_id')->nullable()->after('moviment_id')->constrained('g_material_esportiu')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('g_vehicles_motor_despeses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('material_id');
        });
    }
};
