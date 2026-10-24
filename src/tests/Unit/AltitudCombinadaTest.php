<?php

namespace Tests\Unit;

use App\Support\AltitudCombinada;
use App\Support\Desnivell;
use PHPUnit\Framework\TestCase;

/** La combinació del model del terreny amb l'altímetre, sobre perfils fets a mà: un punt cada 5 m. */
class AltitudCombinadaTest extends TestCase
{
    /** Un turonet suau de 30 m en 2 km, sobre 100 m. */
    private function terreny(float $x): float
    {
        return 100 + 30 * sin(M_PI * $x / 2000);
    }

    /** @return array<int, array{float, float, float}> */
    private function punts(callable $dispositiu, ?callable $terreny = null, int $n = 400): array
    {
        $terreny ??= fn ($x) => $this->terreny($x);
        $punts = [];
        for ($i = 0; $i < $n; $i++) {
            $x = $i * 5.0;
            $punts[] = [$x, $dispositiu($x, $i), $terreny($x)];
        }

        return $punts;
    }

    public function test_un_altimetre_desplacat_queda_al_nivell_del_model(): void
    {
        $c = AltitudCombinada::calcula($this->punts(fn ($x) => $this->terreny($x) - 19));

        foreach ([0, 100, 200, 399] as $i) {
            $this->assertEqualsWithDelta($this->terreny($i * 5), $c[$i], 0.1);
        }
    }

    public function test_la_deriva_despres_d_una_aturada_es_corregeix_de_cop(): void
    {
        // Aturada al punt 200: deu punts a la mateixa distància, i en reprendre el baròmetre marca 12 m menys
        $punts = [];
        foreach ($this->punts(fn ($x, $i) => $this->terreny($x) - ($i >= 200 ? 12 : 0)) as $i => $p) {
            $punts[] = $p;
            if ($i === 199) {
                for ($k = 0; $k < 10; $k++) {
                    $punts[] = [$p[0], $p[1] - 12 * $k / 9, $p[2]];
                }
            }
        }
        $c = AltitudCombinada::calcula($punts);

        // Ni el salt ni el descens durant l'aturada: el perfil és el del turó
        $this->assertEqualsWithDelta(Desnivell::calcula(array_map(fn ($p) => $this->terreny($p[0]), $punts))['baixada'],
            Desnivell::calcula($c)['baixada'], 0.5);
        foreach (array_slice($c, 200, 10) as $alt) {
            $this->assertSame($c[200], $alt, 'a l\'aturada l\'altitud no canvia');
        }
    }

    public function test_en_un_pont_mana_l_altimetre(): void
    {
        // Un pont de 60 m: el model hi posa el fons de la vall, 20 m més avall
        $pont = fn ($x) => $x >= 1000 && $x < 1060;
        $c = AltitudCombinada::calcula($this->punts(
            fn ($x) => $this->terreny($x),
            fn ($x) => $this->terreny($x) - ($pont($x) ? 20 : 0),
        ));

        $this->assertEqualsWithDelta($this->terreny(1030), $c[206], 0.1);
    }

    public function test_un_altimetre_sorollos_cedeix_al_model(): void
    {
        // Salts de ±4 m d'un segon a l'altre, com un baròmetre degradat
        $punts = $this->punts(fn ($x, $i) => $this->terreny($x) + ($i % 2 ? 4 : -4));
        $c = AltitudCombinada::calcula($punts);

        // El dispositiu sol en sumaria centenars de metres; el combinat, el mateix que el model
        $this->assertGreaterThan(500, Desnivell::calcula(array_column($punts, 1))['pujada']);
        $this->assertEqualsWithDelta(Desnivell::calcula(array_column($punts, 2))['pujada'], Desnivell::calcula($c)['pujada'], 1);
    }

    public function test_les_punxes_del_model_no_compten_com_a_pujades(): void
    {
        // Un pla a 72 m amb sots de 5 m d'un sol punt cada 100 m: el GPS que cau a la llera del riu
        $punts = $this->punts(fn ($x) => 72.0, fn ($x, $i = 0) => fmod($x, 100) === 50.0 ? 67.0 : 72.0);

        $this->assertGreaterThan(50, Desnivell::calcula(array_column($punts, 2))['pujada'], 'el model tal com és');
        $this->assertSame(0.0, Desnivell::calcula(AltitudCombinada::terreny($punts))['pujada']);
        $this->assertSame(0.0, Desnivell::calcula(AltitudCombinada::calcula($punts))['pujada']);
    }

    public function test_si_el_dispositiu_infla_tota_la_sortida_mana_el_model(): void
    {
        // Un altímetre net de punt a punt però amb salts de 10 m cada 250 m, que tornen al cap de 50 m
        $punts = $this->punts(fn ($x) => $this->terreny($x) + (fmod($x, 250) < 50 ? 10 : 0));
        $c = AltitudCombinada::calcula($punts);

        $this->assertSame(AltitudCombinada::terreny($punts), $c);
    }

    public function test_els_punts_sense_terreny_queden_sense_altitud(): void
    {
        $punts = $this->punts(fn ($x) => $this->terreny($x), null, 10);
        $punts[0][2] = null;
        $c = AltitudCombinada::calcula($punts);

        $this->assertNull($c[0]);
        $this->assertNotNull($c[1]);
    }
}
