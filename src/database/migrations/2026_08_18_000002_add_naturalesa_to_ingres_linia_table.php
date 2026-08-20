<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les línies d'un ingrés eren totes deduccions: gestoria i reparacions, que es
 * RESTEN de la base per arribar al que va entrar al banc.
 *
 * Les repercussions (l'escombraries que retorna el llogater) van al revés: ja
 * són dins de `base_lloguer`, i el que cal és desglossar-les-hi. Per això no es
 * poden barrejar amb les deduccions sense dir de quina naturalesa és cada línia.
 *
 * `base_lloguer` no canvia de significat: continua sent el total cobrat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('g_moviment_lloguer_ingres_linia', function (Blueprint $table) {
            $table->string('naturalesa', 20)->default('deduccio')->after('tipus');
        });
    }

    public function down(): void
    {
        Schema::table('g_moviment_lloguer_ingres_linia', function (Blueprint $table) {
            $table->dropColumn('naturalesa');
        });
    }
};
