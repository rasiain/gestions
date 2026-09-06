<?php

namespace Tests\Feature;

use App\Models\CompteCorrent;
use App\Models\Persona;
use App\Models\RendaFixaContracte;
use App\Models\RendaFixaTitol;
use App\Models\RendaFixaValor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A la renda fixa hi conviuen el NOMINAL —el que es va contractar— i el VALOR
 * PATRIMONIAL —el que val ara—, que es desa com una sèrie per data perquè caldrà saber
 * què valia el 31 de desembre.
 */
class RendaFixaTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function compte(string $nom, string $tipus = 'renda_fixa'): CompteCorrent
    {
        return CompteCorrent::create([
            'compte_corrent' => str_pad((string) ++$this->seq, 24, '0', STR_PAD_LEFT),
            'nom'            => $nom,
            'ordre'          => 0,
            'tipus'          => $tipus,
        ]);
    }

    private function titol(string $isin = 'XS2952043110'): RendaFixaTitol
    {
        return RendaFixaTitol::create(['isin' => $isin, 'nom' => 'BNP ESTRUCT EUROSTOXX 50 3A']);
    }

    /**
     * @return array<string, mixed>
     */
    private function props(): array
    {
        return $this->actingAs(User::factory()->create())
            ->get(route('renda-fixa.index'))
            ->assertOk()
            ->viewData('page')['props'];
    }

    public function test_sense_valors_el_que_val_es_el_nominal(): void
    {
        $contracte = RendaFixaContracte::create([
            'titol_id'          => $this->titol()->id,
            'compte_corrent_id' => $this->compte('Compte de títols')->id,
            'nominal'           => 25000,
        ]);

        $fila = collect($this->props()['contractes'])->sole();

        $this->assertSame(25000.0, $fila['nominal']);
        $this->assertSame(25000.0, $fila['valor']);
        $this->assertSame(0.0, $fila['diferencia']);
        $this->assertSame($contracte->id, $fila['id']);
    }

    public function test_el_valor_es_el_mes_recent_de_la_serie(): void
    {
        $contracte = RendaFixaContracte::create([
            'titol_id'          => $this->titol()->id,
            'compte_corrent_id' => $this->compte('Compte de títols')->id,
            'nominal'           => 25000,
        ]);

        RendaFixaValor::create(['contracte_id' => $contracte->id, 'data' => '2025-12-31', 'valor_patrimonial' => 24500]);
        RendaFixaValor::create(['contracte_id' => $contracte->id, 'data' => '2026-06-30', 'valor_patrimonial' => 26200]);

        $fila = collect($this->props()['contractes'])->sole();

        $this->assertSame(26200.0, $fila['valor']);
        $this->assertSame(1200.0, $fila['diferencia']);

        // I es pot demanar el d'una data concreta, que és el que voldrà el patrimoni
        $this->assertSame(24500.0, $contracte->fresh('valors')->valorAData('2025-12-31'));
    }

    public function test_els_cupons_es_sumen_per_any(): void
    {
        $contracte = RendaFixaContracte::create([
            'titol_id'               => $this->titol()->id,
            'compte_corrent_id'      => $this->compte('Compte de títols')->id,
            'compte_rendibilitat_id' => $this->compte('On arriben els cupons', 'corrent')->id,
            'nominal'                => 25000,
        ]);

        $usuari = User::factory()->create();

        foreach ([['2025-06-30', 250], ['2025-12-31', 250], ['2026-06-30', 275]] as [$data, $import]) {
            $this->actingAs($usuari)->post(route('renda-fixa.rendibilitats.store'), [
                'contracte_id' => $contracte->id,
                'data'         => $data,
                'import'       => $import,
            ])->assertRedirect();
        }

        $fila = collect($this->props()['contractes'])->sole();

        $this->assertSame(500.0, $fila['rendibilitat_per_any']['2025']);
        $this->assertSame(275.0, $fila['rendibilitat_per_any']['2026']);
        $this->assertSame('On arriben els cupons', $fila['compte_rendibilitat_nom']);
    }

    public function test_el_valor_es_reparteix_entre_els_titulars_del_compte(): void
    {
        $ana = Persona::create(['nom' => 'Ana', 'cognoms' => 'Primera']);
        $bru = Persona::create(['nom' => 'Bru', 'cognoms' => 'Segon']);

        $compte = $this->compte('Compte de títols');
        $compte->titulars()->sync([$ana->id, $bru->id]);

        RendaFixaContracte::create([
            'titol_id'          => $this->titol()->id,
            'compte_corrent_id' => $compte->id,
            'nominal'           => 25000,
        ]);

        $totals = collect($this->props()['totalsPerTitular'])->keyBy('nom');

        $this->assertSame(12500.0, $totals['Ana Primera']['total']);
        $this->assertSame(12500.0, $totals['Bru Segon']['total']);
    }

    public function test_lisin_ha_de_tenir_forma_disin_i_no_es_pot_repetir(): void
    {
        $usuari = User::factory()->create();

        $this->actingAs($usuari)
            ->post(route('renda-fixa.titols.store'), ['isin' => 'AIXO NO VAL', 'nom' => 'Prova'])
            ->assertSessionHasErrors('isin');

        $this->actingAs($usuari)
            ->post(route('renda-fixa.titols.store'), ['isin' => 'XS2952043110', 'nom' => 'Prova'])
            ->assertRedirect();

        $this->actingAs($usuari)
            ->post(route('renda-fixa.titols.store'), ['isin' => 'xs2952043110', 'nom' => 'Repetit'])
            ->assertSessionHasErrors('isin');

        $this->assertSame(1, RendaFixaTitol::count());
    }

    public function test_nomes_sofereixen_els_comptes_de_renda_fixa_per_al_titol(): void
    {
        $this->compte('De títols');
        $this->compte('Del dia a dia', 'corrent');
        $this->compte('Un fons', 'fons_inversio');

        $props = $this->props();

        $this->assertSame(['De títols'], collect($props['comptesTitol'])->pluck('nom')->all());
        $this->assertSame(['Del dia a dia'], collect($props['comptesRendibilitat'])->pluck('nom')->all());
    }
}
