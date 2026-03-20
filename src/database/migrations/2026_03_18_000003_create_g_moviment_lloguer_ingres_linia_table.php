<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('g_moviment_lloguer_ingres_linia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingres_id')->constrained('g_moviment_lloguer_ingres')->cascadeOnDelete();
            $table->string('tipus', 20); // reparacio|compra|certificacio|servei|altres
            $table->string('descripcio', 200)->nullable();
            $table->decimal('import', 10, 2);
            $table->foreignId('proveidor_id')->nullable()->constrained('g_proveidors')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g_moviment_lloguer_ingres_linia');
    }
};
