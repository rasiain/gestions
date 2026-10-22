<?php

namespace Tests\Feature;

use App\Support\TiffSimple;
use Tests\TestCase;

/** El lector de GeoTIFF senzills que fa la màscara de terra amb el model d'1 km de l'IGN. */
class TiffSimpleTest extends TestCase
{
    /** Un TIFF little-endian de 3 × 2 enters de 16 bits amb signe, en dues tires d'una fila. */
    private function tiff(array $files): string
    {
        $w = count($files[0]);
        $tires = array_map(fn ($fila) => pack('v*', ...array_map(fn ($v) => $v & 0xFFFF, $fila)), $files);
        $entrades = [
            [256, 3, 1, $w], [257, 3, 1, count($files)], [258, 3, 1, 16], [259, 3, 1, 1],
            [273, 4, count($tires), 0], [278, 3, 1, 1], [279, 4, count($tires), 0], [339, 3, 1, 2],
        ];
        $ifd = 8;
        $dadesIfd = 2 + count($entrades) * 12 + 4;
        $offsetsLlista = $ifd + $dadesIfd;
        $midesLlista = $offsetsLlista + 4 * count($tires);
        $primeraTira = $midesLlista + 4 * count($tires);

        $offsets = [];
        $pos = $primeraTira;
        foreach ($tires as $t) {
            $offsets[] = $pos;
            $pos += strlen($t);
        }
        $entrades[4][3] = $offsetsLlista;
        $entrades[6][3] = $midesLlista;

        $bin = 'II' . pack('v', 42) . pack('V', $ifd) . pack('v', count($entrades));
        foreach ($entrades as [$etiqueta, $tipus, $nombre, $valor]) {
            $bin .= pack('vvV', $etiqueta, $tipus, $nombre) . ($tipus === 3 && $nombre === 1 ? pack('vv', $valor, 0) : pack('V', $valor));
        }

        return $bin . pack('V', 0) . pack('V*', ...$offsets) . pack('V*', ...array_map('strlen', $tires)) . implode('', $tires);
    }

    public function test_llegeix_enters_amb_signe_per_files_de_dalt_a_baix(): void
    {
        $t = TiffSimple::llegeix($this->tiff([[0, 97, 585], [-5, 0, 3404]]));

        $this->assertSame(3, $t['amplada']);
        $this->assertSame(2, $t['alcada']);
        $this->assertSame([0, 97, 585, -5, 0, 3404], $t['valors']);
    }
}
