<?php

namespace Tests\Feature;

use App\Models\Preferencia;
use App\Models\SalutRegistre;
use App\Models\SortidaBici;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\DadesFisiquesService;
use App\Services\ImportacioAppleSalutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Les dades físiques: cada dada val fins al registre següent que la porti, camp a camp; la pulsació
 * màxima, si no s'apunta, surt de les sortides; i la càrrega d'Apple Salut fa medianes mensuals sense
 * tocar mai el que s'ha apuntat a mà.
 */
class DadesFisiquesTest extends TestCase
{
    use RefreshDatabase;

    private function servei(): DadesFisiquesService
    {
        return new DadesFisiquesService;
    }

    public function test_cada_dada_val_fins_al_seguent_registre_que_la_porta(): void
    {
        SalutRegistre::create(['data' => '2025-01-15', 'pes' => 82, 'fc_repos' => 58]);
        SalutRegistre::create(['data' => '2025-03-01', 'pes' => 83]);   // sense pulsació: no anul·la la d'abans

        $dia = Carbon::parse('2025-04-01');
        $this->assertEquals(83, $this->servei()->valorA('pes', $dia)['valor']);
        $this->assertSame(58, $this->servei()->valorA('fc_repos', $dia)['valor']);
        $this->assertSame('2025-01-15', $this->servei()->valorA('fc_repos', $dia)['data']);
        $this->assertEquals(82, $this->servei()->valorA('pes', Carbon::parse('2025-02-28'))['valor']);
    }

    public function test_abans_del_primer_registre_hi_val_el_primer_i_es_diu(): void
    {
        SalutRegistre::create(['data' => '2025-01-15', 'pes' => 82]);

        $valor = $this->servei()->valorA('pes', Carbon::parse('2024-06-01'));
        $this->assertEquals(82, $valor['valor']);
        $this->assertTrue($valor['anterior']);
        $this->assertNull($this->servei()->valorA('fc_repos', Carbon::parse('2024-06-01')));
    }

    private function sortides(int $quantes, string $des, int $fcBase): void
    {
        $bici = Vehicle::firstOrCreate(['nom' => 'Giant', 'tipus' => 'bici']);
        for ($i = 0; $i < $quantes; $i++) {
            $dia = Carbon::parse($des)->addDays($i)->toDateString();
            SortidaBici::create([
                'vehicle_id' => $bici->id, 'origen' => 'garmin', 'id_extern' => "{$des}-{$i}", 'tipus_activitat' => 'cycling',
                'inici' => "{$dia} 08:00:00", 'data_local' => $dia, 'distancia_m' => 30000, 'fc_max' => $fcBase + $i,
            ]);
        }
    }

    public function test_la_fc_maxima_deduida_es_la_cinquena_mes_alta_de_l_ultim_any(): void
    {
        $this->sortides(25, '2025-03-01', 150);   // 150…174: la 5a més alta és 170

        $fc = $this->servei()->fcMaxima(Carbon::parse('2025-12-31'));
        $this->assertSame(['valor' => 170, 'origen' => 'sortides'], $fc);
    }

    public function test_amb_poques_sortides_pren_l_any_d_abans_i_si_no_tanaka(): void
    {
        $this->sortides(25, '2024-03-01', 150);
        $this->sortides(3, '2025-06-01', 140);

        // A finals de 2025 només n'hi ha 3: val la de l'any d'abans
        $this->assertSame(170, $this->servei()->fcMaxima(Carbon::parse('2025-12-31'))['valor']);

        // Sense prou sortides enlloc, Tanaka amb l'edat del perfil: 208 − 0,7 × 50 = 173
        Preferencia::desa(DadesFisiquesService::PREFERENCIA_PERFIL, ['data_naixement' => '1977-01-01', 'sexe' => 'home']);
        $this->assertSame(['valor' => 173, 'origen' => 'tanaka'], $this->servei()->fcMaxima(Carbon::parse('2027-06-01')));
    }

    public function test_la_fc_maxima_apuntada_mana_sobre_la_deduida(): void
    {
        $this->sortides(25, '2025-03-01', 150);
        SalutRegistre::create(['data' => '2025-06-01', 'fc_maxima' => 181]);

        $this->assertSame(181, $this->servei()->fcMaxima(Carbon::parse('2025-12-31'))['valor']);
        // Abans de l'apuntada, la deduïda: l'apuntada no es projecta enrere
        $this->assertSame('sortides', $this->servei()->fcMaxima(Carbon::parse('2025-04-15'))['origen']);
    }

    private function xml(): string
    {
        $fitxer = tempnam(sys_get_temp_dir(), 'salut');
        $pes = fn (string $dia, float $kg, string $font = 'MyTanita') => "<Record type=\"HKQuantityTypeIdentifierBodyMass\" sourceName=\"{$font}\" unit=\"kg\" startDate=\"{$dia} 07:00:00 +0100\" value=\"{$kg}\"/>";
        $fc = fn (string $dia, int $v) => "<Record type=\"HKQuantityTypeIdentifierRestingHeartRate\" sourceName=\"Connect\" unit=\"count/min\" startDate=\"{$dia} 00:00:00 +0100\" value=\"{$v}\"/>";
        file_put_contents($fitxer, implode("\n", [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<HealthData locale="ca_ES">',
            '<Me HKCharacteristicTypeIdentifierDateOfBirth="1968-02-28" HKCharacteristicTypeIdentifierBiologicalSex="HKBiologicalSexNotSet"/>',
            $pes('2023-12-01', 90),                // abans de --des-de
            $pes('2025-01-05', 82), $pes('2025-01-10', 83), $pes('2025-01-20', 84),
            $pes('2025-01-21', 57.9),              // una altra persona a la bàscula
            $pes('2025-02-03', 85, 'Connect'),     // el mes no té cap pesada de la Tanita
            '<Record type="HKQuantityTypeIdentifierLeanBodyMass" sourceName="MyTanita" unit="kg" startDate="2025-01-05 07:00:00 +0100" value="53.2"/>',
            $fc('2025-01-01', 57), $fc('2025-01-02', 59), $fc('2025-01-03', 64),
            '<Record type="HKQuantityTypeIdentifierHeight" sourceName="iPhone" unit="cm" startDate="2025-01-21 10:00:00 +0100" value="181"/>',
            '</HealthData>',
        ]));

        return $fitxer;
    }

    public function test_la_carrega_d_apple_salut_fa_la_mediana_de_cada_mes(): void
    {
        $servei = new ImportacioAppleSalutService;
        $llegit = $servei->llegeix($this->xml(), '2024-01-01');
        ['mesos' => $mesos, 'descartades' => $descartades] = $servei->perMes($llegit);

        $this->assertSame(['2025-01', '2025-02'], array_keys($mesos));
        $this->assertEquals(83, $mesos['2025-01']['pes']);
        $this->assertSame(59, $mesos['2025-01']['fc_repos']);
        $this->assertEquals(85, $mesos['2025-02']['pes']);
        $this->assertNull($mesos['2025-02']['fc_repos']);
        $this->assertSame([['2025-01-21', 57.9, 'MyTanita']], $descartades);
        $this->assertSame('1968-02-28', $llegit['naixement']);
        $this->assertSame(181, $llegit['alcada']);
    }

    public function test_la_carrega_no_toca_els_manuals_i_es_pot_repetir(): void
    {
        SalutRegistre::create(['data' => '2025-02-15', 'pes' => 80, 'origen' => 'manual']);
        Preferencia::desa(DadesFisiquesService::PREFERENCIA_PERFIL, ['sexe' => 'home']);
        $servei = new ImportacioAppleSalutService;
        $llegit = $servei->llegeix($this->xml(), '2024-01-01');
        $mesos = $servei->perMes($llegit)['mesos'];

        $this->assertSame(['creats' => 1, 'actualitzats' => 0, 'saltats' => 1], $servei->desa($mesos, $llegit['naixement']));
        $this->assertSame(['creats' => 0, 'actualitzats' => 1, 'saltats' => 1], $servei->desa($mesos, $llegit['naixement']));

        $this->assertSame(2, SalutRegistre::count());
        $this->assertEquals(80, SalutRegistre::whereDate('data', '2025-02-15')->value('pes'));
        $this->assertEqualsCanonicalizing(['sexe' => 'home', 'data_naixement' => '1968-02-28'], Preferencia::valor(DadesFisiquesService::PREFERENCIA_PERFIL));
    }

    public function test_un_registre_necessita_alguna_dada_i_una_data_lliure(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post(route('salut.registres.store'), ['data' => '2026-10-01'])->assertSessionHasErrors('pes');
        $this->post(route('salut.registres.store'), ['data' => '2026-10-01', 'fc_repos' => 58])->assertSessionHasNoErrors();
        $this->post(route('salut.registres.store'), ['data' => '2026-10-01', 'pes' => 84])->assertSessionHasErrors('data');

        // Corregir-ne un d'Apple Salut el fa manual: la càrrega ja no el tocarà
        $importat = SalutRegistre::create(['data' => '2025-01-15', 'pes' => 82, 'origen' => 'apple_salut']);
        $this->put(route('salut.registres.update', $importat), ['data' => '2025-01-15', 'pes' => 82.5])->assertSessionHasNoErrors();
        $this->assertSame('manual', $importat->fresh()->origen);

        $this->get(route('salut.dades-fisiques'))->assertOk();

        // L'alçada del perfil, en cm
        $this->put(route('salut.perfil'), ['sexe' => 'home', 'alcada' => 1.81])->assertSessionHasErrors('alcada');
        $this->put(route('salut.perfil'), ['sexe' => 'home', 'alcada' => 181])->assertSessionHasNoErrors();
        $this->assertSame(181, (new DadesFisiquesService)->perfil()['alcada']);
    }
}
