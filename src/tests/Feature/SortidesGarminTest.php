<?php

namespace Tests\Feature;

use App\Models\MaterialEsportiu;
use App\Models\SortidaBici;
use App\Models\Vehicle;
use App\Services\SortidesGarminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Les sortides de l'exportació de Garmin: de quina bici són, el resum en SI i els punts
 * originals a la BD de recorreguts. Els FIT els llegeix scripts/llegeix-fit.mjs; aquí se'n
 * simula la sortida.
 */
class SortidesGarminTest extends TestCase
{
    use RefreshDatabase;

    private Vehicle $giant;

    private MaterialEsportiu $bici;

    private MaterialEsportiu $cadena;

    protected function setUp(): void
    {
        parent::setUp();

        // RefreshDatabase només conserva la connexió principal: la BD de recorreguts (també en
        // memòria) arriba buida, i la seva taula es crea amb la mateixa migració
        if (! DB::connection('recorreguts')->getSchemaBuilder()->hasTable('punts')) {
            (require database_path('migrations/2026_10_20_000004_create_punts_recorreguts.php'))->up();
        }
        DB::connection('recorreguts')->table('punts')->delete();

        $this->giant = Vehicle::create(['nom' => 'Giant Anthem 2', 'tipus' => 'bici']);
        $material = fn (string $id, string $tipus, ?int $vehicle) => MaterialEsportiu::create([
            'origen' => 'garmin', 'id_extern' => $id, 'tipus' => $tipus, 'nom' => $id, 'estat' => 'actiu', 'vehicle_id' => $vehicle,
        ]);
        $this->bici = $material('100', 'bici', $this->giant->id);
        $this->cadena = $material('200', 'component', null);
        $material('300', 'sabates', null);
    }

    /** Una activitat del resum de Garmin: cm, ms, cm/ms i kJ. */
    private function activitat(int $id, array $mes = []): array
    {
        return $mes + [
            'activityId'      => $id,
            'name'            => 'Girona Mountain Biking',
            'activityType'    => 'mountain_biking',
            'startTimeGmt'    => 1760252400000.0,   // 2025-10-12 07:00 UTC
            'startTimeLocal'  => 1760259600000.0,   // 09:00 local
            'distance'        => 4523000.0,         // 45,23 km
            'duration'        => 9000000.0,
            'movingDuration'  => 8400000.0,
            'elapsedDuration' => 9600000.0,
            'elevationGain'   => 98000.0,           // 980 m
            'elevationLoss'   => 97500.0,
            'avgSpeed'        => 0.5025,            // 5,025 m/s
            'maxSpeed'        => 1.5,
            'avgHr'           => 141.0,
            'calories'        => 4184.0,            // 1.000 kcal
            'elevationCorrected' => true,
        ];
    }

    private function gear(array $parells): array
    {
        $dto = [];
        foreach ($parells as [$gearPk, $activityId]) {
            $dto[$gearPk][] = ['gearPk' => $gearPk, 'activityId' => $activityId];
        }

        return ['gearActivityDTOs' => $dto];
    }

    public function test_tria_les_activitats_d_una_bici_amb_tot_el_seu_material(): void
    {
        $sortides = app(SortidesGarminService::class)->sortidesDeBici(
            [$this->activitat(1), $this->activitat(2, ['activityType' => 'running'])],
            $this->gear([[100, 1], [200, 1], [300, 2]]),
        );

        $this->assertSame([1], array_keys($sortides));
        $this->assertSame($this->giant->id, $sortides[1]['vehicle_id']);
        $this->assertEqualsCanonicalizing([$this->bici->id, $this->cadena->id], $sortides[1]['material']);
    }

    public function test_el_resum_passa_a_si_i_les_calories_de_kj_a_kcal(): void
    {
        $r = app(SortidesGarminService::class)->resum($this->activitat(1));

        $this->assertSame(45230.0, $r['distancia_m']);
        $this->assertSame(9000, $r['temps_s']);
        $this->assertSame(8400, $r['temps_moviment_s']);
        $this->assertSame(9600, $r['temps_total_s']);
        $this->assertSame(980.0, $r['desnivell_pos_m']);
        $this->assertSame(5.025, $r['vel_mitjana']);
        $this->assertSame(1000, $r['calories']);
        $this->assertSame('2025-10-12', $r['data_local']);
        $this->assertSame('2025-10-12 07:00:00', $r['inici']->format('Y-m-d H:i:s'));
        $this->assertTrue($r['desnivell_corregit']);
    }

    private function fit(): array
    {
        return [
            'fitxer'      => 'x.fit',
            'inici'       => 1760252400,
            'sessio'      => ['totalDistance' => 45100.0, 'totalAscent' => 950],
            'punts'       => [
                [0, 500871641, 33643561, 11820, 0, 5813, 116, 74, 25],
                [1, 500872038, 33643564, 11820, 562, 5626, 117, 72, 25],
                [1, 1, 1, 1, 1, 1, 1, 1, 1],                          // mateix segon: fora
                [3, null, null, 11840, 1127, 5654, 118, 74, 25],      // sense GPS: es desa igual
            ],
            'rectangle'   => ['lat_min' => 41.98, 'lat_max' => 42.01, 'lon_min' => 2.81, 'lon_max' => 2.84],
            'simplificat' => '_p~iF~ps|U',
        ];
    }

    public function test_desa_la_sortida_el_material_i_els_punts_originals(): void
    {
        $servei = app(SortidesGarminService::class);
        $sortida = $servei->sortidesDeBici([$this->activitat(1)], $this->gear([[100, 1], [200, 1]]))[1];

        $model = $servei->desa($sortida, $this->fit());

        $this->assertSame('1', $model->id_extern);
        $this->assertSame(3, $model->punts);
        $this->assertSame(950, $model->resum_dispositiu['totalAscent']);
        $this->assertSame('_p~iF~ps|U', $model->recorregut_simplificat);
        $this->assertEquals(41.98, $model->lat_min);
        $this->assertCount(2, $model->material);

        $punts = $model->puntsRecorregut()->get();
        $this->assertSame([0, 1, 3], $punts->pluck('t')->all());
        // Els valors del dispositiu, sense tocar: semicercles, cm, mm/s
        $this->assertSame(500871641, (int) $punts[0]->lat);
        $this->assertSame(11820, (int) $punts[0]->alt);
        $this->assertNull($punts[2]->lat);
    }

    public function test_tornar_a_importar_actualitza_el_resum_i_no_toca_els_punts(): void
    {
        $servei = app(SortidesGarminService::class);
        $sortida = $servei->sortidesDeBici([$this->activitat(1)], $this->gear([[100, 1]]))[1];
        $servei->desa($sortida, $this->fit());

        $sortida['activitat']['name'] = 'Reanomenada';
        $fit = $this->fit();
        $fit['punts'][0][1] = 999;
        $servei->desa($sortida, $fit);

        $this->assertSame(1, SortidaBici::count());
        $this->assertSame('Reanomenada', SortidaBici::first()->nom);
        $this->assertSame(3, DB::connection('recorreguts')->table('punts')->count());
        $this->assertSame(500871641, (int) DB::connection('recorreguts')->table('punts')->where('t', 0)->value('lat'));
    }

    public function test_una_sortida_sense_fit_es_desa_amb_el_resum_i_sense_punts(): void
    {
        $servei = app(SortidesGarminService::class);
        $sortida = $servei->sortidesDeBici([$this->activitat(1)], $this->gear([[100, 1]]))[1];

        $model = $servei->desa($sortida, null);

        $this->assertSame(0, $model->punts);
        $this->assertNull($model->fitxer);
        $this->assertSame(45230.0, (float) $model->distancia_m);
    }

    public function test_la_fitxa_resumeix_per_any_i_compta_els_km_dels_components(): void
    {
        $servei = app(SortidesGarminService::class);
        $gear = $this->gear([[100, 1], [200, 1], [100, 2], [100, 3]]);
        $sortides = $servei->sortidesDeBici([
            $this->activitat(1),
            // Dins de les dates de la cadena però sense marcar-la
            $this->activitat(2, ['startTimeGmt' => 1760338800000.0, 'startTimeLocal' => 1760346000000.0]),
            $this->activitat(3, ['activityType' => 'indoor_cycling', 'distance' => 1000000.0, 'startTimeGmt' => 1728716400000.0, 'startTimeLocal' => 1728723600000.0]),
        ], $gear);
        foreach ($sortides as $s) {
            $servei->desa($s, null);
        }
        $this->cadena->update(['data_inici' => '2025-01-01']);

        $fitxa = $this->actingAs(\App\Models\User::factory()->create())
            ->getJson(route('bicis.fitxa', $this->giant))
            ->assertOk()
            ->json();

        $this->assertSame([2025, 2024], array_column($fitxa['per_any'], 'any'));
        $this->assertEquals(90.5, $fitxa['per_any'][0]['km']);
        $this->assertEquals(10.0, $fitxa['per_any'][1]['km_indoor']);

        $cadena = $fitxa['components'][0];
        $this->assertSame(1, $cadena['sortides']);
        $this->assertEquals(45, $cadena['km']);
        // La segona sortida és dins de les dates i no es va marcar; la de rodets no compta
        $this->assertSame(2, $cadena['sortides_en_dates']);

        $llista = $this->getJson(route('bicis.sortides', $this->giant) . '?any=2025')->assertOk()->json();
        $this->assertCount(2, $llista);
        $this->assertSame('09:00', $llista[1]['hora']);
        $this->assertEquals(18.1, $llista[0]['kmh']);
    }
}
