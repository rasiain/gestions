<?php

namespace Tests\Feature;

use App\Models\Preferencia;
use App\Models\SortidaBici;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\AltitudTerrenyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BdRecorreguts;
use Tests\TestCase;

/** Com es mira l'altitud d'una sortida: els perfils que s'envien i les preferències que es desen. */
class AltitudPreferenciaTest extends TestCase
{
    use BdRecorreguts;
    use RefreshDatabase;

    private SortidaBici $sortida;

    protected function setUp(): void
    {
        parent::setUp();

        $this->preparaBdRecorreguts();
        $recorreguts = DB::connection('recorreguts');

        $bici = Vehicle::create(['nom' => 'Giant', 'tipus' => 'bici']);
        $this->sortida = SortidaBici::create([
            'vehicle_id' => $bici->id, 'origen' => 'garmin', 'id_extern' => '1', 'tipus_activitat' => 'cycling',
            'inici' => '2026-10-22 08:00:00', 'data_local' => '2026-10-22', 'punts' => 50,
        ]);

        // Un pla a 70 m, amb el dispositiu desplaçat 20 m per sota: només l'ICGC el cobreix
        for ($t = 0; $t < 50; $t++) {
            $recorreguts->table('punts')->insert(['sortida_id' => $this->sortida->id, 't' => $t, 'lat' => 1, 'lon' => 1, 'alt' => 5000, 'dist' => $t * 500]);
            $recorreguts->table('altituds_terreny')->insert(['sortida_id' => $this->sortida->id, 'font' => 'icgc', 't' => $t, 'alt' => 7000]);
        }
        $recorreguts->table('altituds_terreny_sortides')->insert([
            ['sortida_id' => $this->sortida->id, 'font' => 'icgc', 'punts' => 50, 'sense_dades' => 0, 'calculat_at' => now()],
            ['sortida_id' => $this->sortida->id, 'font' => 'ign', 'punts' => 50, 'sense_dades' => 50, 'calculat_at' => now()],
        ]);
        app(AltitudTerrenyService::class)->desaDesnivells($this->sortida->id);

        $this->actingAs(User::factory()->create());
    }

    public function test_la_pagina_porta_les_fonts_que_cobreixen_la_sortida_i_els_desnivells(): void
    {
        $this->get(route('bicis.sortida', $this->sortida))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('altitud.fonts', ['icgc'])
            ->where('altitud.defecte', AltitudTerrenyService::PER_DEFECTE)
            ->where('altitud.sortida', null)
            ->where('altitud.llegida', true)
            ->has('altitud.desnivells', 3)   // dispositiu, terreny/icgc, combinat/icgc
            ->etc());
    }

    public function test_una_sortida_encara_no_llegida_no_es_confon_amb_una_que_no_es_cobreix(): void
    {
        DB::connection('recorreguts')->table('altituds_terreny_sortides')->where('sortida_id', $this->sortida->id)->delete();

        $this->get(route('bicis.sortida', $this->sortida))->assertInertia(fn (AssertableInertia $p) => $p
            ->where('altitud.fonts', [])
            ->where('altitud.llegida', false)
            ->etc());
    }

    public function test_els_punts_porten_els_perfils_del_terreny_i_combinats(): void
    {
        $punts = $this->getJson(route('bicis.sortida.punts', $this->sortida))->assertOk()->json();

        $this->assertSame(['terreny/icgc', 'combinat/icgc'], array_keys($punts['altituds']));
        $this->assertEquals(50, $punts['alt'][10]);
        // El combinat porta el dispositiu al nivell del model
        $this->assertEquals(70, $punts['altituds']['combinat/icgc'][10]);
    }

    public function test_es_desa_la_preferencia_d_una_sortida_i_es_pot_treure(): void
    {
        $this->put(route('bicis.sortida.altitud', $this->sortida), ['metode' => 'combinat', 'font' => 'icgc'])->assertRedirect();
        $this->assertSame(['combinat', 'icgc'], [$this->sortida->fresh()->altitud_metode, $this->sortida->fresh()->altitud_font]);

        $this->put(route('bicis.sortida.altitud', $this->sortida), ['metode' => null])->assertRedirect();
        $this->assertNull($this->sortida->fresh()->altitud_metode);
        $this->assertNull($this->sortida->fresh()->altitud_font);
    }

    public function test_es_desa_la_preferencia_per_defecte(): void
    {
        $this->put(route('bicis.altitud-defecte'), ['metode' => 'combinat', 'font' => 'ign'])->assertRedirect();
        $this->assertSame(['metode' => 'combinat', 'font' => 'ign'], Preferencia::valor(AltitudTerrenyService::CLAU_PREFERENCIA));

        $this->put(route('bicis.altitud-defecte'), ['metode' => 'pla'])->assertSessionHasErrors('metode');
        $this->put(route('bicis.altitud-defecte'), [])->assertSessionHasErrors('metode');
    }
}
