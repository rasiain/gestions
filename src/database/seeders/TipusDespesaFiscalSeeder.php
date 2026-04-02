<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TipusDespesaFiscalSeeder extends Seeder
{
    public function run(): void
    {
        $tipus = [
            ['codi' => '623', 'descripcio' => 'Serveis de professionals independents'],
            ['codi' => '625', 'descripcio' => "Primes d'assegurances"],
            ['codi' => '626', 'descripcio' => 'Serveis bancaris i similars'],
            ['codi' => '631', 'descripcio' => 'Altres tributs'],
        ];

        foreach ($tipus as $item) {
            DB::table('g_tipus_despesa_fiscal')->updateOrInsert(
                ['codi' => $item['codi']],
                array_merge($item, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}
