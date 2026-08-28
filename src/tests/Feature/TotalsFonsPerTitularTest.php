<?php

namespace Tests\Feature;

use App\Models\AportacioFons;
use App\Models\CompteCorrent;
use App\Models\ContracteFons;
use App\Models\FonsInversio;
use App\Models\Persona;
use App\Models\User;
use App\Models\ValorFons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El valor d'un fons no és cap saldo: són les participacions per la darrera cotització.
 * Es reparteix entre els titulars del compte del contracte, com els comptes corrents.
 */
class TotalsFonsPerTitularTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function compte(string $nom): CompteCorrent
    {
        return CompteCorrent::create([
            'compte_corrent' => str_pad((string) ++$this->seq, 24, '0', STR_PAD_LEFT),
            'nom'            => $nom,
            'ordre'          => 0,
            'tipus'          => 'fons_inversio',
        ]);
    }

    /**
     * @param  array<int, int>  $titularIds
     */
    private function contracte(FonsInversio $fons, array $titularIds, float $participacions): ContracteFons
    {
        $compte = $this->compte('Compte ' . $this->seq);
        $compte->titulars()->sync($titularIds);

        $contracte = ContracteFons::create([
            'fons_id'           => $fons->id,
            'compte_corrent_id' => $compte->id,
            'data_inici'        => '2020-01-01',
        ]);

        AportacioFons::create([
            'contracte_id'   => $contracte->id,
            'data'           => '2020-01-15',
            'import'         => 1000,
            'participacions' => $participacions,
        ]);

        return $contracte;
    }

    private function fons(string $nom, float $valorParticipacio): FonsInversio
    {
        $fons = FonsInversio::create(['nom' => $nom]);

        ValorFons::create([
            'fons_id'            => $fons->id,
            'data'               => '2026-01-01',
            'valor_participacio' => $valorParticipacio,
        ]);

        return $fons;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function totals(): array
    {
        return $this->actingAs(User::factory()->create())
            ->get(route('fons-inversio.index'))
            ->assertOk()
            ->viewData('page')['props']['totalsPerTitular'];
    }

    public function test_reparteix_el_valor_del_contracte_entre_els_titulars(): void
    {
        $ana = Persona::create(['nom' => 'Ana', 'cognoms' => 'Primera']);
        $bru = Persona::create(['nom' => 'Bru', 'cognoms' => 'Segon']);

        $fons = $this->fons('Fons de prova', 10.00);
        $this->contracte($fons, [$ana->id, $bru->id], 100);  // val 1.000
        $this->contracte($fons, [$ana->id], 50);             // val 500

        $totals = collect($this->totals())->keyBy('nom');

        $this->assertSame(1000.0, $totals['Ana Primera']['total']);  // 500 + 500
        $this->assertSame(500.0, $totals['Bru Segon']['total']);
        $this->assertCount(2, $totals['Ana Primera']['contractes']);
    }

    public function test_les_parts_sumen_sempre_el_valor_del_contracte(): void
    {
        $persones = collect(['Ana', 'Bru', 'Cesc'])
            ->map(fn (string $nom) => Persona::create(['nom' => $nom, 'cognoms' => 'Prova']));

        // 100 € entre tres no es parteix sense residu
        $fons = $this->fons('Fons de prova', 1.00);
        $this->contracte($fons, $persones->pluck('id')->all(), 100);

        $this->assertEqualsWithDelta(100.00, array_sum(array_column($this->totals(), 'total')), 0.001);
    }

    public function test_un_contracte_sense_titulars_no_es_perd(): void
    {
        $fons = $this->fons('Fons de prova', 2.00);
        $this->contracte($fons, [], 25);  // val 50

        $totals = collect($this->totals())->keyBy('nom');

        $this->assertSame(50.0, $totals['Sense titular']['total']);
        $this->assertNull($totals['Sense titular']['id']);
    }
}
