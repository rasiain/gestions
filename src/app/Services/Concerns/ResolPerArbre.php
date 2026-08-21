<?php

namespace App\Services\Concerns;

use App\Models\Categoria;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Lectura de l'arbre de categories compartida per les vistes derivades
 * (taxes, assegurances): totes dues han de resoldre l'immoble i el municipi
 * exactament igual, o el mateix immoble sortiria amb dos noms segons la vista.
 */
trait ResolPerArbre
{
    /** Node de l'arbre sota el qual hi ha <POBLACIÓ> > <IMMOBLE>. */
    private const NODE_IMMOBLES = 'IMMOBLES';

    /** Node de l'arbre sota el qual hi ha directament <IMMOBLE>. */
    private const NODE_PROPIETATS = 'DESPESES PROPIETATS';

    /**
     * Partícules que no van en majúscula enmig d'un nom de municipi.
     *
     * @var array<int, string>
     */
    private const PARTICULES = ['de', 'del', 'dels', 'la', 'les', 'el', 'els', 'i', "d'"];

    /**
     * Normalitza un text per comparar (sense accents, majúscules, sense espais als extrems).
     */
    public static function normalitza(?string $text): string
    {
        return trim(mb_strtoupper(Str::ascii((string) $text)));
    }

    /**
     * Els noms de municipi de l'arbre són en majúscules: "VILANOVA DE LA MUGA"
     * i "Vilanova de la Muga" han de ser el mateix grup i llegir-se bé.
     */
    public static function canonitzaPoblacio(?string $poblacio): ?string
    {
        if ($poblacio === null || trim($poblacio) === '') {
            return null;
        }

        // L'apòstrof tipogràfic ve de les adreces cadastrals ("PLATJA D’ARO"):
        // sense unificar-lo faria grup a part del "Platja d'Aro" escrit a mà.
        $poblacio = str_replace(['’', '‘', 'ʼ', '´'], "'", $poblacio);

        $paraules = preg_split('/\s+/', trim(mb_strtolower($poblacio)));

        $canonic = array_map(function (string $paraula, int $i) {
            if ($i > 0 && in_array($paraula, self::PARTICULES, true)) {
                return $paraula;
            }

            // "d'aro" → "d'Aro"
            if (preg_match("/^(d')(.+)$/u", $paraula, $m)) {
                return ($i > 0 ? $m[1] : mb_strtoupper(mb_substr($m[1], 0, 1)) . mb_substr($m[1], 1))
                    . mb_strtoupper(mb_substr($m[2], 0, 1)) . mb_substr($m[2], 1);
            }

            return mb_strtoupper(mb_substr($paraula, 0, 1)) . mb_substr($paraula, 1);
        }, $paraules, array_keys($paraules));

        return implode(' ', $canonic);
    }

    /**
     * Cadena de categories de l'arrel fins a la categoria indicada.
     *
     * @param  Collection<int, Categoria>  $perId
     * @return array<int, Categoria>
     */
    protected function cadena(Categoria $categoria, Collection $perId): array
    {
        $cadena = [];
        $actual = $categoria;
        $vistos = [];

        while ($actual !== null && !isset($vistos[$actual->id])) {
            $vistos[$actual->id] = true;
            array_unshift($cadena, $actual);
            $actual = $actual->categoria_pare_id ? $perId->get($actual->categoria_pare_id) : null;
        }

        return $cadena;
    }

    /**
     * Immoble i població segons la posició dins de l'arbre.
     *
     * @param  array<int, Categoria>  $cadena  de l'arrel a la categoria de la despesa
     * @param  int  $ultimNodeImmoble  últim índex de la cadena que pot ser l'immoble.
     *                                 A les taxes és el pare de la fulla; a les
     *                                 assegurances, el pare del node de la pòlissa
     *                                 (que sovint té la companyia a sota).
     * @return array{0: ?string, 1: ?string}
     */
    protected function immobleDeLArbre(array $cadena, int $ultimNodeImmoble): array
    {
        $noms = array_map(fn (Categoria $c) => self::normalitza($c->nom), $cadena);

        $i = array_search(self::NODE_IMMOBLES, $noms, true);
        if ($i !== false) {
            return [
                $i + 2 <= $ultimNodeImmoble ? $cadena[$i + 2]->nom : null,
                $cadena[$i + 1]->nom ?? null,
            ];
        }

        $i = array_search(self::NODE_PROPIETATS, $noms, true);
        if ($i !== false) {
            return [
                $i + 1 <= $ultimNodeImmoble ? $cadena[$i + 1]->nom : null,
                null,
            ];
        }

        return [null, null];
    }
}
