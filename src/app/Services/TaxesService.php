<?php

namespace App\Services;

use App\Models\Categoria;
use App\Models\MovimentCompteCorrent;
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
 */
class TaxesService
{
    /**
     * Contenidors genèrics que no representen un immoble.
     *
     * @var array<int, string>
     */
    private const NODES_GENERICS = [
        'INGRESSOS',
        'DESPESES',
        'TAXES',
        'IMPOSTOS/TAXES',
        'IMPOSTOS/TAXES/TRAMITS',
    ];

    /**
     * Prefixos d'organisme recaptador (no representen un immoble).
     *
     * @var array<int, string>
     */
    private const PREFIXOS_ORGANISME = [
        'AJUNTAMENT',
        'DIPUTACI',
        'XALOC',
        'XARXA LOCAL',
    ];

    /**
     * Cache dels patrons actius resolts durant la petició.
     *
     * @var Collection<int, TaxaPatro>|null
     */
    private ?Collection $patronsCache = null;

    /**
     * Mapa categoria_id => ['tipus' => string, 'immoble' => string].
     *
     * @var array<int, array{tipus: string, immoble: string}>|null
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
     * Mapa de categories que són taxa: categoria_id => ['tipus', 'immoble'].
     *
     * @return array<int, array{tipus: string, immoble: string}>
     */
    public function categoriesTaxa(): array
    {
        if ($this->categoriesCache !== null) {
            return $this->categoriesCache;
        }

        $patrons = $this->patronsActius();
        $categories = Categoria::all();
        $perId = $categories->keyBy('id');

        $resultat = [];
        foreach ($categories as $categoria) {
            $nomNorm = self::normalitza($categoria->nom);

            $patroCoincident = $patrons->first(
                fn (TaxaPatro $p) => $p->patro !== '' && str_contains($nomNorm, self::normalitza($p->patro))
            );

            if ($patroCoincident === null) {
                continue;
            }

            $resultat[$categoria->id] = [
                'tipus'   => $patroCoincident->etiqueta,
                'immoble' => $this->etiquetaImmoble($categoria, $patroCoincident, $perId),
            ];
        }

        return $this->categoriesCache = $resultat;
    }

    /**
     * Deriva l'etiqueta d'immoble (grouping key) d'una categoria de taxa (best-effort).
     *
     * @param  Collection<int, Categoria>  $perId
     */
    private function etiquetaImmoble(Categoria $categoria, TaxaPatro $patro, Collection $perId): string
    {
        $pare = $categoria->categoria_pare_id ? $perId->get($categoria->categoria_pare_id) : null;

        // 1) Si el pare és un node d'immoble (ni genèric ni organisme), usar-lo.
        if ($pare !== null && $this->esNodeImmoble($pare->nom)) {
            return $pare->nom;
        }

        // 2) Treure el token del tipus del nom propi de la categoria.
        $resta = trim(preg_replace(
            '/\s+/', ' ',
            str_ireplace($patro->patro, '', self::llevaAccents($categoria->nom))
        ));
        // Netejar separadors i prefixos residuals (ex. "X- PIS", "- GARAIG").
        $resta = trim($resta, " -/.");
        $resta = ltrim($resta, 'X');
        $resta = trim($resta, " -/.");

        // Només acceptar la resta com a etiqueta si és prou informativa (evita
        // residus com "IES" de ESCOMBRERIES o "UA" de PLUSVÀLUA).
        if (mb_strlen($resta) >= 4) {
            return $resta;
        }

        // 3) Fallback: pare no genèric, o etiqueta general.
        if ($pare !== null && !$this->esNodeGeneric($pare->nom)) {
            return $pare->nom;
        }

        return '(General)';
    }

    private function esNodeImmoble(string $nom): bool
    {
        return !$this->esNodeGeneric($nom) && !$this->esOrganisme($nom);
    }

    private function esNodeGeneric(string $nom): bool
    {
        return in_array(self::normalitza($nom), self::NODES_GENERICS, true);
    }

    private function esOrganisme(string $nom): bool
    {
        $nomNorm = self::normalitza($nom);
        foreach (self::PREFIXOS_ORGANISME as $prefix) {
            if (str_starts_with($nomNorm, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function llevaAccents(string $text): string
    {
        return mb_strtoupper(Str::ascii($text));
    }

    /**
     * Moviments detectats com a taxa, ordenats per data descendent.
     * Cada moviment s'enriqueix amb 'tipus' i 'immoble' (via la categoria).
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
            ->with(['categoria', 'compteCorrent', 'concepte'])
            ->orderByDesc('data_moviment')
            ->orderByDesc('id');

        if ($any !== null) {
            $query->whereYear('data_moviment', $any);
        }

        return $query->get()->map(function (MovimentCompteCorrent $m) use ($categoriesTaxa) {
            $info = $categoriesTaxa[$m->categoria_id];
            $m->setAttribute('tipus_taxa', $info['tipus']);
            $m->setAttribute('immoble_taxa', $info['immoble']);

            return $m;
        });
    }
}
