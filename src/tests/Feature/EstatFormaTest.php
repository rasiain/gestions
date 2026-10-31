<?php

namespace Tests\Feature;

use App\Models\SalutRegistre;
use App\Models\SortidaBici;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\EstatFormaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BdRecorreguts;
use Tests\TestCase;

/**
 * La càrrega de cada sortida (TRIMP d'Edwards) i les corbes de fons, fatiga i frescor.
 */
class EstatFormaTest extends TestCase
{
    use BdRecorreguts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparaBdRecorreguts();
        // FC màxima apuntada: 200, perquè els percentatges siguin rodons
        SalutRegistre::create(['data' => '2025-01-01', 'fc_maxima' => 200]);
    }

    private function servei(): EstatFormaService
    {
        return app(EstatFormaService::class);
    }

    /** @param  array<int, ?int>  $fcs  una pulsació per segon */
    private function sortida(array $fcs = [], array $extra = []): SortidaBici
    {
        $bici = Vehicle::firstOrCreate(['nom' => 'Giant', 'tipus' => 'bici']);
        $s = SortidaBici::create($extra + [
            'vehicle_id' => $bici->id, 'origen' => 'garmin', 'id_extern' => uniqid(), 'tipus_activitat' => 'cycling',
            'inici' => '2025-06-01 08:00:00', 'data_local' => '2025-06-01', 'distancia_m' => 30000, 'punts' => count($fcs),
        ]);
        DB::connection('recorreguts')->table('punts')->insert(array_map(
            fn ($t, $fc) => ['sortida_id' => $s->id, 't' => $t, 'fc' => $fc],
            array_keys($fcs), $fcs,
        ));

        return $s;
    }

    public function test_les_zones_van_per_percentatge_de_la_maxima(): void
    {
        $f = $this->servei();
        $this->assertSame(0, $f->zona(99, 200));
        $this->assertSame(1, $f->zona(100, 200));
        $this->assertSame(2, $f->zona(120, 200));   // 60 % exacte: la zona de dalt
        $this->assertSame(4, $f->zona(179, 200));
        $this->assertSame(5, $f->zona(180, 200));
        $this->assertSame(5, $f->zona(210, 200));
    }

    public function test_deu_minuts_a_cada_zona_son_150(): void
    {
        $fcs = [];
        foreach ([110, 130, 150, 170, 185] as $fc) {
            $fcs = array_merge($fcs, array_fill(0, 600, $fc));
        }
        $fcs[] = 185;   // l'últim punt només tanca el tram anterior

        $c = $this->servei()->carrega($this->sortida($fcs));

        $this->assertSame('punts', $c['metode']);
        $this->assertEquals([1 => 10, 2 => 10, 3 => 10, 4 => 10, 5 => 10], $c['zones']);
        $this->assertEquals(150, $c['trimp']);
        $this->assertEquals(50, $c['minuts']);
        $this->assertFalse($c['parcial']);
        $this->assertSame(['valor' => 200, 'origen' => 'registre', 'data' => '2025-01-01'], $c['fc_maxima']);
    }

    public function test_una_pausa_nomes_compta_deu_segons(): void
    {
        $s = $this->sortida();
        DB::connection('recorreguts')->table('punts')->insert([
            ['sortida_id' => $s->id, 't' => 0, 'fc' => 150],
            ['sortida_id' => $s->id, 't' => 3600, 'fc' => 150],   // una hora aturat
        ]);
        $s->update(['punts' => 2]);

        $this->assertEqualsWithDelta(10 / 60 * 3, $this->servei()->carrega($s->fresh())['trimp'], 0.1);
    }

    public function test_sense_pulsacio_mes_d_un_vint_per_cent_es_parcial(): void
    {
        $c = $this->servei()->carrega($this->sortida([...array_fill(0, 70, 150), ...array_fill(0, 31, null)]));

        $this->assertTrue($c['parcial']);
    }

    public function test_si_la_sortida_supera_la_maxima_durant_30_s_es_fa_servir_aquella(): void
    {
        $c = $this->servei()->carrega($this->sortida(array_fill(0, 120, 210)));

        $this->assertSame(['valor' => 210, 'origen' => 'sortida'], $c['fc_maxima']);
    }

    public function test_sense_punts_tira_de_les_zones_de_garmin_i_si_no_de_la_mitjana(): void
    {
        $zones = $this->servei()->carrega($this->sortida([], ['dades_originals' => ['hrTimeInZone_2' => 600000, 'hrTimeInZone_4' => 300000]]));
        $this->assertSame('zones', $zones['metode']);
        $this->assertEquals(10 * 2 + 5 * 4, $zones['trimp']);

        $mitjana = $this->servei()->carrega($this->sortida([], ['fc_mitjana' => 150, 'temps_moviment_s' => 3600]));
        $this->assertSame('mitjana', $mitjana['metode']);
        $this->assertEquals(60 * 3, $mitjana['trimp']);

        $res = $this->servei()->carrega($this->sortida());
        $this->assertNull($res['trimp']);
        $this->assertNull($res['metode']);
    }

    public function test_amb_carrega_constant_el_fons_hi_tendeix_i_la_frescor_es_la_del_mati(): void
    {
        $des = Carbon::parse('2025-01-01');
        $fins = Carbon::parse('2026-12-31');
        $carregues = [];
        for ($d = $des->copy(); $d->lte($fins); $d->addDay()) {
            $carregues[] = ['data' => $d->toDateString(), 'trimp' => 100.0];
        }

        $corbes = $this->servei()->corbes($carregues, $des, $des, $fins);

        $this->assertEqualsWithDelta(100, end($corbes)['fons'], 0.1);
        $this->assertEqualsWithDelta(100, end($corbes)['fatiga'], 0.1);
        $this->assertEquals(0, $corbes[0]['frescor']);   // el primer matí no hi ha res d'abans
        $this->assertEqualsWithDelta($corbes[9]['fons'] - $corbes[9]['fatiga'], $corbes[10]['frescor'], 0.11);
    }

    public function test_la_pantalla_es_carrega(): void
    {
        $this->actingAs(User::factory()->create());
        $this->sortida(array_fill(0, 61, 150), ['data_local' => now()->subDays(3)->toDateString()]);

        $this->get(route('salut.estat-de-forma'))->assertOk();
    }
}
