<?php

namespace Tests\Feature;

use App\Models\CompteCorrent;
use App\Models\MovimentCompteCorrent;
use App\Models\MovimentConcepte;
use App\Models\Persona;
use App\Models\User;
use App\Services\PatrimoniService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El patrimoni es valora el darrer dia de l'any: el que compta és el saldo del 31 de
 * desembre, no el d'avui, i és el que diu el banc i no la suma dels imports.
 */
class PatrimoniTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function compte(string $nom, string $tipus = 'corrent'): CompteCorrent
    {
        return CompteCorrent::create([
            'compte_corrent' => str_pad((string) ++$this->seq, 24, '0', STR_PAD_LEFT),
            'nom'            => $nom,
            'ordre'          => 0,
            'tipus'          => $tipus,
        ]);
    }

    private function moviment(CompteCorrent $compte, string $data, float $import, float $saldo): void
    {
        MovimentCompteCorrent::create([
            'data_moviment'     => $data,
            'concepte_id'       => MovimentConcepte::firstOrCreate(['concepte' => 'PROVA'])->id,
            'concepte_original' => 'PROVA',
            'import'            => $import,
            'saldo_posterior'   => $saldo,
            'hash'              => hash('sha256', 'mov' . ++$this->seq),
            'compte_corrent_id' => $compte->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function declaracio(Persona $persona, int $any = 2025): array
    {
        return app(PatrimoniService::class)->declaracio($persona, $any);
    }

    public function test_pren_el_saldo_del_31_de_desembre_i_no_el_dara(): void
    {
        $ana    = Persona::create(['nom' => 'Ana', 'cognoms' => 'Primera']);
        $compte = $this->compte('Compte');
        $compte->titulars()->sync([$ana->id]);

        $this->moviment($compte, '2025-11-30', 1000, 1000);
        $this->moviment($compte, '2025-12-20', 500, 1500);
        // Posterior al tancament: no hi ha d'entrar
        $this->moviment($compte, '2026-03-01', 9000, 10500);

        $apartat = $this->declaracio($ana)['bens']['apartats'][0];

        $this->assertSame(1500.0, $apartat['comptes'][0]['saldo']);
        $this->assertSame(1500.0, $apartat['total']);
    }

    public function test_la_mitjana_del_trimestre_es_pondera_pels_dies(): void
    {
        $ana    = Persona::create(['nom' => 'Ana', 'cognoms' => 'Primera']);
        $compte = $this->compte('Compte');
        $compte->titulars()->sync([$ana->id]);

        // 1.000 des d'abans del trimestre; el 31 de desembre en surten 900
        $this->moviment($compte, '2025-06-01', 1000, 1000);
        $this->moviment($compte, '2025-12-31', -900, 100);

        $apartat = $this->declaracio($ana)['bens']['apartats'][0];
        $compte  = $apartat['comptes'][0];

        // 91 dies a 1.000 i un dia a 100, sobre 92 dies d'octubre a desembre
        $this->assertEqualsWithDelta((91 * 1000 + 100) / 92, $compte['saldo_mig'], 0.01);
        $this->assertSame(100.0, $compte['saldo']);
    }

    public function test_es_declara_el_mes_gran_dels_dos_valors(): void
    {
        $ana = Persona::create(['nom' => 'Ana', 'cognoms' => 'Primera']);

        // Un compte que es buida al desembre: mana la mitjana
        $buidat = $this->compte('Es buida');
        $buidat->titulars()->sync([$ana->id]);
        $this->moviment($buidat, '2025-06-01', 10000, 10000);
        $this->moviment($buidat, '2025-12-30', -9900, 100);

        // Un compte que s'omple al desembre: mana el saldo del 31
        $omplert = $this->compte('S\'omple');
        $omplert->titulars()->sync([$ana->id]);
        $this->moviment($omplert, '2025-06-01', 100, 100);
        $this->moviment($omplert, '2025-12-30', 9900, 10000);

        $comptes = collect($this->declaracio($ana)['bens']['apartats'][0]['comptes'])->keyBy('nom');

        $this->assertSame('mitjana', $comptes['Es buida']['criteri']);
        $this->assertGreaterThan(9000, $comptes['Es buida']['valor']);

        $this->assertSame('saldo', $comptes["S'omple"]['criteri']);
        $this->assertSame(10000.0, $comptes["S'omple"]['valor']);
    }

    public function test_reparteix_el_saldo_entre_els_titulars(): void
    {
        $ana = Persona::create(['nom' => 'Ana', 'cognoms' => 'Primera']);
        $bru = Persona::create(['nom' => 'Bru', 'cognoms' => 'Segon']);

        $compte = $this->compte('Compartit');
        $compte->titulars()->sync([$ana->id, $bru->id]);
        $this->moviment($compte, '2025-06-01', 2000, 2000);

        $apartat = $this->declaracio($ana)['bens']['apartats'][0];

        $this->assertSame(2000.0, $apartat['comptes'][0]['saldo']);
        // La part surt del valor declarat, no del saldo
        $this->assertSame(round($apartat['comptes'][0]['valor'] / 2, 2), $apartat['comptes'][0]['part']);
        $this->assertSame(['Bru Segon'], $apartat['comptes'][0]['altres_titulars']);
    }

    public function test_els_comptes_dinversio_no_son_diposits(): void
    {
        $ana = Persona::create(['nom' => 'Ana', 'cognoms' => 'Primera']);

        $corrent = $this->compte('Corrent');
        $corrent->titulars()->sync([$ana->id]);
        $this->moviment($corrent, '2025-06-01', 100, 100);

        $fons = $this->compte('Un fons', 'fons_inversio');
        $fons->titulars()->sync([$ana->id]);
        $this->moviment($fons, '2025-06-01', 9999, 9999);

        $apartat = $this->declaracio($ana)['bens']['apartats'][0];

        $this->assertCount(1, $apartat['comptes']);
        $this->assertSame(100.0, $apartat['total']);
    }

    public function test_un_compte_sense_moviments_a_la_data_val_zero(): void
    {
        $ana    = Persona::create(['nom' => 'Ana', 'cognoms' => 'Primera']);
        $compte = $this->compte('Obert el 2026');
        $compte->titulars()->sync([$ana->id]);
        $this->moviment($compte, '2026-02-01', 800, 800);

        $apartat = $this->declaracio($ana)['bens']['apartats'][0];

        $this->assertSame(0.0, $apartat['comptes'][0]['saldo']);
        $this->assertSame(0.0, $apartat['total']);
    }

    public function test_es_pot_triar_lany_pero_no_el_que_encara_corre(): void
    {
        $ana    = Persona::create(['nom' => 'Ana', 'cognoms' => 'Primera']);
        $compte = $this->compte('Compte');
        $compte->titulars()->sync([$ana->id]);
        $this->moviment($compte, '2025-06-01', 300, 300);

        $props = $this->actingAs(User::factory()->create())
            ->get(route('impostos.patrimoni', ['persona_id' => $ana->id]))
            ->assertOk()
            ->viewData('page')['props'];

        $maxim = (int) date('Y') - 1;

        // Per defecte, l'últim exercici tancat
        $this->assertSame($maxim, $props['any']);
        $this->assertSame(sprintf('%04d-12-31', $maxim), $props['declaracio']['data']);
        $this->assertSame(0.0, $props['declaracio']['deutes']['total']);

        // Un any anterior sí que es pot demanar
        $anterior = $this->actingAs(User::factory()->create())
            ->get(route('impostos.patrimoni', ['persona_id' => $ana->id, 'any' => $maxim - 2]))
            ->viewData('page')['props'];

        $this->assertSame($maxim - 2, $anterior['any']);

        // L'any en curs, no: encara no ha arribat al 31 de desembre
        $enCurs = $this->actingAs(User::factory()->create())
            ->get(route('impostos.patrimoni', ['persona_id' => $ana->id, 'any' => (int) date('Y')]))
            ->viewData('page')['props'];

        $this->assertSame($maxim, $enCurs['any']);
    }
}
