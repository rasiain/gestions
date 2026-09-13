<?php

namespace Tests\Feature;

use App\Models\CompteCorrent;
use App\Models\Immoble;
use App\Models\Lloguer;
use App\Models\MovimentCompteCorrent;
use App\Models\MovimentConcepte;
use App\Models\Persona;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Un compte de lloguer es reparteix segons les quotes de l'immoble, no a parts iguals. I
 * com que les quotes tenen dates, el repartiment pot canviar al llarg de la sèrie.
 */
class QuotesTitularitatTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    /**
     * @param  array<int, int>  $titularIds
     */
    private function compteAmbSaldo(array $titularIds, float $saldo): CompteCorrent
    {
        $compte = CompteCorrent::create([
            'compte_corrent' => str_pad((string) ++$this->seq, 24, '0', STR_PAD_LEFT),
            'nom'            => 'Compte ' . $this->seq,
            'ordre'          => 0,
            'tipus'          => 'corrent',
        ]);
        $compte->titulars()->sync($titularIds);

        MovimentCompteCorrent::create([
            'compte_corrent_id' => $compte->id,
            'data_moviment'     => '2026-01-01',
            'concepte_id'       => MovimentConcepte::firstOrCreate(['concepte' => 'PROVA'])->id,
            'concepte_original' => 'PROVA',
            'import'            => $saldo,
            'saldo_posterior'   => $saldo,
            'hash'              => hash('sha256', 'prova-' . $this->seq),
        ]);

        return $compte;
    }

    /**
     * @param  array<int, array{0: int, 1: float|null, 2: string, 3: string|null}>  $quotes
     */
    private function immobleAmbLloguer(CompteCorrent $compte, array $quotes): Immoble
    {
        $immoble = Immoble::create([
            'referencia_cadastral' => 'REF' . ++$this->seq,
            'adreca'               => 'Carrer de prova ' . $this->seq,
        ]);

        foreach ($quotes as [$personaId, $quota, $inici, $fi]) {
            $immoble->propietaris()->attach($personaId, [
                'quota' => $quota, 'data_inici' => $inici, 'data_fi' => $fi,
            ]);
        }

        Lloguer::create([
            'nom'               => 'Lloguer ' . $this->seq,
            'immoble_id'        => $immoble->id,
            'compte_corrent_id' => $compte->id,
        ]);

        return $immoble;
    }

    /**
     * @return array<string, mixed>
     */
    private function posicio(): array
    {
        return $this->actingAs(User::factory()->create())
            ->get(route('inversions.totals-mobiliaris'))
            ->assertOk()
            ->viewData('page')['props']['posicions'][0];
    }

    public function test_sense_quotes_es_reparteix_a_parts_iguals(): void
    {
        $ana = Persona::create(['nom' => 'Ana', 'cognoms' => 'Primera']);
        $bru = Persona::create(['nom' => 'Bru', 'cognoms' => 'Segon']);

        $this->compteAmbSaldo([$ana->id, $bru->id], 1000);

        $this->assertSame([500.0, 500.0], array_column($this->posicio()['parts'], 'part'));
    }

    public function test_les_quotes_de_limmoble_manen_sobre_les_parts_iguals(): void
    {
        $marta = Persona::create(['nom' => 'Marta', 'cognoms' => 'Peracaula']);
        $pau   = Persona::create(['nom' => 'Pau', 'cognoms' => 'Peracaula']);

        $compte = $this->compteAmbSaldo([$marta->id, $pau->id], 1000);
        $this->immobleAmbLloguer($compte, [
            [$marta->id, 66.67, '2021-05-16', null],
            [$pau->id, 33.33, '2021-05-16', null],
        ]);

        $parts = collect($this->posicio()['parts'])->keyBy('nom');

        $this->assertSame(666.70, $parts['Marta Peracaula']['part']);
        $this->assertSame(333.30, $parts['Pau Peracaula']['part']);
    }

    public function test_un_canvi_de_quota_obre_un_tram_nou(): void
    {
        $marta = Persona::create(['nom' => 'Marta', 'cognoms' => 'Peracaula']);
        $pau   = Persona::create(['nom' => 'Pau', 'cognoms' => 'Peracaula']);

        $compte = $this->compteAmbSaldo([$marta->id, $pau->id], 1000);
        $this->immobleAmbLloguer($compte, [
            // Fins al 2023 anaven a mitges; des del 2024, la Marta en té dos terços
            [$marta->id, 50, '2021-01-01', '2023-12-31'],
            [$pau->id, 50, '2021-01-01', '2023-12-31'],
            [$marta->id, 66.67, '2024-01-01', null],
            [$pau->id, 33.33, '2024-01-01', null],
        ]);

        $trams = collect($this->posicio()['trams'])->keyBy('des_de');

        $this->assertSame(['2021-01', '2024-01'], $trams->keys()->all());
        $this->assertSame([50.0, 50.0], array_column($trams['2021-01']['parts'], 'pes'));
        $this->assertSame([66.67, 33.33], array_column($trams['2024-01']['parts'], 'pes'));
    }

    public function test_un_compte_amb_immobles_diferents_torna_a_parts_iguals(): void
    {
        $marta = Persona::create(['nom' => 'Marta', 'cognoms' => 'Peracaula']);
        $pau   = Persona::create(['nom' => 'Pau', 'cognoms' => 'Peracaula']);

        $compte = $this->compteAmbSaldo([$marta->id, $pau->id], 1000);
        // Dos immobles amb quotes diferents al mateix compte: no hi ha resposta única
        $this->immobleAmbLloguer($compte, [[$marta->id, 66.67, '2021-01-01', null], [$pau->id, 33.33, '2021-01-01', null]]);
        $this->immobleAmbLloguer($compte, [[$marta->id, 25, '2021-01-01', null], [$pau->id, 75, '2021-01-01', null]]);

        $this->assertSame([500.0, 500.0], array_column($this->posicio()['parts'], 'part'));
    }

    public function test_el_formulari_desa_diversos_trams_per_persona(): void
    {
        $marta = Persona::create(['nom' => 'Marta', 'cognoms' => 'Peracaula']);
        $pau   = Persona::create(['nom' => 'Pau', 'cognoms' => 'Peracaula']);

        $this->actingAs(User::factory()->create())->post(route('immobles.store'), [
            'referencia_cadastral'  => 'REF-TRAMS',
            'adreca'                => 'Carrer de prova',
            'propietari_ids'        => [$marta->id, $pau->id, $marta->id, $pau->id],
            'propietari_data_inici' => ['2021-01-01', '2021-01-01', '2024-01-01', '2024-01-01'],
            'propietari_data_fi'    => ['2023-12-31', '2023-12-31', null, null],
            'propietari_quota'      => [50, 50, 66.67, 33.33],
        ])->assertRedirect();

        $immoble = Immoble::where('referencia_cadastral', 'REF-TRAMS')->firstOrFail();

        // Quatre files: la mateixa persona hi consta dues vegades, amb trams diferents
        $this->assertCount(4, $immoble->propietaris()->get());
        $this->assertSame(
            [50.0, 66.67],
            $immoble->propietaris()->where('persona_id', $marta->id)->get()
                ->pluck('pivot.quota')->map(fn ($q) => (float) $q)->sort()->values()->all(),
        );
    }
}
