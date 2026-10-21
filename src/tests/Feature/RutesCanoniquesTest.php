<?php

namespace Tests\Feature;

use App\Services\RutesCanoniquesService;
use Tests\TestCase;

/**
 * Les rutes canòniques sobre recorreguts inventats: rectes i trams a prop de Girona, en
 * semicercles com a la BD de recorreguts.
 */
class RutesCanoniquesTest extends TestCase
{
    private RutesCanoniquesService $servei;

    protected function setUp(): void
    {
        parent::setUp();
        $this->servei = new RutesCanoniquesService();
    }

    /**
     * Un recorregut que passa pels punts donats (graus), amb un punt cada ~10 m i un per segon.
     *
     * @param  array<int, array{float, float}>  $fites
     */
    private function recorregut(array $fites, float $desplacamentM = 0.0): array
    {
        $punts = [];
        $t = 0;
        $semi = fn (float $g) => (int) round($g * 2 ** 31 / 180);
        for ($i = 0; $i < count($fites) - 1; $i++) {
            [$la0, $lo0] = $fites[$i];
            [$la1, $lo1] = $fites[$i + 1];
            $metres = hypot(($la1 - $la0) * 111320, ($lo1 - $lo0) * 111320 * cos(deg2rad($la0)));
            $passos = max(1, (int) ($metres / 10));
            for ($k = 0; $k < $passos; $k++) {
                $f = $k / $passos;
                $lat = $la0 + ($la1 - $la0) * $f + $desplacamentM / 111320;
                $punts[] = (object) ['t' => $t++, 'lat' => $semi($lat), 'lon' => $semi($lo0 + ($lo1 - $lo0) * $f)];
            }
        }
        $punts[] = (object) ['t' => $t, 'lat' => $semi(end($fites)[0]), 'lon' => $semi(end($fites)[1])];

        return $punts;
    }

    // Uns 5 km cap a l'est i uns 5 km cap al nord
    private const A = [41.98, 2.82];
    private const B = [41.98, 2.88];
    private const C = [42.025, 2.88];

    public function test_la_mateixa_ruta_gravada_dues_vegades_s_assembla_del_tot(): void
    {
        $u = $this->servei->empremta($this->recorregut([self::A, self::B, self::C]));
        // L'altra gravació, desplaçada 15 m pel GPS
        $d = $this->servei->empremta($this->recorregut([self::A, self::B, self::C], 15));

        $this->assertGreaterThan(0.97, $this->servei->semblanca($u, $d));
    }

    public function test_el_sentit_no_importa(): void
    {
        $anada = $this->servei->empremta($this->recorregut([self::A, self::B, self::C]));
        $tornada = $this->servei->empremta($this->recorregut([self::C, self::B, self::A]));

        $this->assertGreaterThan(0.97, $this->servei->semblanca($anada, $tornada));
    }

    public function test_un_recorregut_que_en_conte_un_altre_es_compta_pel_costat_que_en_comparteix_menys(): void
    {
        $sencer = $this->servei->empremta($this->recorregut([self::A, self::B, self::C]));
        $meitat = $this->servei->empremta($this->recorregut([self::A, self::B]));

        // La meitat cau tota dins del sencer, però el sencer només en té la meitat dins
        $this->assertEqualsWithDelta(0.5, $this->servei->semblanca($sencer, $meitat), 0.05);
    }

    public function test_dos_camins_paral_lels_a_200_m_no_son_el_mateix(): void
    {
        $u = $this->servei->empremta($this->recorregut([self::A, self::B]));
        $altre = $this->servei->empremta($this->recorregut([self::A, self::B], 200));

        $this->assertLessThan(0.05, $this->servei->semblanca($u, $altre));
    }

    public function test_la_densitat_de_la_gravacio_no_canvia_l_empremta(): void
    {
        $cadaSegon = $this->recorregut([self::A, self::B, self::C]);
        // Un punt de cada 30, com les gravacions «intel·ligents» d'alguns dispositius
        $esparsa = array_values(array_filter($cadaSegon, fn ($p, $i) => $i % 30 === 0, ARRAY_FILTER_USE_BOTH));

        $densa = $this->servei->empremta($cadaSegon);
        $poca = $this->servei->empremta($esparsa);

        $this->assertGreaterThan(0.95, $this->servei->semblanca($densa, $poca));
        $this->assertGreaterThan($poca['qualitat'], $densa['qualitat']);
    }

    public function test_l_agrupament_no_encadena(): void
    {
        // 1 ≈ 2 i 2 ≈ 3, però 1 i 3 no s'assemblen prou
        $parelles = [[1, 2, 0.85], [2, 3, 0.84], [1, 3, 0.5]];
        $fusions = $this->servei->fusions([1, 2, 3], $parelles);

        $mateixa = $this->servei->grups([1, 2, 3], $fusions, 0.80);
        $this->assertCount(2, $mateixa);
        $this->assertContains([1, 2], $mateixa);

        // Amb més tolerància sí que van junts: tots tres s'assemblen almenys un 50 %
        $this->assertCount(1, $this->servei->grups([1, 2, 3], $fusions, 0.40));
    }

    public function test_els_nivells_queden_niats(): void
    {
        $parelles = [[1, 2, 0.95], [3, 4, 0.93], [1, 3, 0.7], [1, 4, 0.72], [2, 3, 0.69], [2, 4, 0.75], [5, 1, 0.45]];
        $ids = [1, 2, 3, 4, 5];
        $fusions = $this->servei->fusions($ids, $parelles);

        $this->assertCount(3, $this->servei->grups($ids, $fusions, 0.90));   // {1,2} {3,4} {5}
        $this->assertCount(2, $this->servei->grups($ids, $fusions, 0.65));   // {1,2,3,4} {5}
        // El 5 només s'assembla a l'1: amb el 2, el 3 i el 4 no s'ha mesurat res, i no hi entra
        $this->assertCount(2, $this->servei->grups($ids, $fusions, 0.40));
    }

    public function test_el_representant_es_el_mes_central_i_entre_iguals_el_de_millor_gravacio(): void
    {
        $semblanca = [
            1 => [2 => 0.80, 3 => 0.80],
            2 => [1 => 0.80, 3 => 0.95],
            3 => [1 => 0.80, 2 => 0.95],
        ];
        // El 2 i el 3 són igual de centrals; el 3 té millor gravació
        $this->assertSame(3, $this->servei->representant([1, 2, 3], $semblanca, [1 => 1.0, 2 => 0.2, 3 => 0.9]));
        // Si el més central és clarament un, mana la centralitat encara que la gravació sigui pitjor
        $semblanca[2][3] = $semblanca[3][2] = 0.85;
        $semblanca[1][2] = $semblanca[2][1] = 0.95;
        $this->assertSame(2, $this->servei->representant([1, 2, 3], $semblanca, [1 => 1.0, 2 => 0.1, 3 => 0.9]));
    }
}
