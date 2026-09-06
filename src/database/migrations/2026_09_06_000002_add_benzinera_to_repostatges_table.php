<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * On es va repostar. No és cap càlcul: s'apunta, i serveix per veure on surt més barat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('g_vehicles_motor_repostatges', function (Blueprint $table) {
            $table->string('benzinera', 100)->nullable()->after('cost');
        });
    }

    public function down(): void
    {
        Schema::table('g_vehicles_motor_repostatges', function (Blueprint $table) {
            $table->dropColumn('benzinera');
        });
    }
};
