<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repostatges d'un vehicle a motor.
 *
 * Es desa només el que s'apunta a mà —data, quilòmetres del comptador, preu del litre i
 * cost—; els litres, els quilòmetres fets i el consum es calculen, que és com estava al
 * full de càlcul d'on venen aquestes dades.
 *
 * El `moviment_id` és opcional i no hi és per estalviar-se d'escriure el cost: és el que
 * permetrà saber de quin vehicle és cada repostatge de `DESPESES > MOTOR > GASOLINA`, que
 * avui va tot a un sol calaix i fa que el cost per quilòmetre no es pugui calcular.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('g_vehicles_motor_repostatges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('g_vehicles')->cascadeOnDelete();
            $table->date('data');
            // El que marca el comptador, no els quilòmetres del dipòsit
            $table->unsignedInteger('km_totals');
            $table->decimal('preu_litre', 6, 3);
            $table->decimal('cost', 10, 2);
            // Ple fins dalt: si no ho és, els litres no diuen el consum real
            $table->boolean('diposit_ple')->default(true);
            $table->foreignId('moviment_id')->nullable()->constrained('g_moviments_comptes_corrents')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['vehicle_id', 'data']);
        });

        Schema::table('g_vehicles', function (Blueprint $table) {
            // Buit a les bicis i als vehicles elèctrics que no reposten
            $table->string('combustible', 20)->nullable()->after('tipus');
        });
    }

    public function down(): void
    {
        Schema::table('g_vehicles', function (Blueprint $table) {
            $table->dropColumn('combustible');
        });

        Schema::dropIfExists('g_vehicles_motor_repostatges');
    }
};
