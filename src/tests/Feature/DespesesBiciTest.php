<?php

namespace Tests\Feature;

use App\Models\CompteCorrent;
use App\Models\MaterialEsportiu;
use App\Models\MovimentCompteCorrent;
use App\Models\MovimentConcepte;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDespesa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Les despeses d'una bici, i com es lliguen a un moviment del banc sencer o en part. */
class DespesesBiciTest extends TestCase
{
    use RefreshDatabase;

    private Vehicle $bici;

    private MovimentCompteCorrent $moviment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->bici = Vehicle::create(['nom' => 'Giant', 'tipus' => 'bici']);
        $compte = CompteCorrent::create(['compte_corrent' => str_repeat('1', 24), 'nom' => 'Compte', 'ordre' => 0, 'tipus' => 'corrent']);
        $this->moviment = MovimentCompteCorrent::create([
            'data_moviment'     => '2026-11-10',
            'concepte_id'       => MovimentConcepte::firstOrCreate(['concepte' => 'BOTIGA BICIS'])->id,
            'concepte_original' => 'TARGETA *1234 BOTIGA BICIS',
            'import'            => -100,
            'hash'              => hash('sha256', 'mov1'),
            'compte_corrent_id' => $compte->id,
        ]);
    }

    private function despesa(array $dades)
    {
        return $this->post(route('vehicles.despeses.store'), $dades + [
            'vehicle_id' => $this->bici->id, 'data' => '2026-11-10', 'tipus' => 'components',
        ]);
    }

    public function test_una_bici_te_els_seus_tipus_i_pot_portar_la_peca(): void
    {
        $cadena = MaterialEsportiu::create(['origen' => 'manual', 'id_extern' => 'x1', 'nom' => 'Cadena', 'tipus' => 'component', 'estat' => 'actiu']);

        $this->despesa(['import' => 35, 'material_id' => $cadena->id])->assertSessionHasNoErrors()->assertRedirect(route('bicis.index'));
        $this->assertSame($cadena->id, VehicleDespesa::first()->material_id);

        // La llista de bicis amb una despesa i cap repostatge (abans petava)
        $this->get(route('bicis.index'))->assertOk()
            ->assertInertia(fn ($p) => $p->where('vehicles.0.despeses.0.material', 'Cadena')
                ->where('vehicles.0.despeses.0.tipus_nom', 'Components')
                ->where('vehicles.0.per_any.0.altres', 35));

        // L'ITV és dels vehicles a motor
        $this->despesa(['import' => 10, 'tipus' => 'itv'])->assertSessionHasErrors('tipus');
    }

    public function test_un_moviment_es_reparteix_pero_no_se_n_assigna_mes_del_que_va_sortir(): void
    {
        $this->despesa(['import' => 60, 'moviment_id' => $this->moviment->id])->assertSessionHasNoErrors();
        $this->despesa(['import' => 40, 'moviment_id' => $this->moviment->id, 'tipus' => 'roba'])->assertSessionHasNoErrors();
        $this->despesa(['import' => 0.01, 'moviment_id' => $this->moviment->id])->assertSessionHasErrors('import');

        // En editar-ne una, el seu import no compta com a pres
        $primera = VehicleDespesa::orderBy('id')->first();
        $this->put(route('vehicles.despeses.update', $primera), [
            'vehicle_id' => $this->bici->id, 'data' => '2026-11-10', 'tipus' => 'components', 'import' => 60, 'moviment_id' => $this->moviment->id,
        ])->assertSessionHasNoErrors();
    }

    public function test_la_cerca_dona_el_que_queda_per_assignar_i_amaga_els_moviments_esgotats(): void
    {
        $url = fn (array $p) => route('vehicles.moviments-despesa', $p);

        $this->getJson($url(['data' => '2026-11-12']))->assertJsonPath('0.pendent', 100)->assertJsonPath('0.concepte', 'BOTIGA BICIS');

        $this->despesa(['import' => 70, 'moviment_id' => $this->moviment->id]);
        $this->getJson($url(['data' => '2026-11-12']))->assertJsonPath('0.pendent', 30);

        // Editant-la, el moviment hi torna sencer
        $despesa = VehicleDespesa::first();
        $this->getJson($url(['data' => '2026-11-12', 'despesa' => $despesa->id]))->assertJsonPath('0.pendent', 100);

        $this->despesa(['import' => 30, 'moviment_id' => $this->moviment->id]);
        $this->getJson($url(['data' => '2026-11-12']))->assertJsonCount(0);

        // Lluny de la data només surt cercant-lo pel text
        $this->getJson($url(['data' => '2026-06-01', 'q' => 'botiga', 'despesa' => $despesa->id]))->assertJsonCount(1);
        $this->getJson($url(['data' => '2026-06-01', 'despesa' => $despesa->id]))->assertJsonCount(0);
    }
}
