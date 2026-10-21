<?php

namespace Tests\Feature;

use App\Models\MaterialEsportiu;
use App\Models\SortidaBici;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\ImportacioRecorregutsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Els FIT baixats de Garmin Connect: completen sortides sense recorregut, no toquen les que en
 * tenen i, amb una bici triada, creen les que falten. Els FIT se simulen (la sortida de
 * scripts/llegeix-fit.mjs); el lector es prova amb fitxers reals a mà.
 */
class ImportacioRecorregutsTest extends TestCase
{
    use RefreshDatabase;

    private Vehicle $giant;

    private ImportacioRecorregutsService $servei;

    protected function setUp(): void
    {
        parent::setUp();
        if (! DB::connection('recorreguts')->getSchemaBuilder()->hasTable('punts')) {
            (require database_path('migrations/2026_10_20_000004_create_punts_recorreguts.php'))->up();
        }
        DB::connection('recorreguts')->table('punts')->delete();

        $this->giant = Vehicle::create(['nom' => 'Giant', 'tipus' => 'bici']);
        $this->servei = app(ImportacioRecorregutsService::class);
    }

    private function fit(string $fitxer, int $inici = 1682658909, string $sport = 'cycling'): array
    {
        return [
            'fitxer'       => $fitxer,
            'inici'        => $inici,
            'desfas_local' => 7200,
            'sessio'       => [
                'sport' => $sport, 'subSport' => 'mountain', 'totalDistance' => 75300.0, 'totalTimerTime' => 24144.2,
                'totalElapsedTime' => 29070.4, 'totalAscent' => 1150, 'avgHeartRate' => 120, 'totalCalories' => 2100,
                'startPositionLat' => 469624471, 'startPositionLong' => 34816451, 'enhancedAvgSpeed' => 3.1,
            ],
            'punts'        => [[0, 469624471, 34816451, 1000, 0, 3000, 110, 70, 18], [5, 469625000, 34816500, 1200, 1500, 3100, 112, 72, 22]],
            'rectangle'    => ['lat_min' => 39.36, 'lat_max' => 39.8, 'lon_min' => 2.9, 'lon_max' => 3.2],
            'simplificat'  => '_p~iF~ps|U',
        ];
    }

    private function sortida(string $idExtern, int $punts = 0): SortidaBici
    {
        return SortidaBici::create([
            'vehicle_id' => $this->giant->id, 'origen' => 'garmin', 'id_extern' => $idExtern, 'nom' => 'Etapa 2',
            'tipus_activitat' => 'mountain_biking', 'inici' => '2023-04-28 05:15:00', 'data_local' => '2023-04-28',
            'distancia_m' => 75300, 'desnivell_pos_m' => 1180, 'punts' => $punts,
        ]);
    }

    public function test_pel_nom_del_fitxer_troba_la_sortida_encara_que_el_resum_tingui_l_hora_arrodonida(): void
    {
        $s = $this->sortida('11000153638');

        $r = $this->servei->importaSessio($this->fit('11000153638_ACTIVITY.fit'), '/no/hi/es.fit', null);

        $this->assertSame('afegit', $r['estat']);
        $this->assertSame($s->id, $r['sortida_id']);
        $this->assertSame(2, $s->fresh()->punts);
        // El resum de les columnes continua sent el de Garmin
        $this->assertSame(1180.0, (float) $s->fresh()->desnivell_pos_m);
    }

    public function test_si_el_nom_diu_una_activitat_que_no_quadra_no_hi_afegeix_res(): void
    {
        $s = $this->sortida('11000153638');
        $fit = $this->fit('11000153638_ACTIVITY.fit', 1682658900 + 3600);

        $r = $this->servei->importaSessio($fit, '/no/hi/es.fit', null);

        $this->assertSame('error', $r['estat']);
        $this->assertSame(0, $s->fresh()->punts);
    }

    public function test_una_sortida_que_ja_te_recorregut_no_es_toca(): void
    {
        $this->sortida('11000153638', punts: 3531);

        $r = $this->servei->importaSessio($this->fit('11000153638_ACTIVITY.fit'), '/no/hi/es.fit', $this->giant->id);

        $this->assertSame('ja_hi_era', $r['estat']);
        $this->assertSame(0, DB::connection('recorreguts')->table('punts')->count());
    }

    public function test_una_caminada_es_descarta(): void
    {
        $r = $this->servei->importaSessio($this->fit('1_ACTIVITY.fit', sport: 'walking'), '/no/hi/es.fit', $this->giant->id);

        $this->assertSame('descartat', $r['estat']);
        $this->assertSame(0, SortidaBici::count());
    }

    public function test_una_sortida_nova_demana_la_bici_i_amb_ella_es_crea_amb_els_components_muntats(): void
    {
        $this->assertSame('cal_bici', $this->servei->importaSessio($this->fit('555_ACTIVITY.fit'), '/no/hi/es.fit', null)['estat']);
        $this->assertSame(0, SortidaBici::count());

        $peca = fn (string $id, array $mes) => MaterialEsportiu::create(['origen' => 'garmin', 'id_extern' => $id, 'nom' => $id, 'estat' => 'actiu'] + $mes);
        $biciGarmin = $peca('B', ['tipus' => 'bici', 'vehicle_id' => $this->giant->id]);
        $cadena = $peca('C', ['tipus' => 'component', 'muntat_a_vehicle_id' => $this->giant->id, 'data_inici' => '2023-01-01']);
        $peca('D', ['tipus' => 'component', 'muntat_a_vehicle_id' => $this->giant->id, 'data_inici' => '2024-01-01']);   // encara no
        $peca('E', ['tipus' => 'component', 'muntat_a_vehicle_id' => $this->giant->id, 'data_fi' => '2022-12-31']);     // ja retirada

        $r = $this->servei->importaSessio($this->fit('555_ACTIVITY.fit'), '/no/hi/es.fit', $this->giant->id);
        $this->assertSame('creada', $r['estat']);

        $nova = SortidaBici::find($r['sortida_id']);
        $this->assertSame('555', $nova->id_extern);
        $this->assertSame('mountain_biking', $nova->tipus_activitat);
        $this->assertSame('Sortida de BTT', $nova->nom);
        // 05:15 UTC + 2 h: la data local és la del dia
        $this->assertSame('2023-04-28', $nova->data_local->toDateString());
        $this->assertSame(1150.0, (float) $nova->desnivell_pos_m);
        $this->assertSame(2, $nova->punts);
        // Dels punts: l'altitud (cm) i la temperatura que la sessió no porta
        $this->assertSame(10.0, (float) $nova->alt_min_m);
        $this->assertSame(22.0, (float) $nova->temp_max);
        $this->assertEqualsCanonicalizing([$biciGarmin->id, $cadena->id], $nova->material->pluck('id')->all());
    }

    public function test_la_pantalla_llista_les_sortides_sense_recorregut_amb_l_enllac_a_garmin(): void
    {
        $this->sortida('11000153638');
        $this->sortida('22', punts: 100);

        $this->actingAs(User::factory()->create())
            ->get(route('bicis.importa.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Bicis/Importa')
                ->has('senseRecorregut', 1)
                ->where('senseRecorregut.0.garmin', 'https://connect.garmin.com/modern/activity/11000153638'));

        $this->postJson(route('bicis.importa.store'), ['fitxers' => [\Illuminate\Http\UploadedFile::fake()->create('foto.jpg', 10)]])
            ->assertStatus(422);
    }
}
