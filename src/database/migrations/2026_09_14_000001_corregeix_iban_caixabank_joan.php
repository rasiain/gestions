<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * L'IBAN de CAIXABANK JOAN estava desat amb quatre xifres de menys
 * (ES402100112979016930), i per això la importació no reconeixia mai el
 * fitxer: ni l'IBAN de la capçalera ni el número del nom del fitxer
 * (Moviments_compte_0586930.xls) hi coincidien. El correcte surt de la
 * capçalera de l'extracte: «ES40 2100 1129 7901 0058 6930».
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('g_comptes_corrents')
            ->where('compte_corrent', 'ES402100112979016930')
            ->update(['compte_corrent' => 'ES4021001129790100586930']);
    }

    public function down(): void
    {
        DB::table('g_comptes_corrents')
            ->where('compte_corrent', 'ES4021001129790100586930')
            ->update(['compte_corrent' => 'ES402100112979016930']);
    }
};
