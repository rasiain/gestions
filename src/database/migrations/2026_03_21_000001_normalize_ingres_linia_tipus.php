<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $mapping = [
            'reparacio'     => 'reparacions',
            'reparació'     => 'reparacions',
            'compra'        => 'compres',
            'certificacio'  => 'altres',
            'certificació'  => 'altres',
            'servei'        => 'altres',
        ];

        foreach ($mapping as $old => $new) {
            DB::table('g_moviment_lloguer_ingres_linia')
                ->where('tipus', $old)
                ->update(['tipus' => $new]);
        }

        // Any remaining non-standard values → 'altres'
        $valid = ['comunitat', 'taxes', 'assegurança', 'compres', 'reparacions', 'gestoria', 'altres'];
        DB::table('g_moviment_lloguer_ingres_linia')
            ->whereNotIn('tipus', $valid)
            ->update(['tipus' => 'altres']);
    }

    public function down(): void
    {
        // No reversible: original values are unknown
    }
};
