<?php

namespace Tests\Feature;

use App\Models\AportacioFons;
use App\Models\AportacioPlaPensions;
use App\Models\CompteCorrent;
use App\Models\ContracteFons;
use App\Models\ContractePlaPensions;
use App\Models\FonsInversio;
use App\Models\MovimentCompteCorrent;
use App\Models\MovimentConcepte;
use App\Models\Persona;
use App\Models\PlaPensions;
use App\Models\RendaFixaContracte;
use App\Models\RendaFixaTitol;
use App\Models\RendaFixaValor;
use App\Models\User;
use App\Models\ValorFons;
use App\Models\ValorPlaPensions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Els totals mobiliaris sumen les quatre fonts alhora, cadascuna valorada com a la seva
 * pantalla, i reparteixen cada posició entre els titulars del seu compte.
 */
class TotalsMobiliarisTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    /**
     * @param  array<int, int>  $titularIds
     */
    private function compte(string $tipus, array $titularIds): CompteCorrent
    {
        $compte = CompteCorrent::create([
            'compte_corrent' => str_pad((string) ++$this->seq, 24, '0', STR_PAD_LEFT),
            'nom'            => 'Compte ' . $this->seq,
            'ordre'          => 0,
            'tipus'          => $tipus,
        ]);

        $compte->titulars()->sync($titularIds);

        return $compte;
    }

    /** El saldo d'un compte corrent surt del darrer moviment. */
    private function compteAmbSaldo(float $saldo, array $titularIds): CompteCorrent
    {
        $compte = $this->compte('corrent', $titularIds);
        $this->moviment($compte, '2026-01-01', $saldo);

        return $compte;
    }

    private function moviment(CompteCorrent $compte, string $data, float $saldo): void
    {
        MovimentCompteCorrent::create([
            'compte_corrent_id' => $compte->id,
            'data_moviment'     => $data,
            'concepte_id'       => MovimentConcepte::firstOrCreate(['concepte' => 'PROVA'])->id,
            'concepte_original' => 'PROVA',
            'import'            => $saldo,
            'saldo_posterior'   => $saldo,
            'hash'              => hash('sha256', 'prova-' . $compte->id . '-' . $data . '-' . ++$this->seq),
        ]);
    }

    /**
     * @param  array<int, int>  $titularIds
     */
    private function contracteFons(float $participacions, float $valorParticipacio, array $titularIds): void
    {
        $fons = FonsInversio::create(['nom' => 'Fons ' . ++$this->seq]);

        ValorFons::create([
            'fons_id'            => $fons->id,
            'data'               => '2026-01-01',
            'valor_participacio' => $valorParticipacio,
        ]);

        $contracte = ContracteFons::create([
            'fons_id'           => $fons->id,
            'compte_corrent_id' => $this->compte('fons_inversio', $titularIds)->id,
            'data_inici'        => '2020-01-01',
        ]);

        AportacioFons::create([
            'contracte_id'   => $contracte->id,
            'data'           => '2020-01-15',
            'import'         => 1000,
            'participacions' => $participacions,
        ]);
    }

    /**
     * @param  array<int, int>  $titularIds
     */
    private function contractePensions(float $participacions, float $valorParticipacio, array $titularIds): void
    {
        $pla = PlaPensions::create(['nom' => 'Pla ' . ++$this->seq]);

        ValorPlaPensions::create([
            'pla_id'             => $pla->id,
            'data'               => '2026-01-01',
            'valor_participacio' => $valorParticipacio,
        ]);

        $contracte = ContractePlaPensions::create([
            'pla_id'            => $pla->id,
            'compte_corrent_id' => $this->compte('pla_pensions', $titularIds)->id,
            'data_inici'        => '2020-01-01',
        ]);

        AportacioPlaPensions::create([
            'contracte_id'   => $contracte->id,
            'data'           => '2020-01-15',
            'import'         => 1000,
            'participacions' => $participacions,
        ]);
    }

    /**
     * @param  array<int, int>  $titularIds
     */
    private function contracteRendaFixa(float $nominal, ?float $valorPatrimonial, array $titularIds): void
    {
        $titol = RendaFixaTitol::create(['isin' => 'ES' . str_pad((string) ++$this->seq, 10, '0'), 'nom' => 'Títol ' . $this->seq]);

        $contracte = RendaFixaContracte::create([
            'titol_id'          => $titol->id,
            'compte_corrent_id' => $this->compte('renda_fixa', $titularIds)->id,
            'nominal'           => $nominal,
            'data_compra'       => '2024-01-01',
        ]);

        if ($valorPatrimonial !== null) {
            RendaFixaValor::create([
                'contracte_id'      => $contracte->id,
                'data'              => '2026-01-01',
                'valor_patrimonial' => $valorPatrimonial,
            ]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function posicions(): array
    {
        return $this->actingAs(User::factory()->create())
            ->get(route('inversions.totals-mobiliaris'))
            ->assertOk()
            ->viewData('page')['props']['posicions'];
    }

    /**
     * El que sumaria la pantalla amb tot marcat.
     *
     * @return array<string, float>
     */
    private function totalsPerTitular(): array
    {
        $totals = [];

        foreach ($this->posicions() as $posicio) {
            foreach ($posicio['parts'] as $part) {
                $totals[$part['nom']] = round(($totals[$part['nom']] ?? 0) + $part['part'], 2);
            }
        }

        return $totals;
    }

    public function test_suma_les_quatre_fonts_per_titular(): void
    {
        $ana = Persona::create(['nom' => 'Ana', 'cognoms' => 'Primera']);

        $this->compteAmbSaldo(1000, [$ana->id]);
        $this->contracteFons(100, 10.00, [$ana->id]);        // 1.000
        $this->contractePensions(50, 4.00, [$ana->id]);      // 200
        $this->contracteRendaFixa(5000, 5300.00, [$ana->id]); // 5.300

        $this->assertSame(7500.0, $this->totalsPerTitular()['Ana Primera']);
    }

    public function test_cada_posicio_es_reparteix_entre_els_titulars_del_compte(): void
    {
        $ana = Persona::create(['nom' => 'Ana', 'cognoms' => 'Primera']);
        $bru = Persona::create(['nom' => 'Bru', 'cognoms' => 'Segon']);

        $this->compteAmbSaldo(1000, [$ana->id, $bru->id]);
        $this->contracteFons(100, 10.00, [$ana->id]);

        $totals = $this->totalsPerTitular();

        $this->assertSame(1500.0, $totals['Ana Primera']);  // 500 del compte + 1.000 del fons
        $this->assertSame(500.0, $totals['Bru Segon']);
    }

    public function test_les_parts_sumen_sempre_el_valor_de_la_posicio(): void
    {
        $ids = collect(['Ana', 'Bru', 'Cesc'])
            ->map(fn (string $nom) => Persona::create(['nom' => $nom, 'cognoms' => 'Prova'])->id)
            ->all();

        // 100 € entre tres no es parteix sense residu
        $this->compteAmbSaldo(100, $ids);

        $this->assertEqualsWithDelta(100.00, array_sum($this->totalsPerTitular()), 0.001);
    }

    public function test_una_posicio_sense_titular_no_es_perd(): void
    {
        $this->contracteFons(10, 5.00, []);

        $this->assertSame(50.0, $this->totalsPerTitular()['Sense titular']);
    }

    public function test_la_renda_fixa_sense_valor_declarat_val_el_nominal(): void
    {
        $ana = Persona::create(['nom' => 'Ana', 'cognoms' => 'Primera']);
        $this->contracteRendaFixa(3000, null, [$ana->id]);

        $this->assertSame(3000.0, $this->totalsPerTitular()['Ana Primera']);
    }

    public function test_els_comptes_dinversio_no_es_compten_dues_vegades(): void
    {
        $ana = Persona::create(['nom' => 'Ana', 'cognoms' => 'Primera']);

        // El compte on viu el fons no és cap posició: el que val és el contracte
        $this->contracteFons(100, 10.00, [$ana->id]);

        $fonts = array_count_values(array_column($this->posicions(), 'font'));

        $this->assertSame(['fons' => 1], $fonts);
    }

    public function test_els_titulars_son_els_que_tenen_alguna_cosa(): void
    {
        $ana = Persona::create(['nom' => 'Ana', 'cognoms' => 'Primera']);
        Persona::create(['nom' => 'Bru', 'cognoms' => 'Sense res']);

        $this->compteAmbSaldo(1000, [$ana->id]);

        $titulars = $this->actingAs(User::factory()->create())
            ->get(route('inversions.totals-mobiliaris'))
            ->viewData('page')['props']['titulars'];

        $this->assertSame(['Ana Primera'], array_column($titulars, 'nom'));
    }

    /**
     * @return array<string, mixed>
     */
    private function posicioDe(string $font): array
    {
        foreach ($this->posicions() as $posicio) {
            if ($posicio['font'] === $font) {
                return $posicio;
            }
        }

        $this->fail('No hi ha cap posició de ' . $font);
    }

    public function test_el_saldo_de_cada_mes_es_el_del_darrer_moviment_i_sarrossega(): void
    {
        $ana    = Persona::create(['nom' => 'Ana', 'cognoms' => 'Primera']);
        $compte = $this->compte('corrent', [$ana->id]);

        $this->moviment($compte, '2026-01-10', 1000);
        $this->moviment($compte, '2026-01-25', 1500);   // el darrer del gener mana
        $this->moviment($compte, '2026-03-05', 900);

        $serie = $this->posicioDe('comptes')['serie'];

        $this->assertSame(1500.0, $serie['2026-01']);
        $this->assertSame(1500.0, $serie['2026-02']);   // un mes sense moviments val el d'abans
        $this->assertSame(900.0, $serie['2026-03']);
    }

    public function test_abans_del_primer_moviment_un_compte_no_val_res(): void
    {
        $ana    = Persona::create(['nom' => 'Ana', 'cognoms' => 'Primera']);
        $compte = $this->compte('corrent', [$ana->id]);

        // Un altre compte estira la sèrie enrere; el primer encara no existia
        $this->moviment($this->compte('corrent', [$ana->id]), '2025-06-01', 500);
        $this->moviment($compte, '2026-01-10', 1000);

        $this->assertSame(0.0, $this->posicions()[0]['serie']['2025-06']);
    }

    public function test_un_fons_nomes_val_les_participacions_aportades_fins_aleshores(): void
    {
        $ana  = Persona::create(['nom' => 'Ana', 'cognoms' => 'Primera']);
        $fons = FonsInversio::create(['nom' => 'Fons de prova']);

        ValorFons::create(['fons_id' => $fons->id, 'data' => '2026-01-31', 'valor_participacio' => 10]);
        ValorFons::create(['fons_id' => $fons->id, 'data' => '2026-03-31', 'valor_participacio' => 12]);

        $contracte = ContracteFons::create([
            'fons_id'           => $fons->id,
            'compte_corrent_id' => $this->compte('fons_inversio', [$ana->id])->id,
            'data_inici'        => '2026-01-01',
        ]);

        AportacioFons::create(['contracte_id' => $contracte->id, 'data' => '2026-01-15', 'import' => 1000, 'participacions' => 100]);
        AportacioFons::create(['contracte_id' => $contracte->id, 'data' => '2026-03-15', 'import' => 500, 'participacions' => 50]);

        $serie = $this->posicioDe('fons')['serie'];

        $this->assertSame(1000.0, $serie['2026-01']);   // 100 × 10
        $this->assertSame(1000.0, $serie['2026-02']);   // res de nou: ni aportacions ni cotització
        $this->assertSame(1800.0, $serie['2026-03']);   // 150 × 12
    }

    public function test_la_renda_fixa_no_val_res_abans_de_comprar_la(): void
    {
        $ana = Persona::create(['nom' => 'Ana', 'cognoms' => 'Primera']);

        // El compte estira la sèrie fins al 2023, quan el títol encara no s'havia comprat
        $this->compteAmbSaldo(100, [$ana->id]);
        $this->moviment(CompteCorrent::where('tipus', 'corrent')->first(), '2023-05-01', 100);
        $this->contracteRendaFixa(3000, null, [$ana->id]);

        $serie = $this->posicioDe('renda_fixa')['serie'];

        $this->assertSame(0.0, $serie['2023-05']);
        $this->assertSame(3000.0, $serie['2024-01']);   // comprat el 2024-01-01, i sense valor val el nominal
    }

    public function test_la_punta_de_la_serie_diu_el_mateix_que_el_valor_dara(): void
    {
        $ana = Persona::create(['nom' => 'Ana', 'cognoms' => 'Primera']);

        $this->compteAmbSaldo(1000, [$ana->id]);
        $this->contracteFons(100, 10.00, [$ana->id]);
        $this->contracteRendaFixa(5000, 5300.00, [$ana->id]);

        $ara = now()->format('Y-m');

        foreach ($this->posicions() as $posicio) {
            $this->assertSame($posicio['valor'], $posicio['serie'][$ara], $posicio['nom']);
        }
    }
}
