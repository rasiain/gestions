<?php

namespace Tests\Unit;

use App\Support\RepartimentPerTitular;
use PHPUnit\Framework\TestCase;

class RepartimentPerTitularTest extends TestCase
{
    public function test_a_parts_iguals_l_ultim_s_endu_el_residu(): void
    {
        $this->assertSame([33.33, 33.33, 33.34], RepartimentPerTitular::parts(100, [1, 1, 1]));
        $this->assertSame([-33.33, -33.33, -33.34], RepartimentPerTitular::parts(-100, [1, 1, 1]));
        $this->assertSame([], RepartimentPerTitular::parts(100, []));
    }

    public function test_amb_pesos_les_quotes_d_un_immoble(): void
    {
        // Dos terços i un terç, com Hereus Puigvert Comalada
        $this->assertSame(['a' => 666.67, 'b' => 333.33], RepartimentPerTitular::parts(1000, ['a' => 2, 'b' => 1]));
    }

    public function test_els_totals_sumen_el_valor_i_no_perden_cap_posicio_sense_titular(): void
    {
        $marta = ['id' => 1, 'nom' => 'Marta'];
        $ricard = ['id' => 2, 'nom' => 'Ricard'];

        $totals = RepartimentPerTitular::totals([
            ['valor' => 100, 'titulars' => [$marta, $ricard], 'detall' => ['compte' => 'Comú']],
            ['valor' => 10.01, 'titulars' => [$marta], 'detall' => ['compte' => 'Seu']],
            ['valor' => 5, 'titulars' => [], 'detall' => ['compte' => 'Orfe']],
        ], 'comptes');

        $this->assertSame(['Marta', 'Ricard', RepartimentPerTitular::SENSE_TITULAR], array_column($totals, 'nom'));
        $this->assertSame([60.01, 50.0, 5.0], array_column($totals, 'total'));
        $this->assertSame(115.01, round(array_sum(array_column($totals, 'total')), 2));
        $this->assertSame(['compte' => 'Comú', 'titulars' => 2, 'part' => 50.0], $totals[0]['comptes'][0]);
    }
}
