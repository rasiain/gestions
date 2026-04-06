<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TipusDespesaFiscalSeeder extends Seeder
{
    public function run(): void
    {
        // Comptes del grup 6 del PGC rellevants per a despeses de lloguers (IRPF/capital immobiliari)
        $tipus = [
            ['codi' => '621', 'descripcio' => 'Arrendaments i cànons',                    'categoria_despesa' => null],
            ['codi' => '622', 'descripcio' => 'Reparacions i conservació',                 'categoria_despesa' => 'reparacions'],
            ['codi' => '623', 'descripcio' => 'Serveis de professionals independents',     'categoria_despesa' => 'gestoria'],
            ['codi' => '625', 'descripcio' => "Primes d'assegurances",                     'categoria_despesa' => 'assegurança'],
            ['codi' => '626', 'descripcio' => 'Serveis bancaris i similars',               'categoria_despesa' => 'comissions'],
            ['codi' => '628', 'descripcio' => 'Subministraments',                          'categoria_despesa' => null],
            ['codi' => '629', 'descripcio' => 'Altres serveis',                            'categoria_despesa' => 'altres'],
            ['codi' => '631', 'descripcio' => 'Altres tributs',                            'categoria_despesa' => 'taxes'],
            ['codi' => '662', 'descripcio' => 'Interessos de deutes',                      'categoria_despesa' => null],
            ['codi' => '681', 'descripcio' => "Amortització de l'immobilitzat material",   'categoria_despesa' => null],
            ['codi' => '682', 'descripcio' => 'Amortització de les inversions immobiliàries', 'categoria_despesa' => null],
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
