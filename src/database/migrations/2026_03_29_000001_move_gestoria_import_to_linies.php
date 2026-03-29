<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Moure gestoria_import a línies
        $ingressos = DB::table('g_moviment_lloguer_ingres')
            ->whereNotNull('gestoria_import')
            ->where('gestoria_import', '>', 0)
            ->get();

        foreach ($ingressos as $ingres) {
            $proveidorId = DB::table('g_lloguers')
                ->where('id', $ingres->lloguer_id)
                ->value('proveidor_gestoria_id');

            DB::table('g_moviment_lloguer_ingres_linia')->insert([
                'ingres_id'    => $ingres->id,
                'tipus'        => 'gestoria',
                'descripcio'   => 'Comissió gestoria',
                'import'       => $ingres->gestoria_import,
                'proveidor_id' => $proveidorId,
                'created_at'   => $ingres->created_at,
                'updated_at'   => $ingres->updated_at,
            ]);
        }

        Schema::table('g_moviment_lloguer_ingres', function (Blueprint $table) {
            $table->dropColumn('gestoria_import');
        });
    }

    public function down(): void
    {
        Schema::table('g_moviment_lloguer_ingres', function (Blueprint $table) {
            $table->decimal('gestoria_import', 10, 2)->nullable()->after('base_lloguer');
        });

        // Restaurar gestoria_import des de les línies
        $linies = DB::table('g_moviment_lloguer_ingres_linia')
            ->where('tipus', 'gestoria')
            ->get();

        foreach ($linies as $linia) {
            DB::table('g_moviment_lloguer_ingres')
                ->where('id', $linia->ingres_id)
                ->update(['gestoria_import' => $linia->import]);
        }

        DB::table('g_moviment_lloguer_ingres_linia')
            ->where('tipus', 'gestoria')
            ->delete();
    }
};
