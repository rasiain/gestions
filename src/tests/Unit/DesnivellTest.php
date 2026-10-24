<?php

namespace Tests\Unit;

use App\Support\Desnivell;
use PHPUnit\Framework\TestCase;

/** El desnivell amb llindar, que ha de donar el mateix que `acumulat` de Pages/Bicis/Sortida.vue. */
class DesnivellTest extends TestCase
{
    public function test_el_soroll_per_sota_del_llindar_no_compta(): void
    {
        $this->assertSame(['pujada' => 0.0, 'baixada' => 0.0], Desnivell::calcula([100, 101, 99.5, 101.5, 100, 102, 100.5]));
    }

    public function test_una_pujada_i_una_baixada_netes(): void
    {
        $this->assertSame(['pujada' => 50.0, 'baixada' => 30.0], Desnivell::calcula([100, 110, 120, 130, 140, 150, 140, 130, 120]));
    }

    public function test_compta_des_del_punt_de_referencia_i_no_des_de_l_anterior(): void
    {
        // De 100 a 102 no arriba al llindar; a 103 sí, i compta els 3 m sencers des de 100
        $this->assertSame(3.0, Desnivell::calcula([100, 102, 103])['pujada']);
    }

    public function test_els_punts_sense_altitud_se_salten(): void
    {
        $this->assertSame(['pujada' => 20.0, 'baixada' => 0.0], Desnivell::calcula([null, 100, null, 110, 120, null]));
    }
}
