<?php

namespace Tests\Feature;

use App\Models\CompteCorrent;
use App\Models\MovimentCompteCorrent;
use App\Models\MovimentConcepte;
use App\Models\PatrimoniNota;
use App\Models\Persona;
use App\Models\User;
use App\Services\NotesPatrimoniService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les notes expliquen els salts del patrimoni. El criteri d'una nota automàtica és només
 * l'import —tot moviment que passi del llindar— i el que s'hi escriu a sobre es desa.
 */
class NotesPatrimoniTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    /**
     * @param  array<int, int>  $titularIds
     */
    private function compte(array $titularIds = []): CompteCorrent
    {
        $compte = CompteCorrent::create([
            'compte_corrent' => str_pad((string) ++$this->seq, 24, '0', STR_PAD_LEFT),
            'nom'            => 'Compte ' . $this->seq,
            'ordre'          => 0,
            'tipus'          => 'corrent',
        ]);

        $compte->titulars()->sync($titularIds);

        return $compte;
    }

    private function moviment(CompteCorrent $compte, float $import, string $concepte = 'PROVA'): MovimentCompteCorrent
    {
        return MovimentCompteCorrent::create([
            'compte_corrent_id' => $compte->id,
            'data_moviment'     => '2026-02-11',
            'concepte_id'       => MovimentConcepte::firstOrCreate(['concepte' => $concepte])->id,
            'concepte_original' => $concepte,
            'import'            => $import,
            'saldo_posterior'   => 1000,
            'hash'              => hash('sha256', 'prova-' . ++$this->seq),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function notes(): array
    {
        return $this->actingAs(User::factory()->create())
            ->get(route('inversions.totals-mobiliaris'))
            ->assertOk()
            ->viewData('page')['props']['notes'];
    }

    public function test_nomes_son_nota_els_moviments_que_passen_del_minim(): void
    {
        $compte = $this->compte();
        $this->moviment($compte, -50000, 'INVERSIONS');
        $this->moviment($compte, -999.99, 'CAFÈ');           // per sota del mínim
        $this->moviment($compte, 1500, 'LLOGUER');

        $notes = collect($this->notes());

        $this->assertSame(['INVERSIONS', 'LLOGUER'], $notes->pluck('titol')->sort()->values()->all());
    }

    public function test_el_servidor_envia_els_dos_signes_i_la_pantalla_tria(): void
    {
        $compte = $this->compte();
        $this->moviment($compte, -5000, 'IMPOST');
        $this->moviment($compte, 5000, 'HERÈNCIA');

        // La pantalla ensenya només les despeses si no es demana el contrari, però el
        // filtre és seu: així marcar «amb ingressos» no ha de tornar al servidor
        $this->assertCount(2, $this->notes());
    }

    public function test_la_nota_diu_de_quin_compte_es(): void
    {
        // La pantalla treu les notes dels comptes que s'han desmarcat de la suma
        $compte = $this->compte();
        $this->moviment($compte, -50000);

        $this->assertSame($compte->id, $this->notes()[0]['compte_id']);

        // Una nota escrita a mà no és de cap compte, i per tant no la treu cap casella
        $this->actingAs(User::factory()->create())->post(route('inversions.notes.store'), [
            'data'  => '2026-07-01',
            'titol' => 'Revaloració',
        ])->assertRedirect();

        $manual = collect($this->notes())->firstWhere('manual', true);

        $this->assertNull($manual['compte_id']);
    }

    public function test_la_nota_dun_moviment_porta_els_titulars_del_seu_compte(): void
    {
        $ana = Persona::create(['nom' => 'Ana', 'cognoms' => 'Primera']);
        $bru = Persona::create(['nom' => 'Bru', 'cognoms' => 'Segon']);

        $this->moviment($this->compte([$ana->id, $bru->id]), -20000);

        $this->assertEqualsCanonicalizing([$ana->id, $bru->id], $this->notes()[0]['titulars']);
    }

    public function test_la_descripcio_sescriu_sobre_el_moviment(): void
    {
        $moviment = $this->moviment($this->compte(), -50000, 'CAIXABANK');

        $this->actingAs(User::factory()->create())->post(route('inversions.notes.store'), [
            'moviment_id' => $moviment->id,
            'descripcio'  => 'Compra d\'un estructurat que encara no consta enlloc',
        ])->assertRedirect();

        $nota = $this->notes()[0];

        $this->assertSame('CAIXABANK', $nota['titol']);   // el títol continua sent el del banc
        $this->assertSame('Compra d\'un estructurat que encara no consta enlloc', $nota['descripcio']);
        $this->assertSame(-50000.0, $nota['import']);
    }

    public function test_una_nota_per_moviment_tornar_hi_la_corregeix(): void
    {
        $moviment = $this->moviment($this->compte(), -50000);
        $usuari   = User::factory()->create();

        foreach (['Primera versió', 'La bona'] as $text) {
            $this->actingAs($usuari)->post(route('inversions.notes.store'), [
                'moviment_id' => $moviment->id,
                'descripcio'  => $text,
            ])->assertRedirect();
        }

        $this->assertSame(1, PatrimoniNota::count());
        $this->assertSame('La bona', $this->notes()[0]['descripcio']);
    }

    public function test_un_moviment_amagat_no_surt(): void
    {
        $moviment = $this->moviment($this->compte(), -80000, 'TRASPÀS INTERN');

        $this->actingAs(User::factory()->create())->post(route('inversions.notes.store'), [
            'moviment_id' => $moviment->id,
            'ocult'       => true,
        ])->assertRedirect();

        $this->assertTrue($this->notes()[0]['ocult']);
    }

    public function test_una_nota_manual_no_necessita_cap_moviment(): void
    {
        $ana = Persona::create(['nom' => 'Ana', 'cognoms' => 'Primera']);

        $this->actingAs(User::factory()->create())->post(route('inversions.notes.store'), [
            'data'       => '2026-07-01',
            'titol'      => 'Revaloració de títols',
            'import'     => 22,
            'titulars'   => [$ana->id],
            'descripcio' => 'El nominal puja de 100 a 102',
        ])->assertRedirect();

        $nota = $this->notes()[0];

        $this->assertTrue($nota['manual']);
        $this->assertNull($nota['moviment_id']);
        $this->assertSame('Revaloració de títols', $nota['titol']);
        $this->assertSame([$ana->id], $nota['titulars']);
    }

    public function test_una_nota_manual_necessita_data_i_titol(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('inversions.notes.store'), ['descripcio' => 'Sense res més'])
            ->assertSessionHasErrors(['data', 'titol']);

        $this->assertSame(0, PatrimoniNota::count());
    }

    public function test_el_minim_del_servidor_es_mes_baix_que_el_llindar_de_la_pantalla(): void
    {
        // La pantalla mou el llindar sense tornar al servidor: el mínim ha de deixar-hi marge
        $this->assertLessThan(NotesPatrimoniService::PER_DEFECTE, NotesPatrimoniService::MINIM);

        $llindar = $this->actingAs(User::factory()->create())
            ->get(route('inversions.totals-mobiliaris'))
            ->viewData('page')['props']['llindar'];

        $this->assertSame(NotesPatrimoniService::MINIM, $llindar['minim']);
        $this->assertSame(NotesPatrimoniService::PER_DEFECTE, $llindar['per_defecte']);
    }
}
