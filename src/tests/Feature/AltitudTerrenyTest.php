<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * scripts/altitud-terreny.mjs sobre els models reals, que no són al repositori (33 GB): sense ells, el
 * test se salta. L'ICGC va en UTM i l'IGN en graus, de manera que si coincideixen, la conversió a UTM
 * i l'alineació de les cel·les són bones.
 */
class AltitudTerrenyTest extends TestCase
{
    /** @return array<int, array> una línia de sortida per sortida */
    private function llegeix(array $sortides): array
    {
        $carpeta = storage_path('app/models');
        if (! is_dir("{$carpeta}/icgc") || ! is_dir("{$carpeta}/ign")) {
            $this->markTestSkipped('Falten els models del terreny (sortides:baixa-models).');
        }

        $entrada = implode("\n", array_map(fn ($id, $punts) => json_encode(['id' => $id, 'punts' => $punts]), array_keys($sortides), $sortides));
        $proces = new Process(['node', base_path('scripts/altitud-terreny.mjs'), $carpeta], base_path(), null, $entrada);
        $proces->setTimeout(300)->mustRun();

        return array_map(fn ($l) => json_decode($l, true), explode("\n", trim($proces->getOutput())));
    }

    public function test_icgc_i_ign_coincideixen_a_catalunya(): void
    {
        // Girona (pla), el cim de Rocacorba i la Vall de Núria (muntanya, on un desplaçament es notaria)
        $punts = [[41.98310, 2.82490], [42.08990, 2.68420], [42.39710, 2.15240]];
        [$r] = $this->llegeix([1 => $punts]);

        $this->assertSame([], $r['sense_dades']);
        foreach ($punts as $i => $p) {
            $this->assertEqualsWithDelta($r['fonts']['ign'][$i], $r['fonts']['icgc'][$i], 300, "punt {$i}: ICGC i IGN, a menys de 3 m");
        }
        $this->assertEqualsWithDelta(7000, $r['fonts']['icgc'][0], 2500, 'Girona és cap als 70 m');
        $this->assertGreaterThan(190000, $r['fonts']['icgc'][2], 'Núria és per damunt dels 1.900 m');
    }

    public function test_una_font_nomes_es_dona_si_cobreix_tots_els_punts(): void
    {
        $girona = [41.98310, 2.82490];
        $palma = [39.57140, 2.64900];
        $perpinya = [42.69860, 2.89540];

        [$mallorca, $franca] = $this->llegeix([
            1 => [$girona, null, $palma],
            2 => [$girona, $perpinya],
        ]);

        // A Mallorca no hi ha ICGC: aquella sortida només té l'IGN; el punt sense posició queda null
        $this->assertNull($mallorca['fonts']['icgc']);
        $this->assertSame(['icgc' => 1], $mallorca['sense_dades']);
        $this->assertCount(3, $mallorca['fonts']['ign']);
        $this->assertNull($mallorca['fonts']['ign'][1]);

        // Un punt a França i ja no n'hi ha cap
        $this->assertSame(['icgc' => null, 'ign' => null], $franca['fonts']);
        $this->assertSame(['icgc' => 1, 'ign' => 1], $franca['sense_dades']);
    }
}
