<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les despeses d'un vehicle a motor que no són combustible: reparacions, ITV,
 * assegurança i impost de circulació.
 *
 * Els quilòmetres hi són perquè al taller els apunten, i cada lectura del comptador
 * ajuda a saber què s'ha recorregut en un any; però hi ha despeses que no en porten
 * —l'assegurança i l'impost no passen pel taller— i per això són opcionals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('g_vehicles_motor_despeses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('g_vehicles')->cascadeOnDelete();
            $table->date('data');
            $table->string('tipus', 30);
            $table->decimal('import', 10, 2);
            // Només quan la despesa passa pel taller
            $table->unsignedInteger('km_totals')->nullable();
            $table->string('taller', 100)->nullable();
            $table->text('motiu')->nullable();
            $table->foreignId('moviment_id')->nullable()->constrained('g_moviments_comptes_corrents')->nullOnDelete();
            $table->timestamps();

            $table->index(['vehicle_id', 'data']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g_vehicles_motor_despeses');
    }
};
