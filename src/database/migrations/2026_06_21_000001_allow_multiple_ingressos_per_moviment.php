<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('g_moviment_lloguer_ingres', function (Blueprint $table) {
            // Eliminar l'índex únic sobre moviment_id
            $table->dropUnique(['moviment_id']);

            // Crear índex únic compost (moviment_id, lloguer_id)
            $table->unique(['moviment_id', 'lloguer_id']);
        });
    }

    public function down(): void
    {
        Schema::table('g_moviment_lloguer_ingres', function (Blueprint $table) {
            // Revertir: eliminar índex compost i tornar a posar UNIQUE sobre moviment_id
            $table->dropUnique(['moviment_id', 'lloguer_id']);
            $table->unique('moviment_id');
        });
    }
};
