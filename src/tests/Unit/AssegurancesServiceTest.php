<?php

namespace Tests\Unit;

use App\Models\AssegurancaPatro;
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
     * @param  array<int, string>  $noms
     * @return array<int, Categoria>
     */
    private function cadena(array $noms): array
    {
        return array_map(fn (string $nom) => new Categoria(['nom' => $nom]), $noms);
    }

    /**
     * @param  array<int, string>  $noms
     * @return array<string, mixed>|null
     */
    private function resol(array $noms): ?array
    {
        return $this->servei->resolCadena($this->cadena($noms), $this->patrons());
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
}
