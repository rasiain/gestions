<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * L'assumpte i el cos del correu que acompanya la factura. Són plantilles del
     * lloguer, amb el mes i l'any a dins, que és l'únic que canvia cada mes. El
     * destinatari no hi és: surt de les dades del llogater.
     */
    public function up(): void
    {
        Schema::table('g_lloguers', function (Blueprint $table) {
            $table->string('assumpte_correu', 200)->nullable()->after('patro_nom_fitxer');
            $table->text('cos_correu')->nullable()->after('assumpte_correu');
            // Les factures d'escombraries que van a banda porten el seu text; si és
            // buit, fan servir el general.
            $table->string('assumpte_correu_escombraries', 200)->nullable()->after('cos_correu');
            $table->text('cos_correu_escombraries')->nullable()->after('assumpte_correu_escombraries');
        });

        DB::table('g_lloguers')->where('id', 3)->update([
            'assumpte_correu' => 'Factura {mes} {any} - Lloguer local Joan Maragall 33 de Girona',
        ]);

        DB::table('g_lloguers')->where('id', 4)->update([
            'assumpte_correu' => 'Factura {mes} {any} - Lloguer local Juli Garreta 32 de Girona',
        ]);
    }

    public function down(): void
    {
        Schema::table('g_lloguers', function (Blueprint $table) {
            $table->dropColumn([
                'assumpte_correu',
                'cos_correu',
                'assumpte_correu_escombraries',
                'cos_correu_escombraries',
            ]);
        });
    }
};
