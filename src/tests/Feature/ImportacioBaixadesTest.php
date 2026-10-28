<?php

namespace Tests\Feature;

use App\Models\SortidaBici;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\BdRecorreguts;
use Tests\TestCase;

/** Importar les activitats de Garmin que hi ha a Baixades i esborrar-ne les que entren bé. */
class ImportacioBaixadesTest extends TestCase
{
    use BdRecorreguts;
    use RefreshDatabase;

    private string $baixades;

    private function gpx(string $hora): string
    {
        return <<<GPX
<?xml version="1.0"?>
<gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1"><trk><trkseg>
  <trkpt lat="41.9775757" lon="2.8199437"><ele>160</ele><time>{$hora}:47Z</time></trkpt>
  <trkpt lat="41.9784757" lon="2.8199437"><ele>165</ele><time>{$hora}:57Z</time></trkpt>
  <trkpt lat="41.9793757" lon="2.8199437"><ele>170</ele><time>{$hora}:59Z</time></trkpt>
</trkseg></trk></gpx>
GPX;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparaBdRecorreguts();

        $this->baixades = sys_get_temp_dir() . '/baixades-' . uniqid();
        File::ensureDirectoryExists($this->baixades);
        config(['services.garmin.baixades' => $this->baixades]);

        // Una sortida que ja té recorregut, a la mateixa hora que el primer GPX
        $bici = Vehicle::create(['nom' => 'Giant', 'tipus' => 'bici']);
        SortidaBici::create([
            'vehicle_id' => $bici->id, 'origen' => 'garmin', 'id_extern' => '111', 'tipus_activitat' => 'cycling',
            'inici' => '2016-11-20 07:14:47', 'data_local' => '2016-11-20', 'punts' => 3,
        ]);
        File::put("{$this->baixades}/activity_111.gpx", $this->gpx('2016-11-20T07:14'));
        File::put("{$this->baixades}/activity_222.gpx", $this->gpx('2017-03-05T09:30'));
        File::put("{$this->baixades}/vacances.zip", 'no és cap activitat');

        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->baixades);
        parent::tearDown();
    }

    public function test_nomes_llista_els_fitxers_de_garmin(): void
    {
        $this->get(route('bicis.importa.index'))->assertInertia(fn ($p) => $p
            ->where('baixades', fn ($llista) => collect($llista)->pluck('nom')->sort()->values()->all() === ['activity_111.gpx', 'activity_222.gpx'])
            ->etc());
    }

    public function test_esborra_els_que_entren_be_i_deixa_els_altres(): void
    {
        $r = $this->postJson(route('bicis.importa.baixades'), ['fitxers' => ['activity_111.gpx', 'activity_222.gpx']])->assertOk()->json('resultats');

        $perOrigen = collect($r)->keyBy('origen');
        $this->assertSame('ja_hi_era', $perOrigen['activity_111.gpx']['estat']);
        $this->assertTrue($perOrigen['activity_111.gpx']['esborrat']);
        $this->assertFileDoesNotExist("{$this->baixades}/activity_111.gpx");

        // Sense bici per a les noves, la segona no entra: es queda a Baixades
        $this->assertSame('cal_bici', $perOrigen['activity_222.gpx']['estat']);
        $this->assertFalse($perOrigen['activity_222.gpx']['esborrat']);
        $this->assertFileExists("{$this->baixades}/activity_222.gpx");
        $this->assertFileExists("{$this->baixades}/vacances.zip");
    }

    public function test_no_accepta_cap_fitxer_que_no_sigui_de_garmin(): void
    {
        $this->postJson(route('bicis.importa.baixades'), ['fitxers' => ['vacances.zip', '../activity_111.gpx']])
            ->assertStatus(422)->assertJsonValidationErrors(['fitxers.0', 'fitxers.1']);
        $this->assertFileExists("{$this->baixades}/vacances.zip");
    }
}
