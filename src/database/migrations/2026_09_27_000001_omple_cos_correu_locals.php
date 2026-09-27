<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * El cos del correu que acompanya la factura. Com l'assumpte, el mes i l'any
     * hi són variables: és l'únic que canvia cada mes.
     */
    public function up(): void
    {
        $cos = "Bon dia,\n\n"
            . "Us adjuntem la factura {numero}, corresponent al lloguer del mes de {mes} de {any}, "
            . "per un import de {total}.\n\n"
            . "Restem a la vostra disposició per a qualsevol aclariment.\n\n"
            . "Salutacions cordials.";

        $cosEscombraries = "Bon dia,\n\n"
            . "Us adjuntem la factura {numero}, corresponent a la fracció de les escombraries "
            . "industrials de {mes} de {any}, per un import de {total}.\n\n"
            . "Restem a la vostra disposició per a qualsevol aclariment.\n\n"
            . "Salutacions cordials.";

        DB::table('g_lloguers')->whereIn('id', [3, 4])->update(['cos_correu' => $cos]);

        // Només Juli Garreta les factura a banda; a Joan Maragall van dins de la
        // mensual i el text general ja serveix.
        DB::table('g_lloguers')->where('id', 4)->update([
            'assumpte_correu_escombraries' => 'Factura escombraries {mes} {any} - Lloguer local Juli Garreta 32 de Girona',
            'cos_correu_escombraries'      => $cosEscombraries,
        ]);
    }

    public function down(): void
    {
        DB::table('g_lloguers')->whereIn('id', [3, 4])->update([
            'cos_correu'                   => null,
            'assumpte_correu_escombraries' => null,
            'cos_correu_escombraries'      => null,
        ]);
    }
};
