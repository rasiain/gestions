<?php

namespace Tests\Feature;

use App\Models\CompteCorrent;
use App\Models\Immoble;
use App\Models\Lloguer;
use App\Models\Proveidor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L'administradora d'un immoble canvia amb els anys i se'n guarda l'històric. La gestoria
 * del lloguer no es desa enlloc més: és el tram de l'immoble vigent a cada data.
 */
class AdministracionsImmobleTest extends TestCase
{
    use RefreshDatabase;

    private function proveidor(string $nom): Proveidor
    {
        return Proveidor::create(['nom_rao_social' => $nom]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $administracions
     * @return array<string, mixed>
     */
    private function formulari(array $administracions): array
    {
        return [
            'referencia_cadastral' => 'REF-ADM',
            'adreca'               => 'Carrer de prova 1',
            'administracions'      => $administracions,
        ];
    }

    public function test_desa_l_historic_de_trams_i_la_mateixa_empresa_hi_pot_tornar(): void
    {
        $saura   = $this->proveidor('Saura');
        $deronik = $this->proveidor('Deronik');

        $this->actingAs(User::factory()->create())
            ->post(route('immobles.store'), $this->formulari([
                ['proveidor_id' => $saura->id, 'referencia' => '999797', 'percentatge' => 5, 'data_inici' => null, 'data_fi' => '2024-12-31'],
                ['proveidor_id' => $deronik->id, 'referencia' => '3823', 'percentatge' => 4, 'data_inici' => '2025-01-01', 'data_fi' => '2025-12-31'],
                ['proveidor_id' => $saura->id, 'referencia' => '1000', 'percentatge' => 4.5, 'data_inici' => '2026-01-01', 'data_fi' => null],
            ]))
            ->assertSessionHasNoErrors();

        $immoble = Immoble::firstWhere('referencia_cadastral', 'REF-ADM');

        $this->assertCount(3, $immoble->administracions);
        $this->assertSame('999797', $immoble->administracioA('2020-06-01')->referencia);
        $this->assertSame('3823', $immoble->administracioA('2025-06-01')->referencia);
        $this->assertSame('1000', $immoble->administracioA('2026-06-01')->referencia);
        $this->assertSame('1000', $immoble->administracioA()->referencia);
    }

    public function test_rebutja_trams_que_s_encavalquen(): void
    {
        $saura   = $this->proveidor('Saura');
        $deronik = $this->proveidor('Deronik');

        $this->actingAs(User::factory()->create())
            ->post(route('immobles.store'), $this->formulari([
                // El primer no té data de fi: encara és vigent quan comença el segon
                ['proveidor_id' => $saura->id, 'data_inici' => null, 'data_fi' => null],
                ['proveidor_id' => $deronik->id, 'data_inici' => '2025-01-01', 'data_fi' => null],
            ]))
            ->assertSessionHasErrors('administracions');

        $this->assertDatabaseCount('g_administracions_immobles', 0);
    }

    public function test_editar_l_immoble_reescriu_els_trams(): void
    {
        $saura   = $this->proveidor('Saura');
        $deronik = $this->proveidor('Deronik');
        $usuari  = User::factory()->create();

        $this->actingAs($usuari)->post(route('immobles.store'), $this->formulari([
            ['proveidor_id' => $saura->id, 'referencia' => '999797'],
        ]));
        $immoble = Immoble::firstWhere('referencia_cadastral', 'REF-ADM');

        $this->actingAs($usuari)
            ->put(route('immobles.update', $immoble), $this->formulari([
                ['proveidor_id' => $saura->id, 'referencia' => '999797', 'data_fi' => '2025-12-31'],
                ['proveidor_id' => $deronik->id, 'referencia' => '3823', 'data_inici' => '2026-01-01'],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('g_administracions_immobles', 2);
        $this->assertSame($deronik->id, $immoble->fresh()->administracioA('2026-03-01')->proveidor_id);
    }

    public function test_la_gestoria_del_lloguer_es_la_de_l_immoble_a_cada_data(): void
    {
        $saura   = $this->proveidor('Saura');
        $deronik = $this->proveidor('Deronik');

        $immoble = Immoble::create(['referencia_cadastral' => 'REF-L', 'adreca' => 'Carrer 2']);
        $immoble->administracions()->create(['proveidor_id' => $saura->id, 'percentatge' => 5, 'data_fi' => '2025-12-31']);
        $immoble->administracions()->create(['proveidor_id' => $deronik->id, 'percentatge' => 4, 'data_inici' => '2026-01-01']);

        $compte = CompteCorrent::create([
            'compte_corrent' => str_repeat('0', 24),
            'nom'            => 'Compte lloguer',
            'ordre'          => 0,
            'tipus'          => 'corrent',
        ]);

        $lloguer = Lloguer::create([
            'nom'               => 'Lloguer',
            'immoble_id'        => $immoble->id,
            'compte_corrent_id' => $compte->id,
        ]);

        $this->assertSame($saura->id, $lloguer->administracioA('2025-07-01')->proveidor_id);
        $this->assertSame('4.00', $lloguer->administracioA('2026-07-01')->percentatge);

        // La pantalla rep tots els trams, per triar el del dia de cada cobrament
        $this->actingAs(User::factory()->create())
            ->get(route('lloguers.index'))
            ->assertInertia(fn ($page) => $page
                ->has('lloguers.0.administracions', 2)
                ->where('lloguers.0.administracions.1.proveidor', 'Deronik')
                ->where('immobles.0.administracio.proveidor', 'Deronik'));
    }
}
