<?php

namespace App\Services;

use App\Models\Categoria;
use App\Models\MovimentCompteCorrent;
use App\Models\TaxaImmoble;
use App\Models\TaxaPatro;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Detecta i agrega els impostos municipals (taxes) pagats a ajuntaments i
 * organismes recaptadors, a partir del nom de la categoria bancària.
 *
 * Enfocament: filtrar per PATRÓ DE NOM DE CATEGORIA (configurable a g_taxes_patrons),
 * agregant els arbres de categories de tots els comptes corrents. El concepte bancari
 * no és fiable (molts impostos es cobren via XALOC/Diputació i no diuen "ajuntament").
 *
 * L'immoble d'una taxa es resol en dos passos:
 *   1. L'ARBRE de categories, que ja el codifica en dues formes:
 *        DESPESES > DESPESES PROPIETATS > <IMMOBLE> > <taxa>
 *        DESPESES > IMMOBLES > <POBLACIÓ> > <IMMOBLE> > [TAXES >] <taxa>
 *   2. Les DESPESES DE LLOGUER dels seus moviments, que és l'únic senyal quan la
 *      categoria penja d'un organisme (DESPESES > IMPOSTOS/TAXES > AJUNTAMENT X > IBI):
 *      dues categories amb el mateix path però de comptes diferents poden ser
 *      immobles diferents.
 *
 * L'arbre mana sobre les despeses per anomenar el grup; les despeses hi afegeixen
 * el vincle amb l'immoble real. El que no es resol per cap dels dos camins es
 * marca com a no identificat i s'acompanya del path complet.
 */
class TaxesService
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
     * Cache dels patrons actius resolts durant la petició.
     *
     * @var Collection<int, TaxaPatro>|null
     */
    private ?Collection $patronsCache = null;

    /**
     * @var array<int, array{tipus: string, immoble: ?string, poblacio: ?string, ocult: bool, path: string}>|null
     */
    private ?array $categoriesCache = null;

    /**
     * Normalitza un text per comparar (sense accents, majúscules, sense espais als extrems).
     */
    public static function normalitza(?string $text): string
    {
        return trim(mb_strtoupper(Str::ascii((string) $text)));
    }

    /**
     * Patrons actius, ordenats per `ordre` (desempat de coincidència múltiple).
     *
     * @return Collection<int, TaxaPatro>
     */
    public function patronsActius(): Collection
    {
        return $this->patronsCache ??= TaxaPatro::where('actiu', true)
            ->orderBy('ordre')
            ->orderBy('id')
            ->get();
    }

    /**
     * Mapa de categories que són taxa: categoria_id => dades derivades de l'arbre.
     *
     * @return array<int, array{tipus: string, immoble: ?string, poblacio: ?string, ocult: bool, path: string}>
     */
    public function categoriesTaxa(): array
    {
        if ($this->categoriesCache !== null) {
            return $this->categoriesCache;
        }

        $patrons = $this->patronsActius();
        $categories = Categoria::all();
        $perId = $categories->keyBy('id');
        $ajustos = TaxaImmoble::all()->keyBy('categoria_id');

        $resultat = [];
        foreach ($categories as $categoria) {
            $nomNorm = self::normalitza($categoria->nom);

            $patroCoincident = $patrons->first(
                fn (TaxaPatro $p) => $p->patro !== '' && str_contains($nomNorm, self::normalitza($p->patro))
            );

            if ($patroCoincident === null) {
                continue;
            }

            $cadena = $this->cadena($categoria, $perId);
            [$immoble, $poblacio] = $this->immobleDeLArbre($cadena);
            $ajust = $ajustos->get($categoria->id);

            $resultat[$categoria->id] = [
                'tipus'    => $patroCoincident->etiqueta,
                // L'ajust manual mana sobre l'arbre: és una decisió explícita
                'immoble'        => $ajust?->immoble ?? $immoble,
                'poblacio'       => self::canonitzaPoblacio($poblacio),
                'poblacio_ajust' => $ajust?->poblacio,
                'ocult'          => (bool) $ajust?->ocult,
                'path'     => implode(' > ', array_map(fn (Categoria $c) => $c->nom, $cadena)),
            ];
        }

        return $this->categoriesCache = $resultat;
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
    private function cadena(Categoria $categoria, Collection $perId): array
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
     * @param  array<int, Categoria>  $cadena  de l'arrel a la categoria de la taxa
     * @return array{0: ?string, 1: ?string}
     */
    private function immobleDeLArbre(array $cadena): array
    {
        $noms = array_map(fn (Categoria $c) => self::normalitza($c->nom), $cadena);
        // L'últim element és la categoria de la taxa: mai no és el node de l'immoble.
        $ultimNodeImmoble = count($cadena) - 2;

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

    /**
     * Moviments detectats com a taxa, ordenats per data descendent.
     *
     * Cada moviment s'enriqueix amb: 'tipus_taxa', 'immoble_taxa' (etiqueta del grup,
     * null si no s'ha pogut identificar), 'poblacio_taxa', 'immoble_id_taxa' (immoble
     * real quan el vincle ve d'un lloguer), 'lloguer_nom_taxa', 'path_taxa' i
     * 'grup_taxa' (clau d'agrupació estable).
     *
     * @return \Illuminate\Support\Collection<int, MovimentCompteCorrent>
     */
    public function moviments(?int $any = null): Collection
    {
        $categoriesTaxa = $this->categoriesTaxa();
        if ($categoriesTaxa === []) {
            return collect();
        }

        $query = MovimentCompteCorrent::whereIn('categoria_id', array_keys($categoriesTaxa))
            ->with(['categoria', 'compteCorrent', 'concepte', 'despesa.lloguer.immoble'])
            ->orderByDesc('data_moviment')
            ->orderByDesc('id');

        if ($any !== null) {
            $query->whereYear('data_moviment', $any);
        }

        $moviments = $query->get();
        $resolucio = $this->resolucioPerCategoria($moviments, $categoriesTaxa);

        return $moviments->map(function (MovimentCompteCorrent $m) use ($categoriesTaxa, $resolucio) {
            $info    = $categoriesTaxa[$m->categoria_id];
            $immoble = $resolucio[$m->categoria_id]['immoble'];

            $etiqueta = $resolucio[$m->categoria_id]['etiqueta'];
            $poblacio = $resolucio[$m->categoria_id]['poblacio'];

            $m->setAttribute('tipus_taxa', $info['tipus']);
            $m->setAttribute('immoble_taxa', $etiqueta);
            $m->setAttribute('poblacio_taxa', $poblacio);
            $m->setAttribute('immoble_id_taxa', $immoble?->id);
            $m->setAttribute('lloguer_id_taxa', $resolucio[$m->categoria_id]['lloguer_id']);
            $m->setAttribute('lloguer_nom_taxa', $resolucio[$m->categoria_id]['lloguer_nom']);
            $m->setAttribute('path_taxa', $info['path']);
            $m->setAttribute('ocult_taxa', $info['ocult']);
            // La població forma part de la clau: dues finques "Camps" de municipis
            // diferents no s'han de barrejar.
            $m->setAttribute('grup_taxa', match (true) {
                $etiqueta !== null => 'nom:' . self::normalitza($etiqueta) . '|' . self::normalitza($poblacio),
                $immoble !== null  => 'immoble:' . $immoble->id,
                default            => 'sense:' . $m->categoria_id,
            });

            return $m;
        });
    }

    /**
     * Etiqueta, municipi i immoble de cada categoria present als moviments.
     *
     * @param  Collection<int, MovimentCompteCorrent>  $moviments
     * @param  array<int, array{tipus: string, immoble: ?string, poblacio: ?string, poblacio_ajust: ?string, ocult: bool, path: string}>  $categoriesTaxa
     * @return array<int, array{etiqueta: ?string, poblacio: ?string, immoble: ?\App\Models\Immoble, lloguer_nom: ?string}>
     */
    private function resolucioPerCategoria(Collection $moviments, array $categoriesTaxa): array
    {
        $lloguerPerCategoria = $this->lloguerPerCategoria($moviments);

        $resolucio = [];
        foreach ($moviments->pluck('categoria_id')->unique() as $categoriaId) {
            $info    = $categoriesTaxa[$categoriaId];
            $lloguer = $lloguerPerCategoria[$categoriaId] ?? null;
            $immoble = $lloguer['immoble'] ?? null;

            $resolucio[$categoriaId] = [
                'etiqueta'    => $info['immoble'] ?? $lloguer['lloguer_nom'] ?? null,
                // Les tres fonts es canonitzen igual: "SALT" a g_immobles i "Salt"
                // vingut de l'arbre són el mateix municipi i han de fer un sol grup.
                // (canonitzaPoblacio() torna null si el valor és buit, de manera que
                // una població en blanc segueix caient a la font següent.)
                'poblacio'    => self::canonitzaPoblacio($info['poblacio_ajust'])
                    ?? self::canonitzaPoblacio($immoble?->poblacio)
                    ?? $info['poblacio'],
                'immoble'     => $immoble,
                'lloguer_id'  => $lloguer['lloguer_id'] ?? null,
                'lloguer_nom' => $lloguer['lloguer_nom'] ?? null,
            ];
        }

        // Una etiqueta amb un únic municipi conegut el presta a les seves companyes
        // que no en tenen: "IBI RUTLLA" i "ESCOMBRARIES RUTLLA 11, 2N-2A" són el
        // mateix immoble encara que només una de les dues el sàpiga.
        $municipisPerEtiqueta = [];
        foreach ($resolucio as $dades) {
            if ($dades['etiqueta'] !== null && $dades['poblacio'] !== null) {
                $municipisPerEtiqueta[self::normalitza($dades['etiqueta'])][$dades['poblacio']] = true;
            }
        }

        foreach ($resolucio as $categoriaId => $dades) {
            if ($dades['poblacio'] !== null || $dades['etiqueta'] === null) {
                continue;
            }

            $candidats = array_keys($municipisPerEtiqueta[self::normalitza($dades['etiqueta'])] ?? []);
            if (count($candidats) === 1) {
                $resolucio[$categoriaId]['poblacio'] = $candidats[0];
            }
        }

        return $resolucio;
    }

    /**
     * Immoble de lloguer de cada categoria, deduït de les despeses classificades
     * dels seus moviments. Si n'hi ha més d'un, la categoria es considera ambigua
     * i no se n'aprofita el vincle.
     *
     * @param  Collection<int, MovimentCompteCorrent>  $moviments
     * @return array<int, array{immoble: \App\Models\Immoble, lloguer_nom: ?string}>
     */
    private function lloguerPerCategoria(Collection $moviments): array
    {
        $resultat = [];

        foreach ($moviments->groupBy('categoria_id') as $categoriaId => $movs) {
            $lloguers = $movs->map(fn (MovimentCompteCorrent $m) => $m->despesa?->lloguer)
                ->filter(fn ($lloguer) => $lloguer?->immoble !== null)
                ->unique(fn ($lloguer) => $lloguer->immoble->id)
                ->values();

            if ($lloguers->count() === 1) {
                $resultat[(int) $categoriaId] = [
                    'immoble'     => $lloguers->first()->immoble,
                    'lloguer_id'  => $lloguers->first()->id,
                    'lloguer_nom' => $lloguers->first()->nom,
                ];
            }
        }

        return $resultat;
    }
}
