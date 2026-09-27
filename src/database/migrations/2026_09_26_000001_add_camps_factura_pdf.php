<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('g_comunitats_bens', function (Blueprint $table) {
            $table->string('telefon', 30)->nullable()->after('adreca');
        });

        Schema::table('g_lloguers', function (Blueprint $table) {
            // El concepte tal com surt a la factura: no és el nom del lloguer, que és
            // intern i va en majúscules.
            $table->string('concepte_factura', 200)->nullable()->after('irpf_percentatge');
            // El bloc del peu, tal qual, amb l'IBAN ja escrit: a cada local és diferent.
            $table->text('condicions_pagament')->nullable()->after('concepte_factura');
            // Amb {any} a dins: hi ha una carpeta per any i per immoble.
            $table->string('ruta_factures', 500)->nullable()->after('ruta_export');
            $table->string('patro_nom_fitxer', 200)->nullable()->after('ruta_factures');
        });

        // Els valors dels dos locals que ja facturen, llegits de les factures de 2025.
        DB::table('g_comunitats_bens')->whereIn('id', [1, 2])->update(['telefon' => '639 98 00 24']);

        DB::table('g_lloguers')->where('id', 3)->update([
            'concepte_factura'    => 'Lloguer Local Joan Maragall, 33 Baixos, GIRONA',
            'condicions_pagament' => "Transferència bancària a:\tHEREUS PUIGVERT COMALADA CB\n\tIBAN: ES98 2100 1129 7102 0017 5402\n\n\tCaixaBank - \"La Caixa\"\n\tJoan Maragall, 34\n\t17002 - GIRONA",
            'ruta_factures'       => '/Users/ricardasiain/Documents/data_extra/Casa/Comptabilitat/Immobles/Girona - Joan Maragall 33 Baixos/{any}',
            'patro_nom_fitxer'    => 'Factura Hereus Puigvert Comalada CB {numero}{sufix}',
        ]);

        DB::table('g_lloguers')->where('id', 4)->update([
            'concepte_factura'    => 'T036 - Lloguer local carrer Juli Garreta, 32, GIRONA',
            'condicions_pagament' => "Càrrec en compte a Ecoveritas S.A. el dia 5 de cada mes.\n\tIBAN: ES55 2100 0849 5602 **** ****",
            'ruta_factures'       => '/Users/ricardasiain/Documents/data_extra/Casa/Comptabilitat/Immobles/Girona - Juli Garreta 32/{any}',
            'patro_nom_fitxer'    => 'T036 Factura Girona Juli Garreta 32 CB {numero}{sufix}',
        ]);
    }

    public function down(): void
    {
        Schema::table('g_comunitats_bens', function (Blueprint $table) {
            $table->dropColumn('telefon');
        });

        Schema::table('g_lloguers', function (Blueprint $table) {
            $table->dropColumn(['concepte_factura', 'condicions_pagament', 'ruta_factures', 'patro_nom_fitxer']);
        });
    }
};
