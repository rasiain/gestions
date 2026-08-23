<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El que el model 184 necessita i el projecte encara no desava.
 *
 * Dues coses per comuner —la quota de titularitat i l'amortització anual— que van al
 * pivot d'immoble ↔ persona, que ja porta dates de vigència i per tant ja modela els
 * canvis de comuner.
 *
 * I la casella del 184 de cada despesa, que NO és una classificació nova: es dedueix de
 * la categoria que ja es posa, amb la mateixa taula que ja tradueix la categoria al
 * compte del PGC. Només es desa a la despesa quan cal fer una excepció.
 */
return new class extends Migration
{
    /**
     * Casella del 184 (columna de capital immobiliari) que proposa cada categoria.
     *
     *   1 Interessos i despeses de finançament      6 Quantitats meritades per tercers
     *   2 Conservació i reparació                   7 Primes d'assegurances
     *   4 Tributs i recàrrecs                      10 Altres despeses deduïbles
     *
     * @var array<string, int>
     */
    private const CASELLES = [
        'comissions'  => 1,
        'compres'     => 2,
        'reparacions' => 2,
        'taxes'       => 4,
        'gestoria'    => 6,
        'assegurança' => 7,
        'comunitat'   => 10,
        'altres'      => 10,
    ];

    public function up(): void
    {
        Schema::table('g_propietaris_immobles', function (Blueprint $table) {
            // Percentatge de titularitat: reparteix les retencions al 184
            $table->decimal('quota', 7, 4)->nullable()->after('persona_id');
            // La calcula l'usuari: depèn del valor i la data d'adquisició de cada quota,
            // que el projecte no desa. És fixa mentre no hi hagi canvis.
            $table->decimal('amortitzacio_anual', 12, 2)->nullable()->after('quota');
        });

        Schema::table('g_categoria_lloguer_fiscal', function (Blueprint $table) {
            $table->unsignedTinyInteger('casella_184')->nullable()->after('tipus_despesa_fiscal_id');
        });

        Schema::table('g_moviment_lloguer_despesa', function (Blueprint $table) {
            // Excepció: null = la casella que digui la categoria; 0 = fora de la declaració
            $table->unsignedTinyInteger('casella_184')->nullable()->after('tipus_despesa_fiscal_id');
        });

        // La fila pot no existir: el mapatge cap al PGC el va anar creant l'usuari des de
        // la configuració fiscal, i en una instal·lació nova la taula és buida. La casella
        // del 184, en canvi, la marca el formulari i no depèn de ningú.
        foreach (self::CASELLES as $categoria => $casella) {
            DB::table('g_categoria_lloguer_fiscal')->updateOrInsert(
                ['categoria' => $categoria],
                ['casella_184' => $casella, 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    public function down(): void
    {
        Schema::table('g_propietaris_immobles', function (Blueprint $table) {
            $table->dropColumn(['quota', 'amortitzacio_anual']);
        });

        Schema::table('g_categoria_lloguer_fiscal', function (Blueprint $table) {
            $table->dropColumn('casella_184');
        });

        Schema::table('g_moviment_lloguer_despesa', function (Blueprint $table) {
            $table->dropColumn('casella_184');
        });
    }
};
