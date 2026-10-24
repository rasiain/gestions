<?php

namespace Tests\Feature;

use App\Models\Preferencia;
use App\Models\SortidaBici;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\AltitudTerrenyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** El desnivell de les llistes (fitxa de la bici, rutes) segons l'altitud triada. */
class DesnivellsTriatsTest extends TestCase
{
    use RefreshDatabase;

    private Vehicle $bici;

    private SortidaBici $ambModels;

    private SortidaBici $senseModels;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bici = Vehicle::create(['nom' => 'Albert', 'tipus' => 'bici']);
        $sortida = fn (string $id, string $data) => SortidaBici::create([
            'vehicle_id' => $this->bici->id, 'origen' => 'garmin', 'id_extern' => $id, 'tipus_activitat' => 'cycling',
            'inici' => "{$data} 08:00:00", 'data_local' => $data, 'desnivell_pos_m' => 200, 'distancia_m' => 5000,
        ]);
        // Un trajecte de ciutat amb el desnivell inflat pel rellotge: 200 m segons Garmin, 30 de debò
        $this->ambModels = $sortida('1', '2026-05-01');
        // Una sortida a França: cap model la cobreix
        $this->senseModels = $sortida('2', '2026-06-01');

        $desa = fn (string $metode, string $font, float $pujada) => DB::table('g_sortida_desnivells')->insert([
            'sortida_id' => $this->ambModels->id, 'metode' => $metode, 'font' => $font, 'pujada_m' => $pujada, 'baixada_m' => $pujada,
        ]);
        $desa('dispositiu', 'dispositiu', 190);
        $desa('combinat', 'icgc', 30);
        $desa('terreny', 'icgc', 25);
        $desa('combinat', 'ign', 32);
        $desa('terreny', 'ign', 27);
    }

    private function triats(?string $metode = null): array
    {
        return app(AltitudTerrenyService::class)->desnivellsTriats(SortidaBici::orderBy('id')->get(), $metode);
    }

    public function test_per_defecte_es_el_de_garmin(): void
    {
        $t = $this->triats();

        $this->assertSame(['pujada' => 200.0, 'metode' => 'dispositiu'], $t[$this->ambModels->id]);
        $this->assertSame(['pujada' => 200.0, 'metode' => 'dispositiu'], $t[$this->senseModels->id]);
    }

    public function test_la_preferencia_de_la_sortida_mana_sobre_la_de_per_defecte(): void
    {
        Preferencia::desa(AltitudTerrenyService::CLAU_PREFERENCIA, ['metode' => 'terreny', 'font' => 'millor']);
        $this->ambModels->update(['altitud_metode' => 'combinat', 'altitud_font' => 'ign']);

        $t = $this->triats();

        $this->assertSame(['pujada' => 32.0, 'metode' => 'combinat'], $t[$this->ambModels->id]);
        // Sense models, la de per defecte (terreny) cau al dispositiu
        $this->assertSame(['pujada' => 200.0, 'metode' => 'dispositiu'], $t[$this->senseModels->id]);
    }

    public function test_un_metode_demanat_s_aplica_a_totes_amb_la_millor_font(): void
    {
        $this->ambModels->update(['altitud_metode' => 'dispositiu']);

        $t = $this->triats('combinat');

        $this->assertSame(['pujada' => 30.0, 'metode' => 'combinat'], $t[$this->ambModels->id]);
        $this->assertSame('dispositiu', $t[$this->senseModels->id]['metode']);
    }

    public function test_la_fitxa_i_la_llista_de_sortides_compten_amb_l_altitud_demanada(): void
    {
        $this->actingAs(User::factory()->create());

        $fitxa = $this->getJson(route('bicis.fitxa', $this->bici) . '?altitud=combinat')->assertOk()->json('per_any');
        $this->assertSame(230, $fitxa[0]['desnivell']);   // 30 + 200
        $this->assertSame(400, $this->getJson(route('bicis.fitxa', $this->bici))->json('per_any.0.desnivell'));

        $sortides = collect($this->getJson(route('bicis.sortides', $this->bici) . '?any=2026&altitud=combinat')->json())->keyBy('id');
        $this->assertSame(30, $sortides[$this->ambModels->id]['desnivell']);
        $this->assertSame('combinat', $sortides[$this->ambModels->id]['desnivell_metode']);
        $this->assertSame('dispositiu', $sortides[$this->senseModels->id]['desnivell_metode']);
    }
}
