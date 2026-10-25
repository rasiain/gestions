<?php

namespace Tests\Feature;

use App\Models\SortidaBici;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** La fitxa de totes les bicis juntes: el resum de cada una, sumat. */
class FitxaTotesTest extends TestCase
{
    use RefreshDatabase;

    private function sortida(Vehicle $bici, string $id, string $data, float $km): void
    {
        SortidaBici::create([
            'vehicle_id' => $bici->id, 'origen' => 'garmin', 'id_extern' => $id, 'tipus_activitat' => 'cycling',
            'inici' => "{$data} 08:00:00", 'data_local' => $data, 'distancia_m' => $km * 1000, 'desnivell_pos_m' => 100, 'calories' => (int) ($km * 30),
        ]);
    }

    public function test_suma_les_bicis_i_en_dona_els_km_de_cadascuna(): void
    {
        $giant = Vehicle::create(['nom' => 'Giant', 'tipus' => 'bici']);
        $albert = Vehicle::create(['nom' => 'Albert', 'tipus' => 'bici']);
        $cotxe = Vehicle::create(['nom' => 'Cotxe', 'tipus' => 'cotxe']);
        $this->sortida($giant, '1', '2026-05-01', 40);
        $this->sortida($giant, '2', '2025-05-01', 30);
        $this->sortida($albert, '3', '2026-06-01', 5);
        $this->sortida($cotxe, '4', '2026-06-01', 999);   // no és cap bici: no hi entra
        $this->actingAs(User::factory()->create());

        $fitxa = $this->getJson(route('bicis.totes.fitxa'))->assertOk()->json();

        $any2026 = collect($fitxa['per_any'])->firstWhere('any', 2026);
        $this->assertSame(2, $any2026['sortides']);
        $this->assertEquals(45, $any2026['km']);
        $this->assertSame(200, $any2026['desnivell']);
        $this->assertSame(1350, $any2026['calories']);   // (40 + 5) km × 30 kcal
        $this->assertSame([], $fitxa['components']);
        $this->assertSame(['Giant', 'Albert'], array_column($fitxa['bicis'], 'nom'));
        $this->assertEquals(5, collect($fitxa['per_bici'])->where('any', 2026)->firstWhere('vehicle_id', $albert->id)['km']);
        $this->assertSame(150, collect($fitxa['per_bici'])->where('any', 2026)->firstWhere('vehicle_id', $albert->id)['calories']);
        $this->assertEquals(150, collect($fitxa['per_mes'])->firstWhere('mes', '2026-06')['calories']);
        $this->assertSame(0, $any2026['calories_indoor']);

        $sortides = $this->getJson(route('bicis.totes.sortides', ['any' => 2026]))->assertOk()->json();
        $this->assertCount(2, $sortides);
        $this->assertEqualsCanonicalizing(['Giant', 'Albert'], array_column($sortides, 'bici'));
        $this->assertEqualsCanonicalizing([1200, 150], array_column($sortides, 'calories'));
    }
}
