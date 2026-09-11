<?php

namespace Tests\Feature;

use App\Models\CapitalSocialContracte;
use App\Models\CapitalSocialValor;
use App\Models\CompteCorrent;
use App\Models\Persona;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El capital social es valora com ho diu l'extracte: títols per nominal unitari, a la data
 * del darrer estat conegut. El total no es desa mai, es multiplica.
 */
class CapitalSocialTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function compte(string $nom, string $tipus = 'capital_social'): CompteCorrent
    {
        return CompteCorrent::create([
            'compte_corrent' => str_pad((string) ++$this->seq, 24, '0', STR_PAD_LEFT),
            'nom'            => $nom,
            'ordre'          => 0,
            'tipus'          => $tipus,
        ]);
    }

    /**
     * @param  array<int, int>  $titularIds
     */
    private function contracte(array $titularIds = []): CapitalSocialContracte
    {
        $compte = $this->compte('Capital ' . $this->seq);
        $compte->titulars()->sync($titularIds);

        return CapitalSocialContracte::create(['compte_corrent_id' => $compte->id]);
    }

    private function valor(CapitalSocialContracte $c, string $data, int $titols, float $unitari): void
    {
        CapitalSocialValor::create([
            'contracte_id'  => $c->id,
            'data'          => $data,
            'titols'        => $titols,
            'valor_unitari' => $unitari,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function props(): array
    {
        return $this->actingAs(User::factory()->create())
            ->get(route('capital-social.index'))
            ->assertOk()
            ->viewData('page')['props'];
    }

    public function test_el_valor_son_els_titols_pel_nominal_unitari(): void
    {
        $contracte = $this->contracte();
        $this->valor($contracte, '2026-12-31', 11, 100.00);

        $props = $this->props();

        $this->assertSame(11, $props['contractes'][0]['titols']);
        $this->assertSame(100.0, $props['contractes'][0]['valor_unitari']);
        $this->assertSame(1100.0, $props['contractes'][0]['valor']);
    }

    public function test_val_el_darrer_estat_conegut(): void
    {
        $contracte = $this->contracte();
        $this->valor($contracte, '2024-12-31', 5, 100.00);
        $this->valor($contracte, '2026-12-31', 11, 100.00);

        $this->assertSame(1100.0, $this->props()['contractes'][0]['valor']);
        $this->assertSame(500.0, $contracte->fresh()->valorAData('2025-06-30'));
    }

    public function test_sense_cap_valor_declarat_no_val_res(): void
    {
        $this->contracte();

        $this->assertSame(0.0, $this->props()['contractes'][0]['valor']);
    }

    public function test_el_valor_es_reparteix_entre_els_titulars_del_compte(): void
    {
        $ana = Persona::create(['nom' => 'Ana', 'cognoms' => 'Primera']);
        $bru = Persona::create(['nom' => 'Bru', 'cognoms' => 'Segon']);

        $this->valor($this->contracte([$ana->id, $bru->id]), '2026-12-31', 11, 100.00);

        $totals = collect($this->props()['totalsPerTitular'])->keyBy('nom');

        $this->assertSame(550.0, $totals['Ana Primera']['total']);
        $this->assertSame(550.0, $totals['Bru Segon']['total']);
    }

    public function test_un_valor_per_data_tornar_hi_el_corregeix(): void
    {
        $contracte = $this->contracte();
        $usuari    = User::factory()->create();

        $this->actingAs($usuari)->post(route('capital-social.valors.store'), [
            'contracte_id' => $contracte->id, 'data' => '2026-12-31', 'titols' => 10, 'valor_unitari' => 100,
        ])->assertRedirect();

        $this->actingAs($usuari)->post(route('capital-social.valors.store'), [
            'contracte_id' => $contracte->id, 'data' => '2026-12-31', 'titols' => 11, 'valor_unitari' => 100,
        ])->assertRedirect();

        $this->assertSame(1, CapitalSocialValor::count());
        $this->assertSame(1100.0, $contracte->fresh()->valorAData());
    }

    public function test_nomes_sofereixen_els_comptes_de_capital_social(): void
    {
        $this->compte('Del capital');
        $this->compte('Del dia a dia', 'corrent');
        $this->compte('Un fons', 'fons_inversio');

        $props = $this->props();

        $this->assertSame(['Del capital'], collect($props['comptesCapital'])->pluck('nom')->all());
        $this->assertSame(['Del dia a dia'], collect($props['comptesRendiment'])->pluck('nom')->all());
    }

    public function test_els_rendiments_es_sumen_per_any(): void
    {
        $contracte = $this->contracte();
        $usuari    = User::factory()->create();

        foreach ([['2025-06-30', 20], ['2026-06-30', 25.5], ['2026-12-31', 4.5]] as [$data, $import]) {
            $this->actingAs($usuari)->post(route('capital-social.rendiments.store'), [
                'contracte_id' => $contracte->id, 'data' => $data, 'import' => $import,
            ])->assertRedirect();
        }

        $this->assertSame(
            ['2026' => 30.0, '2025' => 20.0],
            $this->props()['contractes'][0]['rendiment_per_any'],
        );
    }

    public function test_distingeix_la_revaloracio_de_laportacio(): void
    {
        $contracte = $this->contracte();
        $this->valor($contracte, '2025-12-31', 11, 100.00);   // 1.100
        $this->valor($contracte, '2026-07-01', 11, 102.00);   // 1.122: els mateixos títols valen més
        $this->valor($contracte, '2026-12-31', 13, 102.00);   // 1.326: dos títols nous

        $valors = collect($this->props()['contractes'][0]['valors'])->keyBy('data');

        // Del primer no se'n pot dir res: no hi ha res amb què comparar-lo
        $this->assertNull($valors['2025-12-31']['revaloracio']);

        $this->assertSame(22.0, $valors['2026-07-01']['revaloracio']);
        $this->assertSame(0.0, $valors['2026-07-01']['aportacio']);

        $this->assertSame(0.0, $valors['2026-12-31']['revaloracio']);
        $this->assertSame(204.0, $valors['2026-12-31']['aportacio']);
    }

    public function test_la_variacio_sempre_suma_el_que_ha_canviat_el_total(): void
    {
        $contracte = $this->contracte();
        $this->valor($contracte, '2025-12-31', 11, 100.00);
        // Títols nous i canvi de preu alhora: les dues parts han de sumar la diferència
        $this->valor($contracte, '2026-07-01', 13, 102.00);

        $valors = collect($this->props()['contractes'][0]['valors'])->keyBy('data');
        $canvi  = $valors['2026-07-01'];

        $this->assertSame(
            round($canvi['total'] - $valors['2025-12-31']['total'], 2),
            round($canvi['aportacio'] + $canvi['revaloracio'], 2),
        );
    }

    public function test_una_aportacio_sense_compte_val_el_saldo_i_te_titulars_propis(): void
    {
        $marta  = Persona::create(['nom' => 'Marta', 'cognoms' => 'Peracaula']);
        $usuari = User::factory()->create();

        // Som Energia: cap compte, cap títol, només el saldo del certificat
        $this->actingAs($usuari)->post(route('capital-social.contractes.store'), [
            'emissor'  => 'Som Energia, SCCL',
            'nif'      => 'F55091367',
            'titulars' => [$marta->id],
        ])->assertRedirect();

        $contracte = CapitalSocialContracte::firstOrFail();
        $this->assertNull($contracte->compte_corrent_id);

        $this->actingAs($usuari)->post(route('capital-social.valors.store'), [
            'contracte_id' => $contracte->id,
            'data'         => '2025-12-31',
            'import'       => 15000,
        ])->assertRedirect();

        $props = $this->props();

        $this->assertSame(15000.0, $props['contractes'][0]['valor']);
        $this->assertNull($props['contractes'][0]['titols']);
        $this->assertSame('Som Energia, SCCL', $props['contractes'][0]['nom']);
        $this->assertSame(['Marta Peracaula'], collect($props['contractes'][0]['titulars'])->pluck('nom')->all());
        $this->assertSame(15000.0, collect($props['totalsPerTitular'])->firstWhere('nom', 'Marta Peracaula')['total']);
    }

    public function test_una_aportacio_sense_compte_necessita_emissor_i_titulars(): void
    {
        $usuari = User::factory()->create();

        $this->actingAs($usuari)
            ->post(route('capital-social.contractes.store'), [])
            ->assertSessionHasErrors(['compte_corrent_id', 'emissor', 'titulars']);

        $this->actingAs($usuari)
            ->post(route('capital-social.contractes.store'), ['emissor' => 'Som Energia, SCCL'])
            ->assertSessionHasErrors('titulars');

        $this->assertSame(0, CapitalSocialContracte::count());
    }

    public function test_un_valor_necessita_o_els_titols_o_el_saldo(): void
    {
        $contracte = $this->contracte();

        $this->actingAs(User::factory()->create())
            ->post(route('capital-social.valors.store'), ['contracte_id' => $contracte->id, 'data' => '2025-12-31'])
            ->assertSessionHasErrors('import');

        $this->assertSame(0, CapitalSocialValor::count());
    }

    public function test_el_rendiment_desa_el_brut_i_la_retencio_a_part(): void
    {
        $contracte = $this->contracte();

        $this->actingAs(User::factory()->create())->post(route('capital-social.rendiments.store'), [
            'contracte_id' => $contracte->id,
            'data'         => '2025-12-31',
            'import'       => 299.59,
            'retencio'     => 56.92,
        ])->assertRedirect();

        $rendiment = $this->props()['contractes'][0]['rendiments'][0];

        $this->assertSame(299.59, $rendiment['import']);
        $this->assertSame(56.92, $rendiment['retencio']);
        $this->assertSame(242.67, $rendiment['net']);
    }

    public function test_el_capital_social_compta_als_totals_mobiliaris(): void
    {
        $ana = Persona::create(['nom' => 'Ana', 'cognoms' => 'Primera']);
        $this->valor($this->contracte([$ana->id]), '2026-01-31', 11, 100.00);

        $posicions = $this->actingAs(User::factory()->create())
            ->get(route('inversions.totals-mobiliaris'))
            ->assertOk()
            ->viewData('page')['props']['posicions'];

        // El compte del capital social no hi surt com a compte: només el contracte
        $this->assertSame(['capital_social' => 1], array_count_values(array_column($posicions, 'font')));
        $this->assertSame(1100.0, $posicions[0]['valor']);
        $this->assertSame(1100.0, $posicions[0]['serie']['2026-01']);
        // La sèrie arrenca al primer mes amb dades, que és el del valor declarat
        $this->assertSame('2026-01', array_key_first($posicions[0]['serie']));
    }
}
