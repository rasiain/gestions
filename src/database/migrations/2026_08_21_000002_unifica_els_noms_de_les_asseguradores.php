<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Una asseguradora, un nom.
 *
 * L'arbre de categories les escriu de maneres diferents segons qui va crear la
 * categoria i quan: tres grafies d'Occident, el nom llarg de l'entitat en unes
 * i el curt en altres. La vista d'assegurances ho tapava amb el camp
 * `companyia` de `g_assegurances_polisses`, però un àlies només arregla una
 * columna d'una pantalla: a Moviments i a Categories continuaven sortint els
 * noms vells. El nom s'unifica a l'arbre, que és d'on el llegeix tothom.
 *
 * SEGURCAIXA NEGOCI hi entra per decisió expressa, tot i que "NEGOCI" era el
 * producte i no la companyia.
 *
 * No es pot desfer: un cop unificats, res no diu quin nom tenia cada categoria.
 */
return new class extends Migration
{
    /**
     * Nom a l'arbre (normalitzat) → nom unificat.
     *
     * @var array<string, string>
     */
    private const NOMS = [
        'CATALANA OCCIDENTE'                  => 'OCCIDENT',
        'CATALANA OCCIDENT'                   => 'OCCIDENT',
        'AXA SEG. GENERALES'                  => 'AXA',
        'BILBAO C. A. DE SEGUROS Y REASEGURO' => 'BILBAO',
        'REALE SEGUROS GENERALES S.A.'        => 'REALE',
        'SEGURCAIXA NEGOCI'                   => 'SEGURCAIXA',
    ];

    public function up(): void
    {
        $normalitza = fn (?string $text) => trim(mb_strtoupper(Str::ascii((string) $text)));

        $categories = DB::table('g_categories')->get(['id', 'nom', 'categoria_pare_id']);

        // Noms que ja hi ha sota cada pare: unificar no pot deixar dues
        // germanes amb el mateix nom.
        $germanes = [];
        foreach ($categories as $categoria) {
            $germanes[$categoria->categoria_pare_id][$normalitza($categoria->nom)] = true;
        }

        foreach ($categories as $categoria) {
            $unificat = self::NOMS[$normalitza($categoria->nom)] ?? null;

            if ($unificat === null || isset($germanes[$categoria->categoria_pare_id][$normalitza($unificat)])) {
                continue;
            }

            DB::table('g_categories')->where('id', $categoria->id)->update([
                'nom'        => $unificat,
                'updated_at' => now(),
            ]);

            $germanes[$categoria->categoria_pare_id][$normalitza($unificat)] = true;
        }

        $this->netejaElsAlies($normalitza);
    }

    public function down(): void
    {
        // Irreversible: els noms unificats no diuen quin tenia cada categoria.
    }

    /**
     * Esborra els àlies de `companyia` que ja no diuen res que el nom de la
     * categoria no digui. Els d'una categoria que no s'hagi pogut reanomenar hi
     * queden, que és el que toca.
     *
     * @param  callable(?string): string  $normalitza
     */
    private function netejaElsAlies(callable $normalitza): void
    {
        $noms = DB::table('g_categories')->pluck('nom', 'id');

        $redundants = DB::table('g_assegurances_polisses')
            ->whereNotNull('companyia')
            ->get(['id', 'categoria_id', 'companyia'])
            ->filter(fn ($ajust) => $normalitza($noms[$ajust->categoria_id] ?? null) === $normalitza($ajust->companyia))
            ->pluck('id');

        if ($redundants->isEmpty()) {
            return;
        }

        DB::table('g_assegurances_polisses')->whereIn('id', $redundants)
            ->update(['companyia' => null, 'updated_at' => now()]);

        // Els ajustos que només servien per a l'àlies queden buits
        DB::table('g_assegurances_polisses')
            ->whereNull('objecte')
            ->whereNull('poblacio')
            ->whereNull('companyia')
            ->whereNull('tipus')
            ->where('inclou', false)
            ->where('ocult', false)
            ->delete();
    }
};
