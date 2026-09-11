<?php

namespace Tests\Feature;

use App\Models\AportacioFons;
use App\Models\CompteCorrent;
use App\Models\ContracteFons;
use App\Models\FonsInversio;
use App\Models\MovimentCompteCorrent;
use App\Models\MovimentConcepte;
use App\Models\User;
use App\Services\FluxosPatrimoniService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Els fluxos diuen quants diners entren i quants en surten. El que no poden dir és que un
 * traspàs entre dos llocs teus sigui cap de les dues coses: d'aquí els parells.
 */
class FluxosPatrimoniTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function compte(string $tipus = 'corrent'): CompteCorrent
    {
        return CompteCorrent::create([
            'compte_corrent' => str_pad((string) ++$this->seq, 24, '0', STR_PAD_LEFT),
            'nom'            => 'Compte ' . $this->seq,
            'ordre'          => 0,
            'tipus'          => $tipus,
        ]);
    }

    private function moviment(CompteCorrent $compte, string $data, float $import): void
    {
        MovimentCompteCorrent::create([
            'compte_corrent_id' => $compte->id,
            'data_moviment'     => $data,
            'concepte_id'       => MovimentConcepte::firstOrCreate(['concepte' => 'PROVA'])->id,
            'concepte_original' => 'PROVA',
            'import'            => $import,
            'saldo_posterior'   => 0,
            'hash'              => hash('sha256', 'prova-' . ++$this->seq),
        ]);
    }

    /**
     * @return array{fluxos: array<int, array<string, mixed>>, traspassos: array<int, array<string, mixed>>}
     */
    private function calcula(): array
    {
        return app(FluxosPatrimoniService::class)->calcula();
    }

    public function test_separa_el_que_entra_del_que_surt_mes_a_mes(): void
    {
        $compte = $this->compte();
        $this->moviment($compte, '2026-01-10', 3000);
        $this->moviment($compte, '2026-01-20', -1200);
        $this->moviment($compte, '2026-02-05', -800);

        $fluxos = collect($this->calcula()['fluxos'])->keyBy('mes');

        $this->assertSame(3000.0, $fluxos['2026-01']['entrades']);
        $this->assertSame(1200.0, $fluxos['2026-01']['sortides']);   // les sortides, en positiu
        $this->assertSame(0.0, $fluxos['2026-02']['entrades']);
        $this->assertSame(800.0, $fluxos['2026-02']['sortides']);
    }

    public function test_dos_moviments_oposats_de_comptes_diferents_son_un_traspas(): void
    {
        $origen = $this->compte();
        $desti  = $this->compte();

        $this->moviment($origen, '2026-03-10', -5000);
        $this->moviment($desti, '2026-03-12', 5000);

        $traspassos = $this->calcula()['traspassos'];

        $this->assertCount(1, $traspassos);
        $this->assertSame('comptes-' . $origen->id, $traspassos[0]['origen']);
        $this->assertSame('comptes-' . $desti->id, $traspassos[0]['desti']);
        $this->assertSame(5000.0, $traspassos[0]['import']);
        // El mes és el de la sortida: és quan els diners es mouen
        $this->assertSame('2026-03', $traspassos[0]['mes']);
    }

    public function test_massa_dies_de_diferencia_no_es_un_traspas(): void
    {
        $this->moviment($this->compte(), '2026-03-01', -5000);
        $this->moviment($this->compte(), '2026-03-20', 5000);

        $this->assertEmpty($this->calcula()['traspassos']);
    }

    public function test_dins_del_mateix_compte_no_hi_ha_traspas(): void
    {
        $compte = $this->compte();
        $this->moviment($compte, '2026-03-10', -5000);
        $this->moviment($compte, '2026-03-11', 5000);

        $this->assertEmpty($this->calcula()['traspassos']);
    }

    public function test_el_que_se_nva_a_un_fons_tambe_es_un_traspas(): void
    {
        $compte = $this->compte();
        $this->moviment($compte, '2026-06-19', -40000);

        $fons = FonsInversio::create(['nom' => 'Fons de prova']);
        $contracte = ContracteFons::create([
            'fons_id'           => $fons->id,
            'compte_corrent_id' => $this->compte('fons_inversio')->id,
            'data_inici'        => '2020-01-01',
        ]);
        AportacioFons::create([
            'contracte_id'   => $contracte->id,
            'data'           => '2026-06-18',
            'import'         => 40000,
            'participacions' => 100,
        ]);

        $traspassos = $this->calcula()['traspassos'];

        // L'altra banda no és cap moviment: el compte d'un fons no en té
        $this->assertCount(1, $traspassos);
        $this->assertSame('comptes-' . $compte->id, $traspassos[0]['origen']);
        $this->assertSame('fons-' . $contracte->id, $traspassos[0]['desti']);
    }

    public function test_un_moviment_no_es_pot_aparellar_dues_vegades(): void
    {
        $compte = $this->compte();
        $this->moviment($compte, '2026-06-19', -10000);

        $fons = FonsInversio::create(['nom' => 'Fons de prova']);
        $contracte = ContracteFons::create([
            'fons_id'           => $fons->id,
            'compte_corrent_id' => $this->compte('fons_inversio')->id,
            'data_inici'        => '2020-01-01',
        ]);

        // Dues aportacions del mateix import i dates properes: el moviment només és d'una
        foreach (['2026-06-18', '2026-06-20'] as $data) {
            AportacioFons::create([
                'contracte_id'   => $contracte->id,
                'data'           => $data,
                'import'         => 10000,
                'participacions' => 10,
            ]);
        }

        $this->assertCount(1, $this->calcula()['traspassos']);
    }

    public function test_la_pantalla_rep_els_fluxos_i_els_traspassos(): void
    {
        $compte = $this->compte();
        $this->moviment($compte, '2026-01-10', 3000);

        $props = $this->actingAs(User::factory()->create())
            ->get(route('inversions.totals-mobiliaris'))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertArrayHasKey('fluxos', $props);
        $this->assertArrayHasKey('traspassos', $props);
    }
}
