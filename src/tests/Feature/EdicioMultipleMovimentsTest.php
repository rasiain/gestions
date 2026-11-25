<?php

namespace Tests\Feature;

use App\Models\CompteCorrent;
use App\Models\MovimentCompteCorrent;
use App\Models\MovimentConcepte;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** L'edició de diversos moviments alhora (botó «Edita seleccionats» de Moviments). */
class EdicioMultipleMovimentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_canvia_el_concepte_de_tots_els_seleccionats(): void
    {
        $this->actingAs(User::factory()->create());
        $compte = CompteCorrent::create(['compte_corrent' => str_repeat('1', 24), 'nom' => 'Compte', 'ordre' => 0, 'tipus' => 'corrent']);
        $vell = MovimentConcepte::firstOrCreate(['concepte' => 'VELL'])->id;
        $ids = collect([false, true])->map(fn ($exclou, $i) => MovimentCompteCorrent::create([
            'data_moviment' => '2026-11-10', 'concepte_id' => $vell, 'concepte_original' => 'VELL',
            'import' => -10, 'hash' => hash('sha256', "m$i"), 'compte_corrent_id' => $compte->id,
            'exclou_lloguer' => $exclou,
        ])->id)->all();

        $this->postJson(route('moviments.bulk-edit'), ['moviment_ids' => $ids, 'concepte' => 'NOU'])
            ->assertOk()->assertJson(['updated' => 2]);

        foreach ($ids as $id) {
            $this->assertSame('NOU', MovimentCompteCorrent::find($id)->concepte->concepte);
            $this->assertSame('VELL', MovimentCompteCorrent::find($id)->concepte_original);
        }
    }
}
