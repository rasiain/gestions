<?php

namespace Tests\Feature;

use App\Models\Ruta;
use App\Models\SortidaBici;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Polilinia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** El catàleg de rutes i el detall d'una ruta, amb dades fetes a mà (sense `rutes:calcula`). */
class RutesPantallesTest extends TestCase
{
    use RefreshDatabase;

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
}
