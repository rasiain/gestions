<?php

namespace App\Services;

use App\Models\AssegurancaPatro;
use App\Models\AssegurancaPolissa;
use App\Models\Categoria;
use App\Models\MovimentCompteCorrent;
use App\Services\Concerns\ResolPerArbre;
use Illuminate\Support\Collection;

/**
 * Detecta les assegurances pagades des de qualsevol compte, a partir de l'arbre
 * de categories, i les agrupa per objecte assegurat.
 *
 * Dues diferències respecte de les taxes, totes dues dictades per les dades:
 *
 *   1. El patró es busca a TOT EL CAMÍ, no només a la fulla. A les taxes la
 *      fulla és la taxa (… > IBI); aquí la fulla acostuma a ser la COMPANYIA
 *      (… > BONASTRUCH DE PORTA 35 > ASSEGURANÇA > SEGURCAIXA). Val el node
 *      coincident més alt: és el de la pòlissa, i no canvia quan es canvia
 *      d'asseguradora.
 *
 *   2. La coincidència és per INICI DE PARAULA. Amb `str_contains`, "ASSEGUR"
 *      enganxaria "CAN MASSEGUR" i "L'ENSEGUR", que no són cap pòlissa.
 *
 * L'objecte assegurat es resol en tres passos, de més fiable a menys:
 *   1. L'IMMOBLE de l'arbre, igual que a les taxes (IMMOBLES > <POBLACIÓ> >
 *      <IMMOBLE> o DESPESES PROPIETATS > <IMMOBLE>).
 *   2. El LLOGUER de les despeses classificades dels seus moviments, quan
 *      l'arbre penja d'un node genèric (DESPESES > ASSEGURANCES > SEGURCAIXA).
 *   3. El PARE del node de la pòlissa, que és el que dona nom a les que no són
 *      d'immobles: MOTOR > MOTO > ASSEGURANÇA MOTO.
 *
 * El que no resol cap dels tres camins es desa a `g_assegurances_polisses`, que
 * mana per damunt de l'arbre: hi ha decisions que només l'usuari sap prendre
 * (de quin pis és un node que només diu "PIS") i pòlisses que cap patró no pot
 * enganxar pel nom (MUTUALITAT DELS ENGINYERS).
 */
class AssegurancesService
{
    use ResolPerArbre;

    /**
     * Nodes massa genèrics per anomenar l'objecte assegurat: si el pare de la
     * pòlissa és un d'aquests, val més el nom del node mateix.
     *
     * @var array<int, string>
     */
    private const NODES_GENERICS = [
        'DESPESES', 'INGRESSOS', 'SERVEIS', 'ALTRES', 'COMPRES',
        'IMMOBLES', 'DESPESES PROPIETATS',
    ];

    /** Etiqueta de la fila quan un ajust inclou una categoria sense dir-ne el tipus. */
    private const TIPUS_PER_DEFECTE = 'Assegurança';

    /** @var Collection<int, AssegurancaPatro>|null */
    private ?Collection $patronsCache = null;

    /** @var array<int, AssegurancaPolissa>|null */
    private ?array $ajustosCache = null;

    /** @var array<int, array<string, mixed>>|null */
    private ?array $categoriesCache = null;

    /**
     * El patró encaixa si comença una paraula del nom. Els dos textos han
     * d'arribar ja normalitzats.
     */
    public static function coincideix(string $nomNorm, string $patroNorm): bool
    {
        if ($patroNorm === '') {
            return false;
        }

        return (bool) preg_match('/\b' . preg_quote($patroNorm, '/') . '/u', $nomNorm);
    }

    /**
     * Patrons actius, ordenats per `ordre` (desempat de coincidència múltiple).
     *
     * @return Collection<int, AssegurancaPatro>
     */
    public function patronsActius(): Collection
    {
        return $this->patronsCache ??= AssegurancaPatro::where('actiu', true)
            ->orderBy('ordre')
            ->orderBy('id')
            ->get();
    }

    /**
     * Ajustos manuals, indexats per categoria.
     *
     * @return array<int, AssegurancaPolissa>
     */
    public function ajustos(): array
    {
        return $this->ajustosCache ??= AssegurancaPolissa::all()->keyBy('categoria_id')->all();
    }

    /**
     * Pòlissa que correspon a una cadena de categories, o null si no n'és cap.
     *
     * @param  array<int, Categoria>  $cadena  de l'arrel a la categoria
     * @param  Collection<int, AssegurancaPatro>  $patrons
     * @param  array<int, AssegurancaPolissa>  $ajustos
     * @return array<string, mixed>|null
     */
    public function resolCadena(array $cadena, Collection $patrons, array $ajustos = []): ?array
    {
        $indexNode = null;
        $tipus     = null;

        // El node més alt que encaixa: a sota hi pengen les companyies.
        foreach ($cadena as $i => $categoria) {
            $inclosa = $ajustos[$categoria->id ?? -1] ?? null;

            if ($inclosa?->inclou) {
                $indexNode = $i;
                $tipus     = $inclosa->tipus ?: self::TIPUS_PER_DEFECTE;
                break;
            }

            $nomNorm = self::normalitza($categoria->nom);

            $coincident = $patrons->first(
                fn (AssegurancaPatro $p) => self::coincideix($nomNorm, self::normalitza($p->patro))
            );

            if ($coincident !== null) {
                $indexNode = $i;
                $tipus     = $coincident->etiqueta;
                break;
            }
        }

        if ($indexNode === null) {
            return null;
        }

        // L'ajust pot estar tant a la categoria del moviment (la companyia) com
        // al node de la pòlissa (l'immoble o el municipi): valen tots dos.
        $propi    = $ajustos[end($cadena)->id ?? -1] ?? null;
        $del_node = $ajustos[$cadena[$indexNode]->id ?? -1] ?? null;
        $ajust    = fn (string $camp) => $propi?->{$camp} ?? $del_node?->{$camp};

        // L'immoble ha de ser per damunt del node de la pòlissa: el que hi ha a
        // sota (la companyia) no ho és mai.
        [$immoble, $poblacio] = $this->immobleDeLArbre($cadena, $indexNode - 1);

        return [
            'tipus'          => $ajust('tipus') ?? $tipus,
            'immoble'        => $immoble,
            'poblacio'       => self::canonitzaPoblacio($poblacio),
            'poblacio_ajust' => $ajust('poblacio'),
            // L'ajust mana sobre l'arbre: és una decisió explícita
            'objecte'        => $ajust('objecte') ?? $immoble ?? $this->objecteDelNode($cadena, $indexNode),
            'objecte_ajust'  => $ajust('objecte'),
            // El fill immediat del node de la pòlissa: qui la té contractada
            'companyia'      => $ajust('companyia')
                ?? (isset($cadena[$indexNode + 1]) ? $cadena[$indexNode + 1]->nom : null),
            'ocult'          => (bool) ($propi?->ocult || $del_node?->ocult),
        ];
    }

    /**
     * Mapa de categories que són assegurança: categoria_id => dades de l'arbre.
     *
     * @return array<int, array<string, mixed>>
     */
    public function categoriesAsseguranca(): array
    {
        if ($this->categoriesCache !== null) {
            return $this->categoriesCache;
        }

        $patrons    = $this->patronsActius();
        $ajustos    = $this->ajustos();
        $categories = Categoria::all();
        $perId      = $categories->keyBy('id');

        $resultat = [];
        foreach ($categories as $categoria) {
            $cadena  = $this->cadena($categoria, $perId);
            $polissa = $this->resolCadena($cadena, $patrons, $ajustos);

            if ($polissa === null) {
                continue;
            }

            $resultat[$categoria->id] = $polissa + [
                'path' => implode(' > ', array_map(fn (Categoria $c) => $c->nom, $cadena)),
            ];
        }

        return $this->categoriesCache = $resultat;
    }

    /**
     * Moviments detectats com a assegurança, ordenats per data descendent.
     *
     * Cada moviment s'enriqueix amb: 'tipus_asseguranca' (l'etiqueta de la
     * pòlissa), 'objecte_asseguranca' (nom del grup), 'immoble_arbre_asseguranca'
     * (l'immoble quan surt de l'arbre), 'poblacio_asseguranca',
     * 'immoble_id_asseguranca' i 'lloguer_*' (quan el vincle ve d'un lloguer),
     * 'companyia_asseguranca', 'path_asseguranca' i 'grup_asseguranca'.
     *
     * @return Collection<int, MovimentCompteCorrent>
     */
    public function moviments(?int $any = null): Collection
    {
        $categories = $this->categoriesAsseguranca();
        if ($categories === []) {
            return collect();
        }

        $query = MovimentCompteCorrent::whereIn('categoria_id', array_keys($categories))
            ->with(['categoria', 'compteCorrent', 'concepte', 'despesa.lloguer.immoble'])
            ->orderByDesc('data_moviment')
            ->orderByDesc('id');

        if ($any !== null) {
            $query->whereYear('data_moviment', $any);
        }

        $moviments = $query->get();
        $resolucio = $this->resolucioPerCategoria($moviments, $categories);

        return $moviments->map(function (MovimentCompteCorrent $m) use ($categories, $resolucio) {
            $info = $categories[$m->categoria_id];
            $res  = $resolucio[$m->categoria_id];

            $m->setAttribute('tipus_asseguranca', $info['tipus']);
            $m->setAttribute('objecte_asseguranca', $res['etiqueta']);
            $m->setAttribute('immoble_arbre_asseguranca', $info['immoble']);
            $m->setAttribute('poblacio_asseguranca', $res['poblacio']);
            $m->setAttribute('immoble_id_asseguranca', $res['immoble']?->id);
            $m->setAttribute('lloguer_id_asseguranca', $res['lloguer_id']);
            $m->setAttribute('lloguer_nom_asseguranca', $res['lloguer_nom']);
            $m->setAttribute('companyia_asseguranca', $info['companyia']);
            $m->setAttribute('path_asseguranca', $info['path']);
            $m->setAttribute('ocult_asseguranca', $info['ocult']);
            // La població forma part de la clau, com a les taxes: dos immobles
            // amb el mateix nom en municipis diferents no s'han de barrejar.
            $m->setAttribute('grup_asseguranca', 'nom:' . self::normalitza($res['etiqueta'])
                . '|' . self::normalitza($res['poblacio']));

            return $m;
        });
    }

    /**
     * Nom del grup quan l'arbre no en diu l'immoble: el pare del node de la
     * pòlissa (MOTOR > MOTO > ASSEGURANÇA MOTO → "MOTO") i, si el pare és
     * genèric, el node mateix (SERVEIS > ASSEGURANÇA DECESOS → "ASSEGURANÇA
     * DECESOS").
     *
     * @param  array<int, Categoria>  $cadena
     */
    private function objecteDelNode(array $cadena, int $indexNode): string
    {
        $pare = $cadena[$indexNode - 1] ?? null;

        if ($pare !== null && ! in_array(self::normalitza($pare->nom), self::NODES_GENERICS, true)) {
            return $pare->nom;
        }

        return $cadena[$indexNode]->nom;
    }

    /**
     * Etiqueta, municipi i immoble de cada categoria present als moviments.
     *
     * @param  Collection<int, MovimentCompteCorrent>  $moviments
     * @param  array<int, array<string, mixed>>  $categories
     * @return array<int, array<string, mixed>>
     */
    private function resolucioPerCategoria(Collection $moviments, array $categories): array
    {
        $lloguerPerCategoria = $this->lloguerPerCategoria($moviments);

        $resolucio = [];
        foreach ($moviments->pluck('categoria_id')->unique() as $categoriaId) {
            $info    = $categories[$categoriaId];
            $lloguer = $lloguerPerCategoria[$categoriaId] ?? null;
            $immoble = $lloguer['immoble'] ?? null;

            $resolucio[$categoriaId] = [
                // L'ajust manual mana; després l'arbre; després el lloguer, que
                // és qui sap de qui és una pòlissa penjada d'un node genèric.
                'etiqueta'    => $info['objecte_ajust'] ?? $info['immoble'] ?? $lloguer['lloguer_nom'] ?? $info['objecte'],
                'poblacio'    => self::canonitzaPoblacio($info['poblacio_ajust'])
                    ?? self::canonitzaPoblacio($immoble?->poblacio)
                    ?? $info['poblacio'],
                'immoble'     => $immoble,
                'lloguer_id'  => $lloguer['lloguer_id'] ?? null,
                'lloguer_nom' => $lloguer['lloguer_nom'] ?? null,
            ];
        }

        // Una etiqueta amb un únic municipi conegut el presta a les seves
        // companyes que no en tenen, perquè no facin dos grups.
        $municipisPerEtiqueta = [];
        foreach ($resolucio as $dades) {
            if ($dades['poblacio'] !== null) {
                $municipisPerEtiqueta[self::normalitza($dades['etiqueta'])][$dades['poblacio']] = true;
            }
        }

        foreach ($resolucio as $categoriaId => $dades) {
            if ($dades['poblacio'] !== null) {
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
     * dels seus moviments. Si n'hi ha més d'un, la categoria és ambigua i no
     * se n'aprofita el vincle.
     *
     * @param  Collection<int, MovimentCompteCorrent>  $moviments
     * @return array<int, array<string, mixed>>
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
