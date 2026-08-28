<?php

namespace Tests\Feature;

use App\Models\CompteCorrent;
use App\Models\MovimentCompteCorrent;
use App\Models\MovimentConcepte;
use App\Models\Persona;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El total de cada titular sumant els comptes corrents.
 *
 * El saldo d'un compte es reparteix a parts iguals entre els seus titulars, i el que ha
 * de quadrar sempre és que la suma de les parts doni el saldo del compte: arrodonir cada
 * part per separat no ho garanteix.
 */
class TotalsPerTitularTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function compte(string $nom, float $saldo, string $tipus = 'corrent'): CompteCorrent
    {
        $compte = CompteCorrent::create([
            'compte_corrent' => str_pad((string) ++$this->seq, 24, '0', STR_PAD_LEFT),
            'nom'            => $nom,
            'ordre'          => 0,
            'tipus'          => $tipus,
        ]);

        // El saldo actual surt del darrer moviment del compte
        MovimentCompteCorrent::create([
            'data_moviment'     => '2026-01-01',
            'concepte_id'       => MovimentConcepte::firstOrCreate(['concepte' => 'PROVA'])->id,
            'concepte_original' => 'PROVA',
            'import'            => $saldo,
            'saldo_posterior'   => $saldo,
            'hash'              => hash('sha256', 'mov' . ++$this->seq),
            'compte_corrent_id' => $compte->id,
        ]);

        return $compte;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function totals(): array
    {
        return $this->actingAs(User::factory()->create())
            ->get(route('comptes-corrents.index'))
            ->assertOk()
            ->viewData('page')['props']['totalsPerTitular'];
    }

    public function test_reparteix_el_saldo_entre_els_titulars_del_compte(): void
    {
        $ana  = Persona::create(['nom' => 'Ana', 'cognoms' => 'Primera']);
        $bru  = Persona::create(['nom' => 'Bru', 'cognoms' => 'Segon']);

        $this->compte('Compartit', 1000.00)->titulars()->sync([$ana->id, $bru->id]);
        $this->compte('Només Ana', 500.00)->titulars()->sync([$ana->id]);

        $totals = collect($this->totals())->keyBy('nom');

        $this->assertSame(1000.0, $totals['Ana Primera']['total']);   // 500 + 500
        $this->assertSame(500.0, $totals['Bru Segon']['total']);
        $this->assertCount(2, $totals['Ana Primera']['comptes']);
    }

    public function test_els_comptes_dinversio_no_hi_entren(): void
    {
        $ana = Persona::create(['nom' => 'Ana', 'cognoms' => 'Primera']);

        $this->compte('Corrent', 100.00)->titulars()->sync([$ana->id]);
        $this->compte('Un fons', 9999.00, 'fons_inversio')->titulars()->sync([$ana->id]);
        $this->compte('Un pla', 8888.00, 'pla_pensions')->titulars()->sync([$ana->id]);

        $totals = collect($this->totals())->keyBy('nom');

        $this->assertSame(100.0, $totals['Ana Primera']['total']);
        $this->assertCount(1, $totals['Ana Primera']['comptes']);
    }

    public function test_les_parts_sumen_sempre_el_saldo_del_compte(): void
    {
        $persones = collect(['Ana', 'Bru', 'Cesc'])
            ->map(fn (string $nom) => Persona::create(['nom' => $nom, 'cognoms' => 'Prova']));

        // Un saldo que no es pot partir en tres sense residu
        $this->compte('Entre tres', 100.00)->titulars()->sync($persones->pluck('id')->all());

        $totals = $this->totals();

        $this->assertEqualsWithDelta(100.00, array_sum(array_column($totals, 'total')), 0.001);
    }

    public function test_un_compte_sense_titulars_no_es_perd(): void
    {
        $this->compte('Orfe', 250.00);

        $totals = collect($this->totals())->keyBy('nom');

        $this->assertSame(250.0, $totals['Sense titular']['total']);
        $this->assertNull($totals['Sense titular']['id']);
    }
}
