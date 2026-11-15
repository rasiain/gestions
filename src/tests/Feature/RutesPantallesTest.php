<?php

namespace Tests\Feature;

use App\Models\Ruta;
use App\Models\SortidaBici;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Polilinia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BdRecorreguts;
use Tests\TestCase;

/** El catàleg de rutes i el detall d'una ruta, amb dades fetes a mà (sense `rutes:calcula`). */
class RutesPantallesTest extends TestCase
{
    use BdRecorreguts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparaBdRecorreguts();
    }

    private function sortida(Vehicle $bici, string $data, float $km): SortidaBici
    {
        return SortidaBici::create([
            'vehicle_id' => $bici->id, 'origen' => 'garmin', 'id_extern' => uniqid(), 'nom' => 'Volta',
            'tipus_activitat' => 'mountain_biking', 'inici' => "{$data} 08:00:00", 'data_local' => $data,
            'distancia_m' => $km * 1000, 'desnivell_pos_m' => 600,
            'recorregut_simplificat' => Polilinia::codifica([[41.98, 2.82], [41.99, 2.83], [42.0, 2.82]]),
            'dades_originals' => ['locationName' => 'Girona'],
        ]);
    }

    public function test_el_cataleg_porta_les_rutes_de_tots_els_nivells_i_el_detall_les_seves_sortides(): void
    {
        $giant = Vehicle::create(['nom' => 'Giant', 'tipus' => 'bici']);
        $lapierre = Vehicle::create(['nom' => 'Lapierre', 'tipus' => 'bici']);
        $a = $this->sortida($giant, '2025-03-01', 40);
        $b = $this->sortida($lapierre, '2026-05-01', 41);

        $mateixa = Ruta::create(['nivell' => 'mateixa', 'canonica_id' => $a->id, 'sortides' => 2]);
        $zona = Ruta::create(['nivell' => 'zona', 'canonica_id' => $a->id, 'sortides' => 2]);
        foreach ([[$a, 1], [$b, 0.86]] as [$s, $v]) {
            DB::table('g_sortida_ruta')->insert([
                ['sortida_id' => $s->id, 'nivell' => 'mateixa', 'ruta_id' => $mateixa->id, 'semblanca' => $v],
                ['sortida_id' => $s->id, 'nivell' => 'zona', 'ruta_id' => $zona->id, 'semblanca' => $v],
            ]);
        }

        $this->actingAs(User::factory()->create())
            ->get(route('bicis.rutes.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Bicis/Rutes')
                ->has('rutes', 2)
                ->where('rutes.0.nom', 'Girona · 40 km · 600 m')
                ->where('rutes.0.darrera', '2026-05-01')
                ->where('rutes.0.bicis', [$giant->id, $lapierre->id])
                ->has("tracats.{$a->id}"));

        $this->get(route('bicis.rutes.show', $mateixa))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Bicis/Ruta')
                ->where('ruta.nivell', 'mateixa')
                ->has('membres', 2)
                ->where('membres.0.id', $b->id)            // la més recent primer
                ->where('membres.0.semblanca', 0.86)
                ->where('membres.1.canonica', true)
                ->has('altres_nivells', 2));
    }

    public function test_la_pagina_de_la_sortida_diu_a_quina_ruta_pertany_a_cada_nivell(): void
    {
        $giant = Vehicle::create(['nom' => 'Giant', 'tipus' => 'bici']);
        $a = $this->sortida($giant, '2025-03-01', 40);
        $b = $this->sortida($giant, '2026-05-01', 41);
        SortidaBici::whereIn('id', [$a->id, $b->id])->update(['punts' => 100]);

        $zona = Ruta::create(['nivell' => 'zona', 'canonica_id' => $a->id, 'sortides' => 2]);
        $mateixa = Ruta::create(['nivell' => 'mateixa', 'canonica_id' => $b->id, 'sortides' => 1]);
        DB::table('g_sortida_ruta')->insert([
            ['sortida_id' => $b->id, 'nivell' => 'zona', 'ruta_id' => $zona->id, 'semblanca' => 0.72],
            ['sortida_id' => $b->id, 'nivell' => 'mateixa', 'ruta_id' => $mateixa->id, 'semblanca' => 1],
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('bicis.sortida', $b))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Bicis/Sortida')
                ->has('rutes.nivells', 2)
                ->where('rutes.nivells.0.nivell', 'mateixa')       // de més estricte a menys
                ->where('rutes.nivells.0.representant', true)
                ->where('rutes.nivells.1.id', $zona->id)
                ->where('rutes.nivells.1.semblanca', 0.72)
                ->where('rutes.nivells.1.representant', false)
                ->where('rutes.pendent', false));

        // Una d'importada després del darrer càlcul: pendent
        $nova = $this->sortida($giant, '2026-11-15', 30);
        $nova->forceFill(['punts' => 100, 'updated_at' => now()->addMinute()])->save();
        $this->get(route('bicis.sortida', $nova))
            ->assertInertia(fn ($p) => $p->has('rutes.nivells', 0)->where('rutes.pendent', true));

        // Els rodets no en tenen
        $rodets = $this->sortida($giant, '2026-11-14', 20);
        $rodets->forceFill(['tipus_activitat' => 'indoor_cycling'])->save();
        $this->get(route('bicis.sortida', $rodets))->assertInertia(fn ($p) => $p->where('rutes', null));
    }
}
