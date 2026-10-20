<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dues dades del vehicle que no surten de cap altre lloc.
     *
     * - `modalitat`, només de les bicis: BTT, carretera, urbana, gravel o rodets. Separa
     *   els rodets —quilòmetres virtuals— de les sortides de debò, i agrupa les anàlisis.
     * - `motiu_baixa`: per què ja no compta. L'estat no es desa —un vehicle amb data de
     *   baixa és de baixa—, però «retirada» no és «venuda», i la data sola no ho diu.
     */
    public function up(): void
    {
        Schema::table('g_vehicles', function (Blueprint $table) {
            $table->string('modalitat', 20)->nullable()->after('combustible');
            $table->string('motiu_baixa', 20)->nullable()->after('data_baixa');
        });

        // Les bicis que hi ha, importades de Garmin el 2026-10-20
        foreach ([
            'Giant Anthem 2'     => 'btt',
            'Lapierre Zesty 214' => 'btt',
            "Albert's bike"      => 'urbana',
            'BH 1992'            => 'carretera',
            'Salter B-20'        => 'rodets',
        ] as $nom => $modalitat) {
            DB::table('g_vehicles')->where('tipus', 'bici')->where('nom', $nom)->update(['modalitat' => $modalitat]);
        }
    }

    public function down(): void
    {
        Schema::table('g_vehicles', function (Blueprint $table) {
            $table->dropColumn(['modalitat', 'motiu_baixa']);
        });
    }
};
