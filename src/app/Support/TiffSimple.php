<?php

namespace App\Support;

use RuntimeException;

/**
 * Llegeix un GeoTIFF senzill: una banda d'enters de 16 bits amb signe, sense comprimir i en tires.
 * És el que torna el WCS de l'IGN quan no se li demana compressió, i n'hi ha prou per fer la màscara
 * de terra amb el model d'1 km. Per als models de 2 i 5 m, comprimits, hi haurà el lector de Node.
 */
class TiffSimple
{
    /** @return array{amplada: int, alcada: int, valors: array<int, int>} per files, de dalt a baix */
    public static function llegeix(string $dades): array
    {
        if (substr($dades, 0, 2) !== 'II') {
            throw new RuntimeException('Només es llegeixen TIFF little-endian.');
        }

        $desplacament = unpack('V', substr($dades, 4, 4))[1];
        $n = unpack('v', substr($dades, $desplacament, 2))[1];
        $etiquetes = [];
        for ($i = 0; $i < $n; $i++) {
            $e = unpack('vetiqueta/vtipus/Vnombre/Vvalor', substr($dades, $desplacament + 2 + $i * 12, 12));
            // Un SHORT sol cap als dos primers bytes del camp
            $valor = $e['tipus'] === 3 && $e['nombre'] === 1 ? $e['valor'] & 0xFFFF : $e['valor'];
            $etiquetes[$e['etiqueta']] = ['tipus' => $e['tipus'], 'nombre' => $e['nombre'], 'valor' => $valor];
        }

        if (($etiquetes[259]['valor'] ?? 1) !== 1 || ($etiquetes[258]['valor'] ?? 0) !== 16) {
            throw new RuntimeException('Només es llegeixen TIFF sense comprimir de 16 bits.');
        }

        $amplada = $etiquetes[256]['valor'];
        $alcada = $etiquetes[257]['valor'];
        $llista = function (array $e) use ($dades): array {
            if ($e['nombre'] === 1) {
                return [$e['valor']];
            }
            $format = $e['tipus'] === 3 ? 'v*' : 'V*';
            $mida = $e['tipus'] === 3 ? 2 : 4;

            return array_values(unpack($format, substr($dades, $e['valor'], $e['nombre'] * $mida)));
        };

        $cru = '';
        foreach (array_map(null, $llista($etiquetes[273]), $llista($etiquetes[279])) as [$inici, $llarg]) {
            $cru .= substr($dades, $inici, $llarg);
        }

        // Enters amb signe de 16 bits, little-endian
        $valors = array_map(fn ($v) => $v >= 0x8000 ? $v - 0x10000 : $v, array_values(unpack('v*', substr($cru, 0, $amplada * $alcada * 2))));

        return ['amplada' => $amplada, 'alcada' => $alcada, 'valors' => $valors];
    }
}
