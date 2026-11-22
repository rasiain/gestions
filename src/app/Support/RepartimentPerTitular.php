<?php

namespace App\Support;

/**
 * Com es reparteix el valor d'una posició (un compte, un contracte) entre els seus titulars.
 *
 * Abans hi havia cinc còpies d'aquest càlcul —els «Totals per titular» de comptes, fons, renda
 * fixa i capital social, i l'Anàlisi mobiliari—, que havien de donar els mateixos números i que
 * només ho feien perquè eren idèntiques. Ara totes passen per aquí.
 */
final class RepartimentPerTitular
{
    /** La fila on va a parar una posició sense cap titular: no es pot repartir, però no s'ha de perdre. */
    public const SENSE_TITULAR = 'Sense titular';

    /**
     * Les parts d'un valor segons el pes de cadascú, arrodonides al cèntim.
     *
     * Amb tots els pesos a 1 és a parts iguals. L'últim s'endú el residu: arrodonir cada part per
     * separat faria que la suma dels titulars no donés el valor (100 € entre tres: 33,33 + 33,33 + 33,34).
     *
     * @param  array<int|string, float|int>  $pesos
     * @return array<int|string, float>  les parts, amb les mateixes claus que els pesos
     */
    public static function parts(float $valor, array $pesos): array
    {
        if ($pesos === []) {
            return [];
        }

        $total = array_sum($pesos) ?: count($pesos);
        $ultim = array_key_last($pesos);

        $parts    = [];
        $repartit = 0.0;

        foreach ($pesos as $clau => $pes) {
            $part = $clau === $ultim
                ? round($valor - $repartit, 2)
                : round($valor * $pes / $total, 2);
            $repartit += $part;
            $parts[$clau] = $part;
        }

        return $parts;
    }

    /**
     * Els totals per titular d'un conjunt de posicions, repartides a parts iguals.
     *
     * Cada posició porta el `valor`, els `titulars` (`id` i `nom`; cap titular va a «Sense titular»)
     * i el `detall` que la pantalla en vol mostrar, al qual s'hi afegeixen quants `titulars` té i la
     * `part` de cadascú. Torna una fila per titular, de més a menys total.
     *
     * @param  iterable<array{valor: float|int|string, titulars: iterable<array{id: int|null, nom: string}>, detall: array<string, mixed>}>  $posicions
     * @param  string  $clauDetall  com es diu la llista de posicions de cada titular (`comptes`, `contractes`)
     * @return array<int, array<string, mixed>>
     */
    public static function totals(iterable $posicions, string $clauDetall): array
    {
        $perTitular = [];

        foreach ($posicions as $posicio) {
            $valor    = (float) $posicio['valor'];
            $titulars = collect($posicio['titulars'])->values()->all() ?: [['id' => null, 'nom' => self::SENSE_TITULAR]];
            $parts    = self::parts($valor, array_fill(0, count($titulars), 1));

            foreach ($titulars as $i => $titular) {
                $clau = $titular['id'] ?? 'sense';

                $perTitular[$clau] ??= [
                    'id'        => $titular['id'],
                    'nom'       => $titular['nom'],
                    'total'     => 0.0,
                    $clauDetall => [],
                ];

                $perTitular[$clau]['total'] += $parts[$i];
                $perTitular[$clau][$clauDetall][] = $posicio['detall'] + [
                    'titulars' => count($titulars),
                    'part'     => $parts[$i],
                ];
            }
        }

        return collect($perTitular)
            ->map(fn (array $t) => ['total' => round($t['total'], 2)] + $t)
            ->sortByDesc('total')
            ->values()
            ->all();
    }
}
