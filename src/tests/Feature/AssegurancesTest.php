<?php

namespace Tests\Feature;

use App\Models\AssegurancaPolissa;
use App\Models\Categoria;
use App\Models\CompteCorrent;
use App\Models\Immoble;
use App\Models\Lloguer;
use App\Models\MovimentCompteCorrent;
use App\Models\MovimentConcepte;
use App\Models\MovimentLloguerDespesa;
use App\Models\User;
use App\Services\AssegurancesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * La detecció d'assegurances llegeix l'arbre de categories sencer i el creua
 * amb les despeses de lloguer. Els tests unitaris cobreixen la resolució d'una
 * cadena; aquí es munta l'arbre de debò, amb els seus comptes i moviments,
 * que és on surten els casos que no es veuen d'una cadena sola: el mateix camí
 * repetit a dos comptes, el vincle amb el lloguer i els ajustos desats.
 */
class AssegurancesTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function compte(string $nom): CompteCorrent
    {
        return CompteCorrent::create([
            'compte_corrent' => str_pad((string) ++$this->seq, 24, '0', STR_PAD_LEFT),
            'nom'            => $nom,
            'ordre'          => 0,
            'tipus'          => 'corrent',
        ]);
    }

    /**
     * Crea la cadena de categories d'un camí i en retorna la fulla.
     *
     * @param  array<int, string>  $cami
     */
    private function categoria(CompteCorrent $compte, array $cami): Categoria
    {
        $pare = null;

        foreach ($cami as $nom) {
            $pare = Categoria::firstOrCreate([
                'compte_corrent_id' => $compte->id,
                'nom'               => $nom,
                'categoria_pare_id' => $pare?->id,
            ]);
        }

        return $pare;
    }

    private function moviment(Categoria $categoria, string $data, float $import): MovimentCompteCorrent
    {
        return MovimentCompteCorrent::create([
            'data_moviment'     => $data,
            'concepte_id'       => MovimentConcepte::firstOrCreate(['concepte' => 'PROVA'])->id,
            'concepte_original' => 'PROVA',
            'import'            => $import,
            'hash'              => hash('sha256', 'mov' . ++$this->seq),
            'compte_corrent_id' => $categoria->compte_corrent_id,
            'categoria_id'      => $categoria->id,
        ]);
    }

    private function lloguer(CompteCorrent $compte, string $nom, string $poblacio): Lloguer
    {
        $immoble = Immoble::create([
            'referencia_cadastral' => 'REF' . ++$this->seq,
            'adreca'               => $nom,
            'poblacio'             => $poblacio,
        ]);

        return Lloguer::create([
            'nom'               => $nom,
            'immoble_id'        => $immoble->id,
            'compte_corrent_id' => $compte->id,
        ]);
    }

    /**
     * @return Collection<int, MovimentCompteCorrent>
     */
    private function detectats(): Collection
    {
        return app(AssegurancesService::class)->moviments();
    }

    public function test_el_grup_surt_de_limmoble_i_la_companyia_de_la_fulla(): void
    {
        $compte = $this->compte('Compte de casa');
        $fulla  = $this->categoria($compte, [
            'DESPESES', 'IMMOBLES', 'GIRONA', 'BONASTRUCH DE PORTA 35', 'ASSEGURANÇA', 'SEGURCAIXA',
        ]);
        $this->moviment($fulla, '2026-01-04', -25.97);

        $moviment = $this->detectats()->sole();

        $this->assertSame('Assegurança', $moviment->getAttribute('tipus_asseguranca'));
        $this->assertSame('BONASTRUCH DE PORTA 35', $moviment->getAttribute('objecte_asseguranca'));
        $this->assertSame('Girona', $moviment->getAttribute('poblacio_asseguranca'));
        $this->assertSame('SEGURCAIXA', $moviment->getAttribute('companyia_asseguranca'));
    }

    public function test_el_mateix_cami_a_dos_comptes_fa_un_sol_grup(): void
    {
        // Cada compte té el seu arbre: la mateixa pòlissa pagada des de dos
        // comptes són dues categories diferents i un sol grup.
        foreach (['Compte vell', 'Compte nou'] as $nom) {
            $compte = $this->compte($nom);
            $fulla  = $this->categoria($compte, [
                'DESPESES', 'IMMOBLES', 'SALT', 'ÀNGEL GUIMERÀ 19', 'ASSEGURANÇA', 'OCCIDENT',
            ]);
            $this->moviment($fulla, '2026-03-10', -426.06);
        }

        $moviments = $this->detectats();

        $this->assertCount(2, $moviments);
        $this->assertCount(1, $moviments->pluck('grup_asseguranca')->unique());
    }

    public function test_el_lloguer_dona_nom_al_grup_quan_larbre_no_diu_limmoble(): void
    {
        $compte  = $this->compte('Compte de lloguers');
        $lloguer = $this->lloguer($compte, 'JOAN MARAGALL 33 BAIXOS', 'Girona');

        // Node genèric: l'arbre no sap de quin immoble és
        $fulla    = $this->categoria($compte, ['DESPESES', 'ASSEGURANCES', 'SEGURCAIXA NEGOCI']);
        $moviment = $this->moviment($fulla, '2026-04-14', -800.77);

        MovimentLloguerDespesa::create([
            'moviment_id' => $moviment->id,
            'lloguer_id'  => $lloguer->id,
            'categoria'   => 'assegurances',
        ]);

        $detectat = $this->detectats()->sole();

        $this->assertSame('JOAN MARAGALL 33 BAIXOS', $detectat->getAttribute('objecte_asseguranca'));
        $this->assertSame('Girona', $detectat->getAttribute('poblacio_asseguranca'));
        $this->assertSame($lloguer->id, $detectat->getAttribute('lloguer_id_asseguranca'));
        $this->assertNotNull($detectat->getAttribute('immoble_id_asseguranca'));
    }

    public function test_un_ajust_inclou_una_categoria_que_cap_patro_no_enganxa(): void
    {
        $compte = $this->compte('Compte de casa');
        $node   = $this->categoria($compte, ['DESPESES', 'SERVEIS', 'MUTUALITAT DELS ENGINYERS']);
        $fulla  = $this->categoria($compte, ['DESPESES', 'SERVEIS', 'MUTUALITAT DELS ENGINYERS', 'SERPRECO']);
        $this->moviment($fulla, '2026-02-01', -1180.81);

        $this->assertCount(0, $this->detectats());

        AssegurancaPolissa::create([
            'categoria_id' => $node->id,
            'inclou'       => true,
            'tipus'        => 'Mutualitat',
        ]);

        $detectat = $this->detectats()->sole();

        $this->assertSame('Mutualitat', $detectat->getAttribute('tipus_asseguranca'));
        $this->assertSame('MUTUALITAT DELS ENGINYERS', $detectat->getAttribute('objecte_asseguranca'));
        $this->assertSame('SERPRECO', $detectat->getAttribute('companyia_asseguranca'));
    }

    public function test_lajust_de_poblacio_agrupa_el_que_larbre_deixava_separat(): void
    {
        $compte = $this->compte('Compte de casa');

        $baixos = $this->categoria($compte, [
            'DESPESES', 'DESPESES PROPIETATS', 'ST.ANTONI MN 16, BAIXOS', 'ASSEGURANÇA ST. ANTONI',
        ]);
        $this->moviment($baixos, '2026-05-02', -160.41);

        $this->assertNull($this->detectats()->sole()->getAttribute('poblacio_asseguranca'));

        AssegurancaPolissa::create([
            'categoria_id' => $baixos->id,
            'poblacio'     => 'Sant Antoni de Calonge',
        ]);

        $this->assertSame('Sant Antoni de Calonge', $this->detectats()->sole()->getAttribute('poblacio_asseguranca'));
    }

    public function test_les_seccions_separen_els_immobles_de_lloguer_de_la_resta(): void
    {
        $compte  = $this->compte('Compte de lloguers');
        $lloguer = $this->lloguer($compte, 'RUTLLA 11 2ON 2A', 'Girona');

        // De lloguer: la despesa el lliga a un immoble real
        $deLloguer = $this->categoria($compte, [
            'DESPESES', 'DESPESES PROPIETATS', 'RUTLLA 11 2ON 2A', 'ASSEGURANÇA RUTLLA',
        ]);
        $moviment = $this->moviment($deLloguer, '2026-06-01', -126.90);
        MovimentLloguerDespesa::create([
            'moviment_id' => $moviment->id,
            'lloguer_id'  => $lloguer->id,
            'categoria'   => 'assegurances',
        ]);

        // Immoble que surt de l'arbre, però sense cap despesa que l'hi lligui
        $altreImmoble = $this->categoria($compte, [
            'DESPESES', 'IMMOBLES', 'BARCELONA', 'TRAVESSERA CORTS 196', 'ASSEGURANÇA',
        ]);
        $this->moviment($altreImmoble, '2026-06-02', -526.73);

        // Ni immoble ni lloguer: un vehicle
        $moto = $this->categoria($compte, ['DESPESES', 'MOTOR', 'MOTO', 'ASSEGURANÇA MOTO']);
        $this->moviment($moto, '2026-06-03', -456.82);

        $resposta = $this->actingAs(User::factory()->create())
            ->get(route('impostos.assegurances', ['any' => 2026]));

        $resposta->assertOk();

        $seccions = collect($resposta->viewData('page')['props']['seccions'])->keyBy('clau');

        $objectes = fn (string $clau) => collect($seccions[$clau]['poblacions'])
            ->flatMap(fn (array $poblacio) => $poblacio['objectes'])
            ->pluck('objecte')
            ->all();

        $this->assertSame(['RUTLLA 11 2ON 2A'], $objectes('lloguer'));
        $this->assertSame(['TRAVESSERA CORTS 196'], $objectes('identificat'));
        $this->assertSame(['MOTO'], $objectes('resta'));
    }

    public function test_ocult_treu_el_grup_de_la_vista_pero_no_de_la_llista(): void
    {
        $compte = $this->compte('Compte de casa');
        $fulla  = $this->categoria($compte, ['DESPESES', 'SERVEIS', 'ASSEGURANÇA DECESOS']);
        $this->moviment($fulla, '2026-07-01', -40.29);

        AssegurancaPolissa::create(['categoria_id' => $fulla->id, 'ocult' => true]);

        $props = $this->actingAs(User::factory()->create())
            ->get(route('impostos.assegurances', ['any' => 2026]))
            ->viewData('page')['props'];

        $ambObjectes = collect($props['seccions'])
            ->flatMap(fn (array $seccio) => $seccio['poblacions'])
            ->flatMap(fn (array $poblacio) => $poblacio['objectes']);

        $this->assertCount(0, $ambObjectes);
        // El moviment segueix sent una assegurança: només surt de la vista per pòlissa
        $this->assertCount(1, $props['moviments']);
    }

    public function test_desar_un_ajust_lescriu_a_totes_les_categories_del_cami(): void
    {
        $cami = ['DESPESES', 'ASSEGURANCES'];

        foreach (['Compte vell', 'Compte nou'] as $nom) {
            $this->categoria($this->compte($nom), $cami);
        }

        $usuari = User::factory()->create();

        $this->actingAs($usuari)->put(route('impostos.assegurances.ajustos.update'), [
            'cami'     => implode(' > ', $cami),
            'objecte'  => 'ASSEGURANCES DE CASA',
            'poblacio' => 'Girona',
        ])->assertRedirect();

        $this->assertSame(2, AssegurancaPolissa::where('objecte', 'ASSEGURANCES DE CASA')->count());

        // Un ajust sense cap valor no és cap decisió: s'esborra
        $this->actingAs($usuari)->put(route('impostos.assegurances.ajustos.update'), [
            'cami' => implode(' > ', $cami),
        ])->assertRedirect();

        $this->assertSame(0, AssegurancaPolissa::count());
    }
}
