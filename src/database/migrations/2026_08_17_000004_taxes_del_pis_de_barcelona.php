<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Al compte de l'Albina el pis hi consta com a "DESPESES > PIS", sense municipi
 * enlloc: l'arbre no en diu res i no hi ha cap despesa de lloguer que el vinculi
 * a un immoble, de manera que el seu IBI queia a "Sense població".
 *
 * És un pis de BARCELONA, però no és el de Travessera de les Corts 196: aquell
 * es paga des d'un altre compte i el rebut és d'un altre import. Per això només
 * s'hi assigna el municipi i no es dona nom al grup, que els unificaria.
 */
return new class extends Migration
{
    /** Prefix del path de la categoria: hi encaixen totes les seves filles. */
    private const PREFIX = 'DESPESES > PIS > AJUNTAMENT DE BARCELONA';

    private const MUNICIPI = 'Barcelona';

    public function up(): void
    {
        foreach ($this->categoriesTaxaDelPrefix() as $categoriaId) {
            $existeix = DB::table('g_taxes_immobles')->where('categoria_id', $categoriaId)->exists();

            if ($existeix) {
                DB::table('g_taxes_immobles')->where('categoria_id', $categoriaId)
                    ->update(['poblacio' => self::MUNICIPI, 'updated_at' => now()]);

                continue;
            }

            DB::table('g_taxes_immobles')->insert([
                'categoria_id' => $categoriaId,
                'poblacio'     => self::MUNICIPI,
                'ocult'        => false,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach ($this->categoriesTaxaDelPrefix() as $categoriaId) {
            DB::table('g_taxes_immobles')
                ->where('categoria_id', $categoriaId)
                ->where('poblacio', self::MUNICIPI)
                ->update(['poblacio' => null, 'updated_at' => now()]);
        }
    }

    /**
     * Categories que són taxa i pengen del prefix (o hi són elles mateixes).
     *
     * @return array<int, int>
     */
    private function categoriesTaxaDelPrefix(): array
    {
        $normalitza = fn (?string $text) => trim(mb_strtoupper(Str::ascii((string) $text)));

        $patrons = DB::table('g_taxes_patrons')->where('actiu', true)->pluck('patro')
            ->map($normalitza)->filter()->values()->all();

        $categories = DB::table('g_categories')->get(['id', 'nom', 'categoria_pare_id'])->keyBy('id');

        $ids = [];
        foreach ($categories as $categoria) {
            $nom = $normalitza($categoria->nom);

            $esTaxa = false;
            foreach ($patrons as $patro) {
                if (str_contains($nom, $patro)) {
                    $esTaxa = true;
                    break;
                }
            }

            if (! $esTaxa) {
                continue;
            }

            $path = $this->path($categoria, $categories);
            if ($path === self::PREFIX || str_starts_with($path, self::PREFIX . ' > ')) {
                $ids[] = $categoria->id;
            }
        }

        return $ids;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \stdClass>  $categories
     */
    private function path(object $categoria, $categories): string
    {
        $segments = [];
        $actual   = $categoria;
        $vistos   = [];

        while ($actual !== null && ! isset($vistos[$actual->id])) {
            $vistos[$actual->id] = true;
            array_unshift($segments, $actual->nom);
            $actual = $actual->categoria_pare_id ? $categories->get($actual->categoria_pare_id) : null;
        }

        return implode(' > ', $segments);
    }
};
