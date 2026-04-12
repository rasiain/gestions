<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Marcar com a conciliat tots els moviments que ja estan classificats als lloguers
     * o vinculats a una factura. S'aplica retroactivament als moviments existents.
     */
    public function up(): void
    {
        DB::statement("
            UPDATE g_moviments_comptes_corrents
            SET conciliat = 1
            WHERE id IN (
                SELECT moviment_id FROM g_moviment_lloguer_despesa
                UNION
                SELECT moviment_id FROM g_moviment_lloguer_ingres
                UNION
                SELECT moviment_id FROM g_factures
                WHERE moviment_id IS NOT NULL
            )
        ");
    }

    public function down(): void
    {
        // No revertim: impossible distingir els marcats manualment dels retroactius
    }
};
