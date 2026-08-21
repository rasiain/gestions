<?php

namespace Tests\Unit;

use App\Models\AssegurancaPatro;
use App\Models\AssegurancaPolissa;
use App\Models\Categoria;
use App\Services\AssegurancesService;
use Tests\TestCase;

class AssegurancesServiceTest extends TestCase
{
    private AssegurancesService $servei;

    protected function setUp(): void
    {
        parent::setUp();

        $this->servei = new AssegurancesService();
    }

    /**
     * Els patrons per defecte de la migració, sense passar per la base de dades.
     *
     * @return \Illuminate\Support\Collection<int, AssegurancaPatro>
     */
    private function patrons(): \Illuminate\Support\Collection
    {
        return collect([
            ['Comunitat', 'ASSEGURANÇA COMUNITAT'],
            ['Comunitat', 'COMUNITAT ASSEGURANÇA'],
            ['Decessos', 'ASSEGURANÇA DECESOS'],
            ['Vehicle', 'ASSEGURANÇA COTXE'],
            ['Vehicle', 'ASSEGURANÇA MOTO'],
            ['Assegurança', 'ASSEGURAN'],
            ['Assegurança', 'SEGURCAIXA'],
        ])->map(fn (array $p) => new AssegurancaPatro(['etiqueta' => $p[0], 'patro' => $p[1]]));
    }

    /**
     * Cada categoria té l'id de la seva posició a la cadena, per poder-hi
     * enganxar ajustos.
     *
     * @param  array<int, string>  $noms
     * @return array<int, Categoria>
     */
    private function cadena(array $noms): array
    {
        return array_map(function (string $nom, int $i) {
            $categoria = new Categoria(['nom' => $nom]);
            $categoria->id = $i;

            return $categoria;
        }, $noms, array_keys($noms));
    }

    /**
     * @param  array<int, array<string, mixed>>  $ajustos  per posició a la cadena
     * @return array<int, AssegurancaPolissa>
     */
    private function ajustos(array $ajustos): array
    {
        return array_map(fn (array $camps) => new AssegurancaPolissa($camps), $ajustos);
    }

    /**
     * @param  array<int, string>  $noms
     * @param  array<int, array<string, mixed>>  $ajustos  per posició a la cadena
     * @return array<string, mixed>|null
     */
    private function resol(array $noms, array $ajustos = []): ?array
    {
        return $this->servei->resolCadena($this->cadena($noms), $this->patrons(), $this->ajustos($ajustos));
    }

    public function test_el_patro_ha_de_comencar_una_paraula(): void
    {
        $norm = fn (string $t) => AssegurancesService::normalitza($t);

        $this->assertTrue(AssegurancesService::coincideix($norm('ASSEGURANÇA'), $norm('ASSEGURAN')));
        $this->assertTrue(AssegurancesService::coincideix($norm('ASSEGURANCES'), $norm('ASSEGURAN')));
        $this->assertTrue(AssegurancesService::coincideix($norm('COMUNITAT ASSEGURANÇA ST. ANTONI'), $norm('ASSEGURAN')));

        // Enmig d'una paraula no compta: si no, "CAN MASSEGUR" (complements de
        // casa) i "L'ENSEGUR" (un restaurant) serien pòlisses.
        $this->assertFalse(AssegurancesService::coincideix($norm('CAN MASSEGUR'), $norm('ASSEGUR')));
        $this->assertFalse(AssegurancesService::coincideix($norm("L'ENSEGUR"), $norm('SEGUR')));
        $this->assertFalse(AssegurancesService::coincideix($norm('QUALSEVOL COSA'), $norm('')));
    }

    public function test_troba_la_polissa_encara_que_la_fulla_sigui_la_companyia(): void
    {
        $polissa = $this->resol([
            'DESPESES', 'IMMOBLES', 'GIRONA', 'BONASTRUCH DE PORTA 35', 'ASSEGURANÇA', 'SEGURCAIXA',
        ]);

        $this->assertNotNull($polissa);
        $this->assertSame('Assegurança', $polissa['tipus']);
        $this->assertSame('BONASTRUCH DE PORTA 35', $polissa['immoble']);
        $this->assertSame('Girona', $polissa['poblacio']);
        $this->assertSame('SEGURCAIXA', $polissa['companyia']);
    }

    public function test_resol_limmoble_sota_despeses_propietats(): void
    {
        $polissa = $this->resol([
            'DESPESES', 'DESPESES PROPIETATS', 'RUTLLA 11 2ON 2A', 'ASSEGURANÇA RUTLLA',
        ]);

        $this->assertSame('RUTLLA 11 2ON 2A', $polissa['immoble']);
        $this->assertSame('RUTLLA 11 2ON 2A', $polissa['objecte']);
        $this->assertNull($polissa['companyia']);
    }

    public function test_la_comunitat_es_del_mateix_immoble_pero_es_una_polissa_a_part(): void
    {
        $polissa = $this->resol([
            'DESPESES', 'IMMOBLES', 'SALT', 'ÀNGEL GUIMERÀ 23 2', 'ALTRES', 'ASSEGURANÇA COMUNITAT', 'CATALANA OCCIDENT',
        ]);

        $this->assertSame('ÀNGEL GUIMERÀ 23 2', $polissa['immoble']);
        $this->assertSame('Comunitat', $polissa['tipus']);
    }

    public function test_sense_immoble_el_grup_es_el_pare_del_node(): void
    {
        $moto = $this->resol(['DESPESES', 'MOTOR', 'MOTO', 'ASSEGURANÇA MOTO']);

        $this->assertNull($moto['immoble']);
        $this->assertSame('MOTO', $moto['objecte']);
        $this->assertSame('Vehicle', $moto['tipus']);
    }

    public function test_si_el_pare_es_generic_el_grup_es_el_node_mateix(): void
    {
        $decesos = $this->resol(['DESPESES', 'SERVEIS', 'ASSEGURANÇA DECESOS', 'SANTA LUCIA']);

        $this->assertSame('ASSEGURANÇA DECESOS', $decesos['objecte']);
        $this->assertSame('Decessos', $decesos['tipus']);
        $this->assertSame('SANTA LUCIA', $decesos['companyia']);
    }

    public function test_una_categoria_que_no_es_polissa_no_es_detecta(): void
    {
        $this->assertNull($this->resol(['DESPESES', 'COMPRES', 'COMPLEMENTS CASA', 'CAN MASSEGUR']));
        $this->assertNull($this->resol(['DESPESES', 'SERVEIS', 'SUYAPA', 'TRESORERIA SEGURETAT SOCIAL']));
    }

    public function test_la_companyia_no_pot_fer_dimmoble(): void
    {
        // Sense l'immoble a sobre del node, el que hi ha a sota (la companyia)
        // no l'ha de suplantar mai.
        $polissa = $this->resol(['DESPESES', 'IMMOBLES', 'GIRONA', 'ASSEGURANÇA', 'AXA']);

        $this->assertNull($polissa['immoble']);
        $this->assertSame('Girona', $polissa['poblacio']);
    }

    public function test_lajust_manual_mana_sobre_el_nom_de_larbre(): void
    {
        $noms = ['DESPESES', 'FILLS', 'LAIA', 'DESPESES BARCELONA', 'PIS', 'ASSEGURANÇA PIS', 'BILBAO'];

        // Sense ajust, el grup es diu "PIS" i no diu de quin pis és
        $this->assertSame('PIS', $this->resol($noms)['objecte']);

        $polissa = $this->resol($noms, [5 => ['objecte' => 'PIS LAIA', 'poblacio' => 'Barcelona']]);

        $this->assertSame('PIS LAIA', $polissa['objecte']);
        $this->assertSame('PIS LAIA', $polissa['objecte_ajust']);
        $this->assertSame('Barcelona', $polissa['poblacio_ajust']);
    }

    public function test_lajust_del_node_i_el_de_la_fulla_es_combinen(): void
    {
        // El municipi es desa al node de la pòlissa i la companyia unificada a
        // la fulla: la categoria del moviment és la fulla, i han de valer tots dos.
        $polissa = $this->resol(
            ['DESPESES', 'DESPESES PROPIETATS', 'ST.ANTONI MN 16, BAIXOS', 'ASSEGURANÇA', 'CATALANA OCCIDENTE'],
            [
                3 => ['poblacio' => 'Sant Antoni de Calonge'],
                4 => ['companyia' => 'OCCIDENT'],
            ]
        );

        $this->assertSame('Sant Antoni de Calonge', $polissa['poblacio_ajust']);
        $this->assertSame('OCCIDENT', $polissa['companyia']);
        $this->assertSame('ST.ANTONI MN 16, BAIXOS', $polissa['immoble']);
    }

    public function test_inclou_una_polissa_que_cap_patro_no_enganxa(): void
    {
        $noms = ['DESPESES', 'SERVEIS', 'MUTUALITAT DELS ENGINYERS', 'SERPRECO'];

        $this->assertNull($this->resol($noms));

        $polissa = $this->resol($noms, [2 => ['inclou' => true, 'tipus' => 'Mutualitat']]);

        $this->assertNotNull($polissa);
        $this->assertSame('Mutualitat', $polissa['tipus']);
        $this->assertSame('MUTUALITAT DELS ENGINYERS', $polissa['objecte']);
        $this->assertSame('SERPRECO', $polissa['companyia']);
    }

    public function test_ocult_marca_el_que_no_es_cap_polissa(): void
    {
        $noms = ['DESPESES', 'IMMOBLES', 'GIRONA', 'FRANCESC CIURANA 6', 'ASSEGURANÇA'];

        $this->assertFalse($this->resol($noms)['ocult']);
        $this->assertTrue($this->resol($noms, [4 => ['ocult' => true]])['ocult']);
    }
}
