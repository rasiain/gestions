<?php

namespace Tests\Feature;

use App\Models\SortidaBici;
use App\Models\Vehicle;
use App\Services\XarxaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BdRecorreguts;
use Tests\TestCase;

/** La xarxa de camins que es construeix amb les sortides, sobre recorreguts fets a mà prop de Girona. */
class XarxaTest extends TestCase
{
    use BdRecorreguts;
    use RefreshDatabase;

    private const LAT = 41.98;

    private const LON = 2.82;

    /** Metres cap a l'est i cap al nord des de (LAT, LON), en graus. */
    private function punt(float $est, float $nord): array
    {
        return [self::LAT + $nord / 111320, self::LON + $est / (111320 * cos(deg2rad(self::LAT)))];
    }

    /** Una sortida amb un punt cada 5 m entre dos extrems (metres des de l'origen). */
    private function sortida(string $modalitat, array $de, array $a, string $id): void
    {
        $bici = Vehicle::firstOrCreate(['nom' => "Bici {$modalitat}"], ['tipus' => 'bici', 'modalitat' => $modalitat]);
        $s = SortidaBici::create([
            'vehicle_id' => $bici->id, 'origen' => 'garmin', 'id_extern' => $id, 'tipus_activitat' => 'cycling',
            'inici' => '2026-05-01 08:00:00', 'data_local' => '2026-05-01', 'punts' => 1,
        ]);
        $llarg = hypot($a[0] - $de[0], $a[1] - $de[1]);
        $semicercle = 2 ** 31 / 180;
        for ($t = 0; $t * 5 <= $llarg; $t++) {
            $f = $t * 5 / $llarg;
            [$lat, $lon] = $this->punt($de[0] + ($a[0] - $de[0]) * $f, $de[1] + ($a[1] - $de[1]) * $f);
            DB::connection('recorreguts')->table('punts')->insert([
                'sortida_id' => $s->id, 't' => $t, 'lat' => (int) round($lat * $semicercle), 'lon' => (int) round($lon * $semicercle),
                'alt' => 10000, 'dist' => $t * 500,
            ]);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparaBdRecorreguts();
    }

    public function test_dues_passades_pel_mateix_cami_fan_un_sol_cami(): void
    {
        // Un quilòmetre cap a l'est, i la segona passada 8 m més al nord (l'error del GPS)
        $this->sortida('btt', [0, 0], [1000, 0], '1');
        $this->sortida('btt', [0, 8], [1000, 8], '2');

        $trams = app(XarxaService::class)->construeix()['trams'];

        $this->assertEqualsWithDelta(1000, array_sum(array_column($trams, 'longitud_m')), 30);
        $this->assertCount(1, $trams);
        $this->assertSame(2, $trams[0]['passades']);
    }

    public function test_un_recorregut_que_en_creua_un_altre_hi_fa_una_cruilla(): void
    {
        $this->sortida('btt', [0, 0], [1000, 0], '1');
        $this->sortida('urbana', [500, -500], [500, 500], '2');

        $trams = app(XarxaService::class)->construeix()['trams'];

        // Quatre braços des de la cruïlla del mig
        $this->assertCount(4, $trams);
        $extrems = array_merge(...array_map(fn ($t) => [$t['nodes'][0], end($t['nodes'])], $trams));
        $this->assertSame(4, max(array_count_values($extrems)), 'els quatre trams comparteixen un node');

        // Els braços nord-sud són de la bici urbana: aptes per a carretera; els est-oest, no
        $aptes = array_filter($trams, fn ($t) => $t['carretera']);
        $this->assertCount(2, $aptes);
        $this->assertEqualsWithDelta(1000, array_sum(array_column($aptes, 'longitud_m')), 30);
    }
}
