<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `g_comptes_corrents.tipus` deia `fons_inversio` a tots els comptes
 * d'inversió, plans de pensions inclosos.
 *
 * No era només una etiqueta lletja: com que el tipus no els distingia, la
 * pantalla de Fons i la de Plans de Pensions oferien la mateixa llista de
 * comptes en crear un contracte, i res no impedia penjar un contracte de
 * pensions d'un compte de fons.
 *
 * El senyal per separar-los ja hi és: de quina taula de contractes penja cada
 * compte. Els que encara no en tenen cap es queden com estaven, que és el
 * valor per defecte del formulari.
 */
return new class extends Migration
{
    public function up(): void
    {
        $pensions = DB::table('g_pp_contractes')->pluck('compte_corrent_id')->unique();
        $fons     = DB::table('g_fi_contractes')->pluck('compte_corrent_id')->unique();

        // Un compte amb contractes de tots dos tipus no es pot resoldre sol:
        // avui no n'hi ha cap, i si n'apareix un es queda com estava.
        $nomesPensions = $pensions->diff($fons);

        if ($nomesPensions->isEmpty()) {
            return;
        }

        DB::table('g_comptes_corrents')
            ->where('tipus', 'fons_inversio')
            ->whereIn('id', $nomesPensions)
            ->update(['tipus' => 'pla_pensions', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('g_comptes_corrents')
            ->where('tipus', 'pla_pensions')
            ->update(['tipus' => 'fons_inversio', 'updated_at' => now()]);
    }
};
