<?php

namespace Tests\Feature;

use App\Models\MaterialEsportiu;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El material esportiu ve de l'exportació de Garmin tal com és: es reconeix pel seu
 * identificador a l'origen, i les bicis s'enllacen amb la del catàleg pel nom.
 */
class MaterialEsportiuTest extends TestCase
{
    use RefreshDatabase;

    private function gear(array $peces): string
    {
        $fitxer = tempnam(sys_get_temp_dir(), 'gear') . '_gear.json';
        file_put_contents($fitxer, json_encode([['gearDTOS' => $peces, 'gearActivityDTOs' => []]]));

        return $fitxer;
    }

    private const GIANT = [
        'gearPk' => 20040750, 'uuid' => '14dd', 'gearTypeName' => 'Bike', 'gearStatusName' => 'active',
        'displayName' => 'Giant Anthem 2', 'customMakeModel' => 'Giant Anthem 29 2 NX Eagle 2019',
        'dateBegin' => '2019-10-25',
    ];

    public function test_importa_el_material_i_enllaca_la_bici_pel_nom(): void
    {
        $giant = Vehicle::create(['nom' => 'Giant Anthem 2', 'tipus' => 'bici']);

        $this->artisan('material:importa', ['fitxer' => $this->gear([
            self::GIANT,
            ['gearPk' => 1, 'gearTypeName' => 'Other', 'gearStatusName' => 'retired', 'customMakeModel' => 'Coberta davant -&gt; darrera', 'dateBegin' => '2022-02-19', 'dateEnd' => '2025-07-31'],
        ])])->assertSuccessful();

        $bici = MaterialEsportiu::where('id_extern', '20040750')->first();
        $this->assertSame('garmin', $bici->origen);
        $this->assertSame('bici', $bici->tipus);
        $this->assertSame('Giant Anthem 2', $bici->nom);
        $this->assertSame('Giant Anthem 29 2 NX Eagle 2019', $bici->model);
        $this->assertSame($giant->id, $bici->vehicle_id);
        $this->assertSame('14dd', $bici->dades_originals['uuid']);

        // Sense «displayName» el nom és el model, i les entitats HTML de Garmin es desfan
        $coberta = MaterialEsportiu::where('id_extern', '1')->first();
        $this->assertSame('Coberta davant -> darrera', $coberta->nom);
        $this->assertSame('retirat', $coberta->estat);
        $this->assertSame('altres', $coberta->tipus);
        $this->assertNull($coberta->vehicle_id);
    }

    public function test_tornar_a_importar_actualitza_i_no_trepitja_ni_el_tipus_ni_l_enllac_corregits(): void
    {
        $giant = Vehicle::create(['nom' => 'Giant Anthem 2', 'tipus' => 'bici']);
        $altra = Vehicle::create(['nom' => 'La de la muntanya', 'tipus' => 'bici']);
        $fitxer = $this->gear([self::GIANT]);

        $this->artisan('material:importa', ['fitxer' => $fitxer])->assertSuccessful();
        MaterialEsportiu::first()->update(['vehicle_id' => $altra->id, 'tipus' => 'altres']);

        $this->artisan('material:importa', ['fitxer' => $this->gear([['gearStatusName' => 'retired', 'dateEnd' => '2026-01-01'] + self::GIANT])])
            ->assertSuccessful();

        $this->assertSame(1, MaterialEsportiu::count());
        $peca = MaterialEsportiu::first();
        $this->assertSame('retirat', $peca->estat);
        $this->assertSame($altra->id, $peca->vehicle_id);
        $this->assertSame('altres', $peca->tipus);
        $this->assertNotSame($giant->id, $peca->vehicle_id);
    }

    public function test_amb_prova_no_desa_res(): void
    {
        $this->artisan('material:importa', ['fitxer' => $this->gear([self::GIANT]), '--prova' => true])->assertSuccessful();

        $this->assertSame(0, MaterialEsportiu::count());
    }

    public function test_el_motiu_de_baixa_demana_data_de_baixa(): void
    {
        $this->actingAs(\App\Models\User::factory()->create())
            ->post(route('vehicles.store'), ['nom' => 'Bici', 'tipus' => 'bici', 'motiu_baixa' => 'venut'])
            ->assertSessionHasErrors('motiu_baixa');

        $this->post(route('vehicles.store'), ['nom' => 'Bici', 'tipus' => 'bici', 'modalitat' => 'btt', 'data_baixa' => '2026-01-01', 'motiu_baixa' => 'venut'])
            ->assertSessionHasNoErrors();

        $this->assertSame('venut', Vehicle::where('nom', 'Bici')->first()->motiu_baixa);
    }

    public function test_s_hi_poden_afegir_peces_propies_i_esborrar_les_que_no_tenen_sortides(): void
    {
        $giant = Vehicle::create(['nom' => 'Giant Anthem 2', 'tipus' => 'bici']);
        $this->actingAs(\App\Models\User::factory()->create())
            ->post(route('bicis.material.store'), [
                'nom' => 'Cadena KMC X12', 'tipus' => 'component', 'estat' => 'actiu',
                'data_inici' => '2026-10-01', 'muntat_a_vehicle_id' => $giant->id,
            ])
            ->assertSessionHasNoErrors();

        $peca = MaterialEsportiu::where('nom', 'Cadena KMC X12')->first();
        $this->assertSame('manual', $peca->origen);
        $this->assertNotEmpty($peca->id_extern);
        $this->assertSame($giant->id, $peca->muntat_a_vehicle_id);

        $this->delete(route('bicis.material.destroy', $peca))->assertSessionHasNoErrors();
        $this->assertNull($peca->fresh());
    }

    public function test_les_peces_de_garmin_no_s_esborren_i_corregides_la_importacio_no_les_toca(): void
    {
        $this->artisan('material:importa', ['fitxer' => $this->gear([
            ['gearPk' => 1, 'gearTypeName' => 'Other', 'gearStatusName' => 'active', 'customMakeModel' => 'Cadena20250510', 'dateBegin' => '2025-05-08'],
        ])])->assertSuccessful();
        $peca = MaterialEsportiu::first();

        $this->actingAs(\App\Models\User::factory()->create())
            ->delete(route('bicis.material.destroy', $peca))
            ->assertSessionHasErrors('esborrar');
        $this->assertNotNull($peca->fresh());

        $this->put(route('bicis.material.update', $peca), [
            'nom' => 'Cadena SRAM NX', 'model' => 'NX Eagle', 'tipus' => 'component', 'estat' => 'retirat',
            'data_inici' => '2025-05-10', 'data_fi' => '2025-07-31',
        ])->assertSessionHasNoErrors();
        $this->assertTrue($peca->fresh()->editat);

        // Garmin la torna a dur amb el nom i les dates d'abans: no les recupera
        $this->artisan('material:importa', ['fitxer' => $this->gear([
            ['gearPk' => 1, 'gearTypeName' => 'Other', 'gearStatusName' => 'active', 'customMakeModel' => 'Cadena20250510', 'dateBegin' => '2025-05-08', 'nou' => 'x'],
        ])])->assertSuccessful();

        $peca->refresh();
        $this->assertSame('Cadena SRAM NX', $peca->nom);
        $this->assertSame('retirat', $peca->estat);
        $this->assertSame('2025-05-10', $peca->data_inici->toDateString());
        $this->assertSame('2025-07-31', $peca->data_fi->toDateString());
        // El registre original sí que s'actualitza
        $this->assertSame('x', $peca->dades_originals['nou']);
    }

    public function test_la_pantalla_de_material_no_inclou_les_bicis(): void
    {
        $this->artisan('material:importa', ['fitxer' => $this->gear([
            self::GIANT,
            ['gearPk' => 1, 'gearTypeName' => 'Shoes', 'gearStatusName' => 'active', 'customMakeModel' => 'Spiuk 2024'],
        ])])->assertSuccessful();

        $this->actingAs(\App\Models\User::factory()->create())
            ->get(route('bicis.material.index'))
            ->assertOk()
            ->assertInertia(fn ($pagina) => $pagina->component('Bicis/Material')->has('peces', 1)->where('peces.0.nom', 'Spiuk 2024'));
    }
}
