<?php

namespace Tests\Unit;

use App\Models\MovimentCompteCorrent;
use App\Services\AssegurancesEstatService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

class AssegurancesEstatServiceTest extends TestCase
{
    private AssegurancesEstatService $servei;

    protected function setUp(): void
    {
        parent::setUp();

        $this->servei = new AssegurancesEstatService();
    }

    /**
     * Moviments d'una sola pòlissa, sense base de dades. La relació `despesa`
     * es fixa a null perquè llegir-la no dispari cap consulta.
     *
     * @param  array<int, array{0: string, 1: float}>  $linies  data i import
     * @return Collection<int, MovimentCompteCorrent>
     */
    private function moviments(array $linies): Collection
    {
        return collect($linies)->map(function (array $linia) {
            $m = new MovimentCompteCorrent(['data_moviment' => $linia[0], 'import' => $linia[1]]);
            $m->setRelation('despesa', null);
            $m->setAttribute('grup_asseguranca', 'nom:PROVA|GIRONA');
            $m->setAttribute('tipus_asseguranca', 'Assegurança');

            return $m;
        });
    }

    /**
     * @param  array<int, array{0: string, 1: float}>  $linies
     * @return array<string, mixed>
     */
    private function estat(array $linies, int $any = 2026, string $referencia = '2026-08-20'): array
    {
        $estats = $this->servei->estats($this->moviments($linies), $any, Carbon::parse($referencia));

        return $estats['nom:PROVA|GIRONA||Assegurança'];
    }

    public function test_lany_anterior_es_retalla_al_mateix_dia(): void
    {
        $linies = [];
        foreach (range(1, 12) as $mes) {
            $linies[] = [sprintf('2025-%02d-04', $mes), -10.0];
        }
        foreach (range(1, 8) as $mes) {
            $linies[] = [sprintf('2026-%02d-04', $mes), -12.0];
        }

        $estat = $this->estat($linies);

        $this->assertSame(96.0, $estat['pagat']);          // 8 × 12
        $this->assertSame(80.0, $estat['anterior_a_data']); // 8 × 10, fins al 20 d'agost
        $this->assertSame(120.0, $estat['anterior_total']); // l'any sencer
        $this->assertTrue($estat['any_incomplet']);
        $this->assertSame(16.0, $estat['variacio']);
        $this->assertSame(20.0, $estat['variacio_pct']);
        $this->assertSame('mensual', $estat['periodicitat']);
    }

    public function test_un_any_acabat_es_compara_sencer(): void
    {
        $estat = $this->estat([
            ['2024-04-10', -100.0],
            ['2025-04-10', -110.0],
        ], any: 2025, referencia: '2025-12-31');

        $this->assertSame(110.0, $estat['pagat']);
        $this->assertSame(100.0, $estat['anterior_a_data']);
        $this->assertSame(100.0, $estat['anterior_total']);
        $this->assertFalse($estat['any_incomplet']);
        $this->assertSame(10.0, $estat['variacio_pct']);
        $this->assertSame('anual', $estat['periodicitat']);
    }

    public function test_els_retorns_no_abarateixen_lany(): void
    {
        // Cas real: es paga el rebut, l'asseguradora l'extorna sencer i se'n
        // contracta un altre. El pagat és el que ha sortit del compte.
        $estat = $this->estat([
            ['2026-04-14', -800.77],
            ['2026-05-20', 800.77],
            ['2026-06-03', -558.99],
        ]);

        $this->assertSame(1359.76, $estat['pagat']);
        $this->assertSame(2, $estat['pagaments']);
        $this->assertSame(800.77, $estat['retornat']);
    }

    public function test_la_prima_es_el_carrec_mes_gran_de_lany_no_lultim(): void
    {
        // La categoria barreja el rebut anual amb comissions de 30 €: amb
        // l'últim càrrec, la prima d'enguany es compararia amb una comissió.
        $estat = $this->estat([
            ['2025-01-01', -30.0],
            ['2025-04-15', -764.44],
            ['2025-07-01', -30.0],
            ['2026-04-01', -30.0],
            ['2026-04-14', -800.77],
            ['2026-06-03', -30.0],
        ]);

        $this->assertSame(800.77, $estat['prima']);
        $this->assertSame('2026-04-14', $estat['data_prima']);
        $this->assertSame(764.44, $estat['prima_anterior']);
        $this->assertSame(4.8, $estat['prima_variacio_pct']);
    }

    public function test_una_polissa_sense_carrecs_recents_es_marca_inactiva(): void
    {
        $estat = $this->estat([['2021-12-07', -373.99]]);

        $this->assertSame(0.0, $estat['pagat']);
        $this->assertSame('inactiva', $estat['periodicitat']);
        $this->assertNull($estat['prima']);
        $this->assertNull($estat['variacio_pct']);
    }
}
