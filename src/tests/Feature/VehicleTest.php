<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\MovimentCompteCorrent;
use App\Models\MovimentConcepte;
use App\Models\Categoria;
use App\Models\CompteCorrent;
use App\Models\Vehicle;
use App\Models\VehicleDespesa;
use App\Models\VehicleRepostatge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleTest extends TestCase
{
    use RefreshDatabase;

    private function usuari(): User
    {
        return User::factory()->create();
    }

    public function test_cada_llista_mostra_nomes_el_que_li_toca(): void
    {
        Vehicle::create(['nom' => 'Peugeot Partner', 'tipus' => 'cotxe']);
        Vehicle::create(['nom' => 'La moto', 'tipus' => 'moto']);
        Vehicle::create(['nom' => 'La bici', 'tipus' => 'bici']);

        $motor = $this->actingAs($this->usuari())
            ->get(route('vehicles-motor.index'))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame(['Peugeot Partner', 'La moto'], collect($motor['vehicles'])->pluck('nom')->sortDesc()->values()->all());
        $this->assertTrue($motor['esMotor']);

        $bicis = $this->actingAs($this->usuari())
            ->get(route('bicis.index'))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame(['La bici'], collect($bicis['vehicles'])->pluck('nom')->all());
        // Una bici no té matrícula ni combustible
        $this->assertFalse($bicis['esMotor']);
    }

    public function test_es_pot_crear_editar_i_eliminar_un_vehicle(): void
    {
        $usuari = $this->usuari();

        $this->actingAs($usuari)->post(route('vehicles.store'), [
            'nom'   => 'La moto',
            'tipus' => 'moto',
        ])->assertRedirect(route('vehicles-motor.index'));

        $vehicle = Vehicle::sole();
        $this->assertSame('moto', $vehicle->tipus);

        $this->actingAs($usuari)->put(route('vehicles.update', $vehicle->id), [
            'nom'       => 'La moto',
            'tipus'     => 'moto',
            'matricula' => '1234ABC',
        ])->assertRedirect(route('vehicles-motor.index'));

        $this->assertSame('1234ABC', $vehicle->fresh()->matricula);

        $this->actingAs($usuari)->delete(route('vehicles.destroy', $vehicle->id))->assertRedirect();
        $this->assertSame(0, Vehicle::count());
    }

    public function test_un_vehicle_de_baixa_es_marca_com_a_no_actiu(): void
    {
        $venut  = Vehicle::create(['nom' => 'Honda', 'tipus' => 'cotxe', 'data_baixa' => '2021-06-30']);
        $actual = Vehicle::create(['nom' => 'Peugeot', 'tipus' => 'cotxe']);

        $this->assertFalse($venut->esActiu());
        $this->assertTrue($actual->esActiu());

        $vehicles = collect($this->actingAs($this->usuari())
            ->get(route('vehicles-motor.index'))
            ->viewData('page')['props']['vehicles'])->keyBy('nom');

        $this->assertFalse($vehicles['Honda']['actiu']);
        $this->assertTrue($vehicles['Peugeot']['actiu']);
    }

    public function test_no_sadmet_un_tipus_desconegut_ni_una_baixa_anterior_a_lalta(): void
    {
        $usuari = $this->usuari();

        $this->actingAs($usuari)
            ->post(route('vehicles.store'), ['nom' => 'Patinet', 'tipus' => 'nau espacial'])
            ->assertSessionHasErrors('tipus');

        $this->actingAs($usuari)
            ->post(route('vehicles.store'), [
                'nom'        => 'Cotxe',
                'tipus'      => 'cotxe',
                'data_alta'  => '2020-01-01',
                'data_baixa' => '2019-12-31',
            ])
            ->assertSessionHasErrors('data_baixa');

        $this->assertSame(0, Vehicle::count());
    }

    public function test_una_bici_torna_a_la_llista_de_bicis(): void
    {
        $this->actingAs($this->usuari())
            ->post(route('vehicles.store'), ['nom' => 'La bici', 'tipus' => 'bici'])
            ->assertRedirect(route('bicis.index'));
    }

    public function test_el_combustible_nomes_admet_els_de_la_llista(): void
    {
        $usuari = $this->usuari();

        $this->actingAs($usuari)->post(route('vehicles.store'), [
            'nom' => 'Partner', 'tipus' => 'cotxe', 'combustible' => 'dièsel',
        ])->assertRedirect();

        $this->assertSame('dièsel', Vehicle::sole()->combustible);

        $this->actingAs($usuari)->post(route('vehicles.store'), [
            'nom' => 'Coet', 'tipus' => 'cotxe', 'combustible' => 'querosè',
        ])->assertSessionHasErrors('combustible');
    }

    public function test_dels_repostatges_en_surten_els_litres_els_km_i_el_consum(): void
    {
        $vehicle = Vehicle::create(['nom' => 'Partner', 'tipus' => 'cotxe', 'combustible' => 'dièsel']);
        $usuari  = $this->usuari();

        // Dos plens: 50 litres per a 700 km
        foreach ([['2026-01-10', 180000, 1.500, 60.00], ['2026-02-10', 180700, 1.400, 70.00]] as [$data, $km, $preu, $cost]) {
            $this->actingAs($usuari)->post(route('vehicles.repostatges.store'), [
                'vehicle_id' => $vehicle->id,
                'data'       => $data,
                'km_totals'  => $km,
                'preu_litre' => $preu,
                'cost'       => $cost,
                'diposit_ple' => true,
            ])->assertRedirect(route('vehicles-motor.index'));
        }

        $files = collect($this->actingAs($usuari)->get(route('vehicles-motor.index'))
            ->viewData('page')['props']['vehicles'])->sole()['repostatges'];

        // Es mostren del més recent al més antic
        $darrer = $files[0];
        $this->assertSame(700, $darrer['km']);
        $this->assertSame(50.0, $darrer['litres']);          // 70 / 1,40
        $this->assertSame(7.14, $darrer['consum']);          // 50 litres per 700 km

        // El primer no té amb què comparar-se
        $this->assertNull($files[1]['km']);
        $this->assertNull($files[1]['consum']);
    }

    public function test_un_diposit_a_mitges_no_dona_consum(): void
    {
        $vehicle = Vehicle::create(['nom' => 'Partner', 'tipus' => 'cotxe', 'combustible' => 'dièsel']);
        $usuari  = $this->usuari();

        VehicleRepostatge::create(['vehicle_id' => $vehicle->id, 'data' => '2026-01-10', 'km_totals' => 180000, 'preu_litre' => 1.5, 'cost' => 60]);
        VehicleRepostatge::create(['vehicle_id' => $vehicle->id, 'data' => '2026-02-10', 'km_totals' => 180700, 'preu_litre' => 1.4, 'cost' => 70, 'diposit_ple' => false]);

        $files = collect($this->actingAs($usuari)->get(route('vehicles-motor.index'))
            ->viewData('page')['props']['vehicles'])->sole()['repostatges'];

        $this->assertSame(700, $files[0]['km']);
        $this->assertSame(50.0, $files[0]['litres']);
        // Sense omplir el dipòsit, els litres posats no són els gastats
        $this->assertNull($files[0]['consum']);
    }

    public function test_proposa_els_moviments_de_combustible_dels_dies_propers(): void
    {
        $compte = CompteCorrent::create([
            'compte_corrent' => str_pad('1', 24, '0', STR_PAD_LEFT),
            'nom' => 'Compte', 'ordre' => 0, 'tipus' => 'corrent',
        ]);

        $categoria = Categoria::create(['compte_corrent_id' => $compte->id, 'nom' => 'GASOLINA']);

        $moviment = MovimentCompteCorrent::create([
            'data_moviment'     => '2026-02-10',
            'concepte_id'       => MovimentConcepte::firstOrCreate(['concepte' => 'BENZINERA'])->id,
            'concepte_original' => 'BENZINERA',
            'import'            => -70.00,
            'hash'              => hash('sha256', 'mov1'),
            'compte_corrent_id' => $compte->id,
            'categoria_id'      => $categoria->id,
        ]);

        $proposats = $this->actingAs($this->usuari())
            ->getJson(route('vehicles.moviments-combustible', ['data' => '2026-02-11']))
            ->assertOk()
            ->json();

        $this->assertSame([$moviment->id], array_column($proposats, 'id'));
        $this->assertEqualsWithDelta(70.0, $proposats[0]['import'], 0.001);

        // Un cop lligat a un repostatge, ja no es proposa
        $vehicle = Vehicle::create(['nom' => 'Partner', 'tipus' => 'cotxe', 'combustible' => 'dièsel']);
        $repostatge = VehicleRepostatge::create([
            'vehicle_id' => $vehicle->id, 'data' => '2026-02-10', 'km_totals' => 180000,
            'preu_litre' => 1.4, 'cost' => 70, 'moviment_id' => $moviment->id,
        ]);

        $this->assertSame([], $this->actingAs($this->usuari())
            ->getJson(route('vehicles.moviments-combustible', ['data' => '2026-02-11']))->json());

        // Llevat que s'estigui editant aquell repostatge: el moviment és seu i s'ha de poder desvincular
        $propis = $this->actingAs($this->usuari())
            ->getJson(route('vehicles.moviments-combustible', ['data' => '2026-02-11', 'repostatge' => $repostatge->id]))
            ->json();

        $this->assertSame([$moviment->id], array_column($propis, 'id'));
    }

    public function test_el_resum_per_any_creua_km_combustible_i_despeses(): void
    {
        $vehicle = Vehicle::create(['nom' => 'Partner', 'tipus' => 'cotxe', 'combustible' => 'dièsel']);

        // Lectura de tancament del 2025 i dos repostatges del 2026
        VehicleRepostatge::create(['vehicle_id' => $vehicle->id, 'data' => '2025-12-20', 'km_totals' => 100000, 'preu_litre' => 1.5, 'cost' => 60]);
        VehicleRepostatge::create(['vehicle_id' => $vehicle->id, 'data' => '2026-03-10', 'km_totals' => 105000, 'preu_litre' => 1.5, 'cost' => 75]);
        VehicleRepostatge::create(['vehicle_id' => $vehicle->id, 'data' => '2026-09-10', 'km_totals' => 110000, 'preu_litre' => 1.6, 'cost' => 80]);

        VehicleDespesa::create(['vehicle_id' => $vehicle->id, 'data' => '2026-05-01', 'tipus' => 'itv', 'import' => 45.59]);
        VehicleDespesa::create(['vehicle_id' => $vehicle->id, 'data' => '2026-06-01', 'tipus' => 'reparacio', 'import' => 200, 'km_totals' => 107000]);

        $anys = collect(collect($this->actingAs($this->usuari())->get(route('vehicles-motor.index'))
            ->viewData('page')['props']['vehicles'])->sole()['per_any'])->keyBy('any');

        $enguany = $anys[2026];

        // De 100.000 (darrera lectura del 2025) a 110.000. Aquí les dues mesures coincideixen:
        // la darrera lectura de l'any és un repostatge, no pas la del taller
        $this->assertSame(10000, $enguany['km']);
        $this->assertSame(10000, $enguany['km_estimats']);
        $this->assertSame(100.0, $enguany['litres']);        // 50 + 50
        $this->assertSame(1.0, $enguany['consum']);          // 100 litres per 10.000 km
        $this->assertSame(155.0, $enguany['carburant']);     // 75 + 80
        $this->assertSame(245.59, $enguany['altres']);       // 45,59 + 200
        $this->assertSame(400.59, $enguany['total']);

        // El primer any no té lectura anterior: no se saben els quilòmetres
        $this->assertNull($anys[2025]['km']);
        $this->assertNull($anys[2025]['consum']);
    }

    public function test_els_km_del_consum_nomes_compten_entre_repostatges(): void
    {
        $vehicle = Vehicle::create(['nom' => 'Partner', 'tipus' => 'cotxe', 'combustible' => 'dièsel']);

        VehicleRepostatge::create(['vehicle_id' => $vehicle->id, 'data' => '2025-12-20', 'km_totals' => 100000, 'preu_litre' => 1.5, 'cost' => 60]);
        VehicleRepostatge::create(['vehicle_id' => $vehicle->id, 'data' => '2026-03-10', 'km_totals' => 105000, 'preu_litre' => 1.5, 'cost' => 75]);

        // Al novembre encara s'hi roda, però ja no s'hi torna a posar benzina: el taller
        // és l'única lectura que ho sap
        VehicleDespesa::create(['vehicle_id' => $vehicle->id, 'data' => '2026-11-05', 'tipus' => 'reparacio', 'import' => 200, 'km_totals' => 108000]);

        // Un any només amb l'assegurança, sense cap lectura del comptador
        VehicleDespesa::create(['vehicle_id' => $vehicle->id, 'data' => '2027-02-01', 'tipus' => 'asseguranca', 'import' => 300]);

        $anys = collect(collect($this->actingAs($this->usuari())->get(route('vehicles-motor.index'))
            ->viewData('page')['props']['vehicles'])->sole()['per_any'])->keyBy('any');

        $enguany = $anys[2026];

        // El consum es mesura de repostatge a repostatge: 50 litres per 5.000 km
        $this->assertSame(5000, $enguany['km']);
        $this->assertSame(50.0, $enguany['litres']);
        $this->assertSame(1.0, $enguany['consum']);

        // El cost, en canvi, es reparteix entre tot el que s'ha rodat: 275 € / 8.000 km
        $this->assertSame(8000, $enguany['km_estimats']);
        $this->assertSame(275.0, $enguany['total']);
        $this->assertSame(0.034, $enguany['cost_km']);

        // Sense cap lectura de l'any no se sap fins on va arribar: ni zero ni l'última d'abans
        $this->assertNull($anys[2027]['km']);
        $this->assertNull($anys[2027]['km_estimats']);
        $this->assertNull($anys[2027]['cost_km']);
    }

    public function test_una_despesa_ha_de_ser_dun_tipus_conegut(): void
    {
        $vehicle = Vehicle::create(['nom' => 'Partner', 'tipus' => 'cotxe', 'combustible' => 'dièsel']);
        $usuari  = $this->usuari();

        $this->actingAs($usuari)->post(route('vehicles.despeses.store'), [
            'vehicle_id' => $vehicle->id, 'data' => '2026-05-01', 'tipus' => 'multa', 'import' => 100,
        ])->assertSessionHasErrors('tipus');

        $this->actingAs($usuari)->post(route('vehicles.despeses.store'), [
            'vehicle_id' => $vehicle->id, 'data' => '2026-05-01', 'tipus' => 'itv', 'import' => 45.59,
        ])->assertRedirect(route('vehicles-motor.index'));

        $this->assertSame(1, VehicleDespesa::count());
    }

    public function test_una_despesa_es_pot_editar(): void
    {
        $vehicle = Vehicle::create(['nom' => 'Partner', 'tipus' => 'cotxe', 'combustible' => 'dièsel']);
        $despesa = VehicleDespesa::create([
            'vehicle_id' => $vehicle->id, 'data' => '2026-05-01', 'tipus' => 'reparacio',
            'import' => 120, 'taller' => 'Talleres Pep', 'motiu' => 'Canvi de pastilles',
        ]);

        $this->actingAs($this->usuari())->put(route('vehicles.despeses.update', $despesa->id), [
            'vehicle_id' => $vehicle->id, 'data' => '2026-05-03', 'tipus' => 'itv',
            'import' => 45.59, 'km_totals' => 91000, 'taller' => 'ITV Figueres', 'motiu' => null,
        ])->assertRedirect(route('vehicles-motor.index'));

        $despesa->refresh();
        $this->assertSame('2026-05-03', $despesa->data->toDateString());
        $this->assertSame('itv', $despesa->tipus);
        $this->assertSame(45.59, (float) $despesa->import);
        $this->assertSame(91000, $despesa->km_totals);
        $this->assertSame('ITV Figueres', $despesa->taller);
        $this->assertNull($despesa->motiu);
        $this->assertSame(1, VehicleDespesa::count());
    }

    public function test_un_repostatge_es_pot_editar(): void
    {
        $vehicle = Vehicle::create(['nom' => 'Partner', 'tipus' => 'cotxe', 'combustible' => 'dièsel']);
        $repostatge = VehicleRepostatge::create([
            'vehicle_id' => $vehicle->id, 'data' => '2026-02-10', 'km_totals' => 180000,
            'preu_litre' => 1.4, 'cost' => 70, 'benzinera' => 'Repsol', 'diposit_ple' => true,
        ]);

        $this->actingAs($this->usuari())->put(route('vehicles.repostatges.update', $repostatge->id), [
            'vehicle_id' => $vehicle->id, 'data' => '2026-02-11', 'km_totals' => 180450,
            'preu_litre' => 1.459, 'cost' => 65.20, 'benzinera' => 'Petrocat', 'diposit_ple' => false,
        ])->assertRedirect(route('vehicles-motor.index'));

        $repostatge->refresh();
        $this->assertSame('2026-02-11', $repostatge->data->toDateString());
        $this->assertSame(180450, $repostatge->km_totals);
        $this->assertEqualsWithDelta(65.20, (float) $repostatge->cost, 0.001);
        $this->assertSame('Petrocat', $repostatge->benzinera);
        $this->assertFalse($repostatge->diposit_ple);
        $this->assertSame(1, VehicleRepostatge::count());

        // Els km del comptador continuen sent obligatoris en editar
        $this->actingAs($this->usuari())->put(route('vehicles.repostatges.update', $repostatge->id), [
            'vehicle_id' => $vehicle->id, 'data' => '2026-02-11', 'preu_litre' => 1.459, 'cost' => 65.20,
        ])->assertSessionHasErrors('km_totals');
    }
}
