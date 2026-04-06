<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategoriaLloguerFiscalSeeder extends Seeder
{
    public function run(): void
    {
        $mapping = [
            'comunitat'   => null,
            'taxes'       => '631',
            'assegurança' => '625',
            'compres'     => null,
            'reparacions' => '622',
            'gestoria'    => '623',
            'comissions'  => '626',
            'altres'      => '629',
        ];

        foreach ($mapping as $categoria => $codi) {
            $tipusId = $codi
                ? DB::table('g_tipus_despesa_fiscal')->where('codi', $codi)->value('id')
                : null;

            DB::table('g_categoria_lloguer_fiscal')->updateOrInsert(
                ['categoria' => $categoria],
                [
                    'tipus_despesa_fiscal_id' => $tipusId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
