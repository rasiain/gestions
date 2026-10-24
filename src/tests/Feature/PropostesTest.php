<?php

namespace Tests\Feature;

use App\Models\SortidaBici;
use App\Models\Vehicle;
use App\Services\PropostesService;
use App\Services\XarxaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BdRecorreguts;
use Tests\TestCase;

/** Les propostes de sortida sobre una xarxa feta a mà: un quadrat d'1 km de costat prop de Girona. */
class PropostesTest extends TestCase
{
    use BdRecorreguts;
    use RefreshDatabase;

    private const LAT = 41.98;

    private const LON = 2.82;

    private function graus(float $est, float $nord): array
    {
        return [self::LAT + $nord / 111320, self::LON + $est / (111320 * cos(deg2rad(self::LAT)))];
    }

    /** Una sortida que ressegueix uns vèrtexs (metres des de l'origen), amb un punt cada 5 m. */
    private function sortida(string $modalitat, array $vertexs, string $id): void
    {
        $bici = Vehicle::firstOrCreate(['nom' => "Bici {$modalitat}"], ['tipus' => 'bici', 'modalitat' => $modalitat]);
        $s = SortidaBici::create([
            'vehicle_id' => $bici->id, 'origen' => 'garmin', 'id_extern' => $id, 'tipus_activitat' => 'cycling',
            'inici' => '2026-05-01 08:00:00', 'data_local' => '2026-05-01', 'punts' => 1,
        ]);
        $semicercle = 2 ** 31 / 180;
        $t = 0;
        for ($k = 1; $k < count($vertexs); $k++) {
            [$de, $a] = [$vertexs[$k - 1], $vertexs[$k]];
            $llarg = hypot($a[0] - $de[0], $a[1] - $de[1]);
            // L'últim vèrtex també, perquè una volta tanqui on comença
            $final = $k === count($vertexs) - 1;
            for ($m = 0; $m < $llarg || ($final && $m <= $llarg); $m += 5) {
                $f = $m / $llarg;
                [$lat, $lon] = $this->graus($de[0] + ($a[0] - $de[0]) * $f, $de[1] + ($a[1] - $de[1]) * $f);
                DB::connection('recorreguts')->table('punts')->insert([
                    'sortida_id' => $s->id, 't' => $t++, 'lat' => (int) round($lat * $semicercle), 'lon' => (int) round($lon * $semicercle),
                    'alt' => 10000, 'dist' => $t * 500,
                ]);
            }
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparaBdRecorreguts();

        // Un quadrat d'1 km de costat amb la BTT, i un carrer que el travessa pel mig amb la urbana
        $this->sortida('btt', [[0, 0], [1000, 0], [1000, 1000], [0, 1000], [0, 0]], '1');
        $this->sortida('urbana', [[0, 500], [1000, 500]], '2');
        $xarxa = app(XarxaService::class);
        $xarxa->desa($xarxa->construeix()['trams']);
    }

    public function test_proposa_voltes_que_tornen_a_l_inici(): void
    {
        [$lat, $lon] = $this->graus(0, 0);
        $r = app(PropostesService::class)->proposa($lat, $lon, 4, 0);

        $this->assertNotEmpty($r['propostes']);
        $millor = $r['propostes'][0];
        $this->assertEqualsWithDelta(4, $millor['km'], 0.2, 'la volta del quadrat sencer');
        $this->assertSame(0.0, $millor['km_repetits']);
        $this->assertEquals($millor['punts'][0], end($millor['punts']), 'acaba on comença');
    }

    public function test_a_carretera_nomes_hi_ha_els_trams_aptes(): void
    {
        [$lat, $lon] = $this->graus(0, 500);
        $r = app(PropostesService::class)->proposa($lat, $lon, 2, 0, 'carretera');

        // Només el carrer del mig: l'única volta és anar i tornar per ell
        foreach ($r['propostes'] as $p) {
            $this->assertEqualsWithDelta($p['km'], 2 * $p['km_repetits'], 0.1);
        }
    }

    public function test_la_pantalla_porta_casa_i_la_cerca_les_propostes(): void
    {
        $this->actingAs(\App\Models\User::factory()->create());
        $this->get(route('bicis.propostes.index'))->assertInertia(fn ($p) => $p->component('Bicis/Propostes')
            ->where('casa', ['lat' => PropostesService::CASA[0], 'lon' => PropostesService::CASA[1]]));

        [$lat, $lon] = $this->graus(0, 0);
        $r = $this->getJson(route('bicis.propostes.cerca', ['km' => 4, 'desnivell' => 0, 'tipus' => 'btt', 'lat' => $lat, 'lon' => $lon]))
            ->assertOk()->json();
        $this->assertNotEmpty($r['propostes']);
        $sortides = $r['propostes'][0]['sortides'];
        $this->assertSame(['id', 'km', 'nom', 'data', 'bici'], array_keys($sortides[0]));
        $this->assertSame('Bici btt', collect($sortides)->firstWhere('km', '>', 1)['bici']);
    }

    public function test_la_cerca_diu_que_falta(): void
    {
        $this->actingAs(\App\Models\User::factory()->create());
        $this->getJson(route('bicis.propostes.cerca', ['tipus' => 'muntanya']))
            ->assertStatus(422)->assertJsonValidationErrors(['km', 'desnivell', 'tipus', 'lat', 'lon']);
    }

    public function test_el_gpx_porta_el_tracat_amb_l_altitud(): void
    {
        [$lat, $lon] = $this->graus(0, 0);
        $gpx = PropostesService::gpx(app(PropostesService::class)->proposa($lat, $lon, 4, 0)['propostes'][0], 'Prova & co');

        $xml = simplexml_load_string($gpx);
        $this->assertSame('Prova & co', (string) $xml->trk->name);
        $this->assertGreaterThan(100, count($xml->trk->trkseg->trkpt));
        $this->assertSame('100.0', (string) $xml->trk->trkseg->trkpt[0]->ele);
    }
}
