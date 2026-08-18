<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Vilanova de la Muga" és un nucli del municipi de PERALADA: les taxes s'hi
 * paguen a l'Ajuntament de Peralada. L'arbre de categories, en canvi, usa el
 * nucli com a node de municipi (DESPESES > IMMOBLES > VILANOVA DE LA MUGA),
 * cosa que separava el FORT 16 dels altres immobles del mateix municipi
 * (CARRER MAJOR, CAMPS) a la vista de taxes.
 *
 * Es desa com a ajust manual per categoria, que és qui mana sobre l'arbre.
 */
return new class extends Migration
{
    /** Nucli de població que a la vista de taxes s'ha de comptar dins del municipi. */
    private const NUCLI = 'VILANOVA DE LA MUGA';

    private const MUNICIPI = 'Peralada';

    public function up(): void
    {
        foreach ($this->categoriesTaxaDelNucli() as $categoriaId) {
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

        // Cap immoble no hi consta ara mateix, però el camp és editable a mà.
        DB::table('g_immobles')
            ->whereRaw('UPPER(poblacio) = ?', [self::NUCLI])
            ->update(['poblacio' => self::MUNICIPI]);
    }

    public function down(): void
    {
        foreach ($this->categoriesTaxaDelNucli() as $categoriaId) {
            DB::table('g_taxes_immobles')
                ->where('categoria_id', $categoriaId)
                ->where('poblacio', self::MUNICIPI)
                ->update(['poblacio' => null, 'updated_at' => now()]);
        }
    }

    /**
     * Categories que són taxa i pengen del node del nucli.
     *
     * @return array<int, int>
     */
    private function categoriesTaxaDelNucli(): array
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

            if ($this->penjaDelNucli($categoria, $categories, $normalitza)) {
                $ids[] = $categoria->id;
            }
        }

        return $ids;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \stdClass>  $categories
     */
    private function penjaDelNucli(object $categoria, $categories, callable $normalitza): bool
    {
        $actual = $categoria->categoria_pare_id ? $categories->get($categoria->categoria_pare_id) : null;
        $vistos = [$categoria->id => true];

        while ($actual !== null && ! isset($vistos[$actual->id])) {
            $vistos[$actual->id] = true;

            if ($normalitza($actual->nom) === self::NUCLI) {
                return true;
            }

            $actual = $actual->categoria_pare_id ? $categories->get($actual->categoria_pare_id) : null;
        }

        return false;
    }
};
