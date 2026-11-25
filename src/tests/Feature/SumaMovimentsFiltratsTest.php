<?php

namespace Tests\Feature;

use App\Models\CompteCorrent;
use App\Models\MovimentCompteCorrent;
use App\Models\MovimentConcepte;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** La suma de sota la taula de moviments: de tot el que passa el filtre, no només de la pàgina. */
class SumaMovimentsFiltratsTest extends TestCase
{
    use RefreshDatabase;

    private CompteCorrent $compte;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->compte = CompteCorrent::create(['compte_corrent' => str_repeat('1', 24), 'nom' => 'Compte', 'ordre' => 0, 'tipus' => 'corrent']);
        $concepte = MovimentConcepte::firstOrCreate(['concepte' => 'SUPER'])->id;

        // 60 despeses de 10 € al novembre (més d'una pàgina) i un ingrés de 1.000 € a l'octubre
        foreach (range(1, 60) as $i) {
            MovimentCompteCorrent::create([
                'data_moviment' => '2026-11-'.str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT),
                'concepte_id' => $concepte, 'concepte_original' => 'SUPER', 'import' => -10,
                'hash' => hash('sha256', "m$i"), 'compte_corrent_id' => $this->compte->id,
            ]);
        }
        MovimentCompteCorrent::create([
            'data_moviment' => '2026-10-01', 'concepte_id' => $concepte, 'concepte_original' => 'NOMINA', 'import' => 1000,
            'hash' => hash('sha256', 'nomina'), 'compte_corrent_id' => $this->compte->id,
        ]);
    }

    public function test_sense_filtre_no_hi_ha_suma(): void
    {
        $this->get(route('moviments.index', ['compte_corrent_id' => $this->compte->id]))->assertOk()
            ->assertInertia(fn ($p) => $p->where('stats.suma_filtrada', null));
    }

    public function test_la_suma_es_de_totes_les_pagines(): void
    {
        $this->get(route('moviments.index', ['compte_corrent_id' => $this->compte->id, 'data_inici' => '2026-11-01']))->assertOk()
            ->assertInertia(fn ($p) => $p->where('moviments.total', 60)
                ->where('stats.suma_filtrada.moviments', 60)
                ->where('stats.suma_filtrada.despeses', -600)
                ->where('stats.suma_filtrada.ingressos', 0)
                ->where('stats.suma_filtrada.total', -600));
    }

    public function test_separa_ingressos_i_despeses(): void
    {
        $this->get(route('moviments.index', ['compte_corrent_id' => $this->compte->id, 'data_fi' => '2026-12-31']))->assertOk()
            ->assertInertia(fn ($p) => $p->where('stats.suma_filtrada.moviments', 61)
                ->where('stats.suma_filtrada.ingressos', 1000)
                ->where('stats.suma_filtrada.despeses', -600)
                ->where('stats.suma_filtrada.total', 400));
    }
}
