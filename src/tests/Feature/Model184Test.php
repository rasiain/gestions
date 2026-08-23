<?php

namespace Tests\Feature;

use App\Models\Arrendador;
use App\Models\Categoria;
use App\Models\ComunitatBens;
use App\Models\Contracte;
use App\Models\CompteCorrent;
use App\Models\Factura;
use App\Models\Immoble;
use App\Models\Lloguer;
use App\Models\MovimentCompteCorrent;
use App\Models\MovimentConcepte;
use App\Models\MovimentLloguerDespesa;
use App\Models\Persona;
use App\Models\User;
use App\Services\Model184Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El 184 reparteix dues coses de dues maneres diferents: el rendiment per la
 * PARTICIPACIÓ —que depèn de l'amortització de cada comuner— i les retencions per la
 * QUOTA de titularitat. Els números d'aquests tests estan triats perquè les dues
 * reparticions no coincideixin, que és justament on es veu si el càlcul és correcte.
 */
class Model184Test extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private ComunitatBens $comunitat;
    private Immoble $immoble;
    private Lloguer $lloguer;
    private Persona $gran;
    private Persona $petit;

    protected function setUp(): void
    {
        parent::setUp();

        $compte = CompteCorrent::create([
            'compte_corrent' => str_pad((string) ++$this->seq, 24, '0', STR_PAD_LEFT),
            'nom'            => 'Compte de la comunitat',
            'ordre'          => 0,
            'tipus'          => 'corrent',
        ]);

        $this->gran  = Persona::create(['nom' => 'Gran', 'cognoms' => 'Comunera', 'nif' => '00000001R']);
        $this->petit = Persona::create(['nom' => 'Petit', 'cognoms' => 'Comuner', 'nif' => '00000002W']);

        $this->immoble = Immoble::create([
            'referencia_cadastral' => 'CADASTRE0001',
            'adreca'               => 'LOCAL DE PROVA',
            'poblacio'             => 'Girona',
        ]);

        // Quotes desiguals i amortitzacions desiguals: les dues reparticions han de sortir
        // diferents, que és el que el 184 distingeix.
        $this->immoble->propietaris()->attach($this->gran->id, [
            'data_inici' => '2020-01-01', 'quota' => 60, 'amortitzacio_anual' => 6000.00,
        ]);
        $this->immoble->propietaris()->attach($this->petit->id, [
            'data_inici' => '2020-01-01', 'quota' => 40, 'amortitzacio_anual' => 1500.00,
        ]);

        $this->lloguer = Lloguer::create([
            'nom'               => 'LOCAL DE PROVA',
            'immoble_id'        => $this->immoble->id,
            'compte_corrent_id' => $compte->id,
        ]);

        $this->comunitat = ComunitatBens::create(['nom' => 'PROVA CB', 'nif' => 'E00000000']);

        $contracte = Contracte::create(['lloguer_id' => $this->lloguer->id, 'data_inici' => '2020-01-01']);
        $arrendador = Arrendador::create([
            'arrendadorable_type' => ComunitatBens::class,
            'arrendadorable_id'   => $this->comunitat->id,
        ]);
        $contracte->arrendadors()->attach($arrendador->id);

        $this->compte = $compte;
    }

    private CompteCorrent $compte;

    private function factura(int $any, float $base, float $irpf): Factura
    {
        return Factura::create([
            'lloguer_id'       => $this->lloguer->id,
            'any'              => $any,
            'mes'              => 1 + ($this->seq++ % 12),
            'base'             => $base,
            'iva_percentatge'  => 21,
            'iva_import'       => round($base * 0.21, 2),
            'irpf_percentatge' => 19,
            'irpf_import'      => $irpf,
            'total'            => round($base * 1.21 - $irpf, 2),
        ]);
    }

    private function despesa(string $categoria, string $data, float $import, ?int $casella = null): MovimentLloguerDespesa
    {
        $categoriaBanc = Categoria::firstOrCreate([
            'compte_corrent_id' => $this->compte->id,
            'nom'               => 'DESPESES',
            'categoria_pare_id' => null,
        ]);

        $moviment = MovimentCompteCorrent::create([
            'data_moviment'     => $data,
            'concepte_id'       => MovimentConcepte::firstOrCreate(['concepte' => 'PROVA'])->id,
            'concepte_original' => 'PROVA',
            'import'            => -$import,
            'hash'              => hash('sha256', 'mov' . ++$this->seq),
            'compte_corrent_id' => $this->compte->id,
            'categoria_id'      => $categoriaBanc->id,
        ]);

        return MovimentLloguerDespesa::create([
            'moviment_id' => $moviment->id,
            'lloguer_id'  => $this->lloguer->id,
            'categoria'   => $categoria,
            'casella_184' => $casella,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function registre(int $any = 2026): array
    {
        return app(Model184Service::class)->declaracio($this->comunitat, $any)['immobles'][0];
    }

    public function test_les_despeses_van_a_la_casella_que_diu_la_seva_categoria(): void
    {
        $this->factura(2026, 100000.00, 19000.00);

        $this->despesa('taxes', '2026-03-01', 4000.00);
        $this->despesa('assegurança', '2026-04-01', 500.00);
        $this->despesa('comissions', '2026-05-01', 100.00);
        $this->despesa('comunitat', '2026-06-01', 300.00);
        $this->despesa('altres', '2026-07-01', 200.00);

        $caselles = $this->registre()['caselles'];

        $this->assertSame(100.0, $caselles[1]);   // interessos i despeses de finançament
        $this->assertSame(4000.0, $caselles[4]);  // tributs i recàrrecs
        $this->assertSame(500.0, $caselles[7]);   // primes d'assegurances
        $this->assertSame(7500.0, $caselles[8]);  // amortització: 6000 + 1500
        $this->assertSame(500.0, $caselles[10]);  // comunitat + altres
    }

    public function test_una_despesa_pot_canviar_de_casella_o_quedar_fora(): void
    {
        $this->factura(2026, 100000.00, 19000.00);

        // Una comissió que en realitat és la prima d'una assegurança
        $this->despesa('comissions', '2026-05-01', 700.00, casella: 7);
        // I un càrrec que no ha d'entrar a la declaració
        $this->despesa('comissions', '2026-05-02', 1.20, casella: 0);

        $caselles = $this->registre()['caselles'];

        $this->assertSame(700.0, $caselles[7]);
        $this->assertArrayNotHasKey(1, $caselles);
    }

    public function test_el_rendiment_es_reparteix_descomptant_lamortitzacio_de_cadascu(): void
    {
        $this->factura(2026, 100000.00, 19000.00);
        $this->despesa('taxes', '2026-03-01', 2500.00);

        $registre = $this->registre();

        // 100.000 − 2.500 − (6.000 + 1.500) = 90.000 de rendiment net
        $this->assertSame(10000.0, $registre['despeses']);
        $this->assertSame(90000.0, $registre['rendiment_net']);
        // El que es reparteix per quota abans que cadascú es dedueixi la seva amortització
        $this->assertSame(97500.0, $registre['base_repartible']);

        $comuners = collect($registre['comuners'])->keyBy('persona_id');
        $gran  = $comuners[$this->gran->id];
        $petit = $comuners[$this->petit->id];

        // 60 % · 97.500 − 6.000 = 52.500 ; 40 % · 97.500 − 1.500 = 37.500
        $this->assertSame(52500.0, $gran['rendiment']);
        $this->assertSame(37500.0, $petit['rendiment']);
        $this->assertSame(90000.0, $gran['rendiment'] + $petit['rendiment']);
    }

    public function test_la_participacio_no_es_la_quota_i_les_retencions_si(): void
    {
        $this->factura(2026, 100000.00, 19000.00);
        $this->despesa('taxes', '2026-03-01', 2500.00);

        $comuners = collect($this->registre()['comuners'])->keyBy('persona_id');
        $gran  = $comuners[$this->gran->id];
        $petit = $comuners[$this->petit->id];

        // La participació surt del rendiment: 52.500 / 90.000 i 37.500 / 90.000
        $this->assertSame(58.3333, $gran['participacio']);
        $this->assertSame(41.6667, $petit['participacio']);
        // I no coincideix amb la quota, perquè cadascú amortitza una cosa diferent
        $this->assertNotEqualsWithDelta($gran['quota'], $gran['participacio'], 0.01);

        // Les retencions, en canvi, es reparteixen per quota
        $this->assertSame(11400.0, $gran['retencio']);
        $this->assertSame(7600.0, $petit['retencio']);
    }

    public function test_els_ingressos_i_les_retencions_surten_de_les_factures_de_lany(): void
    {
        $this->factura(2026, 60000.00, 11400.00);
        $this->factura(2026, 40000.00, 7600.00);
        $this->factura(2025, 90000.00, 17100.00);

        $registre = $this->registre(2026);

        $this->assertSame(100000.0, $registre['ingressos']);
        $this->assertSame(19000.0, $registre['retencions']);
    }

    public function test_avisa_del_que_cal_repassar_abans_de_declarar(): void
    {
        $this->factura(2026, 100000.00, 19000.00);

        DB::table('g_propietaris_immobles')
            ->where('persona_id', $this->petit->id)
            ->update(['quota' => null, 'amortitzacio_anual' => null]);

        $avisos = app(Model184Service::class)->declaracio($this->comunitat, 2026)['avisos'];

        $this->assertNotEmpty(array_filter($avisos, fn ($a) => str_contains($a, 'sense quota')));
    }

    public function test_la_pantalla_mostra_la_declaracio(): void
    {
        $this->factura(2026, 100000.00, 19000.00);
        $this->despesa('taxes', '2026-03-01', 2500.00);

        $props = $this->actingAs(User::factory()->create())
            ->get(route('impostos.model-184', ['comunitat_bens_id' => $this->comunitat->id, 'any' => 2026]))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame('PROVA CB', $props['declaracio']['comunitat']['nom']);
        $this->assertCount(1, $props['declaracio']['immobles']);
        $this->assertSame(19000.0, $props['declaracio']['retencions']);
    }

    public function test_es_pot_desar_la_quota_i_lamortitzacio_dun_comuner(): void
    {
        $this->actingAs(User::factory()->create())
            ->put(route('impostos.model-184.propietaris.update'), [
                'immoble_id'         => $this->immoble->id,
                'persona_id'         => $this->petit->id,
                'any'                => 2026,
                'quota'              => 25,
                'amortitzacio_anual' => 999.99,
            ])->assertRedirect();

        $pivot = DB::table('g_propietaris_immobles')
            ->where('immoble_id', $this->immoble->id)
            ->where('persona_id', $this->petit->id)
            ->first();

        $this->assertEqualsWithDelta(25, (float) $pivot->quota, 0.0001);
        $this->assertEqualsWithDelta(999.99, (float) $pivot->amortitzacio_anual, 0.001);
    }
}
