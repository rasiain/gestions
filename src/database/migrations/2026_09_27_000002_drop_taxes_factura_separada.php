<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Qui decideix si les escombraries van en una factura a banda és la
     * configuració de l'any (`g_lloguers_escombraries.factura_separada`), perquè
     * pot canviar d'un any a l'altre. Aquest camp deia el mateix per sempre i no el
     * llegia ningú: amb dos llocs, el dia que no coincidissin no se sabria quin val.
     */
    public function up(): void
    {
        Schema::table('g_lloguers', function (Blueprint $table) {
            $table->dropColumn('taxes_factura_separada');
        });
    }

    public function down(): void
    {
        Schema::table('g_lloguers', function (Blueprint $table) {
            $table->boolean('taxes_factura_separada')->default(false)->after('irpf_percentatge');
        });
    }
};
