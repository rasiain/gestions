<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
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

        $cotxes = $this->actingAs($this->usuari())
            ->get(route('cotxes.index'))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame(['Peugeot Partner', 'La moto'], collect($cotxes['vehicles'])->pluck('nom')->sortDesc()->values()->all());
        $this->assertTrue($cotxes['esMotor']);

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
        ])->assertRedirect(route('cotxes.index'));

        $vehicle = Vehicle::sole();
        $this->assertSame('moto', $vehicle->tipus);

        $this->actingAs($usuari)->put(route('vehicles.update', $vehicle->id), [
            'nom'       => 'La moto',
            'tipus'     => 'moto',
            'matricula' => '1234ABC',
        ])->assertRedirect(route('cotxes.index'));

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
            ->get(route('cotxes.index'))
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
}
