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
            ['codi' => '621', 'descripcio' => 'Arrendaments i cànons'],
            ['codi' => '622', 'descripcio' => 'Reparacions i conservació'],
            ['codi' => '623', 'descripcio' => 'Serveis de professionals independents'],
            ['codi' => '625', 'descripcio' => "Primes d'assegurances"],
            ['codi' => '626', 'descripcio' => 'Serveis bancaris i similars'],
            ['codi' => '628', 'descripcio' => 'Subministraments'],
            ['codi' => '629', 'descripcio' => 'Altres serveis'],
            ['codi' => '631', 'descripcio' => 'Altres tributs'],
            ['codi' => '662', 'descripcio' => 'Interessos de deutes'],
            ['codi' => '681', 'descripcio' => "Amortització de l'immobilitzat material"],
            ['codi' => '682', 'descripcio' => 'Amortització de les inversions immobiliàries'],
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
