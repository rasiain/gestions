<?php

use App\Services\TaxesService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La població de `g_immobles` s'escrivia tal com venia de l'adreça cadastral, en
 * majúscules: l'ÀNGEL GUIMERÀ 23 2 tenia "SALT" i els seus veïns "Salt" (vingut
 * del node de l'arbre, que sí que es canonitzava). A la vista de taxes això feia
 * dues capçaleres de municipi per al mateix Salt.
 *
 * `TaxesService` ja canonitza qualsevol de les tres fonts de població, així que
 * l'agrupació queda arreglada pel codi. Això només posa la dada en la mateixa
 * forma perquè la fitxa de l'immoble també es llegeixi bé.
 */
return new class extends Migration
{
    /** ÀNGEL GUIMERÀ 19 (Salt): no tenia població i la vista l'havia de deduir de l'arbre. */
    private const REFERENCIA_AG19 = '3171908DG8437A0001XM';

    public function up(): void
    {
        foreach (DB::table('g_immobles')->whereNotNull('poblacio')->get(['id', 'poblacio']) as $immoble) {
            $canonic = TaxesService::canonitzaPoblacio($immoble->poblacio);

            if ($canonic !== null && $canonic !== $immoble->poblacio) {
                DB::table('g_immobles')->where('id', $immoble->id)->update(['poblacio' => $canonic]);
            }
        }

        DB::table('g_immobles')
            ->where('referencia_cadastral', self::REFERENCIA_AG19)
            ->whereRaw("COALESCE(poblacio, '') = ''")
            ->update(['poblacio' => 'Salt']);
    }

    public function down(): void
    {
        // La canonització no es desfà: no hi ha res a recuperar d'una majúscula.
        DB::table('g_immobles')
            ->where('referencia_cadastral', self::REFERENCIA_AG19)
            ->where('poblacio', 'Salt')
            ->update(['poblacio' => null]);
    }
};
