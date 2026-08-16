<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ajustos manuals de la vista de taxes: l'arbre de categories no sempre diu de
 * quin immoble o municipi és una taxa, i hi ha casos que només l'usuari sap
 * resoldre (dues categories que són el mateix immoble, o impostos que no en són
 * de cap). Aquí s'hi desa la decisió, per categoria.
 */
return new class extends Migration
{
    /**
     * Decisions preses sobre les dades actuals. La clau és el prefix del path
     * complet de la categoria: hi encaixen totes les seves filles.
     *
     * @var array<string, array{immoble?: string, poblacio?: string, ocult?: bool}>
     */
    private const AJUSTOS = [
        // Municipi que l'arbre no diu
        'DESPESES > DESPESES PROPIETATS > JOAN MARAGALL 31, PLAÇA 5 GARAIG' => ['poblacio' => 'Girona'],
        'DESPESES > DESPESES PROPIETATS > JOAN MARAGALL 33 PRAL 1A'         => ['poblacio' => 'Girona'],
        'DESPESES > DESPESES PROPIETATS > JOAN MARAGALL 33 PRAL 2A'         => ['poblacio' => 'Girona'],
        'DESPESES > DESPESES PROPIETATS > ST.ANTONI MN 16, ÀTIC'            => ['poblacio' => 'Sant Antoni de Calonge'],
        'DESPESES > DESPESES PROPIETATS > ST.ANTONI MN 16, BAIXOS'          => ['poblacio' => 'Sant Antoni de Calonge'],
        'DESPESES > DESPESES PROPIETATS > ST.ANTONI MN 16, 1ER 1A'          => ['poblacio' => 'Sant Antoni de Calonge'],
        'DESPESES > DESPESES PROPIETATS > ST.ANTONI MN 16, 1ER 2A'          => ['poblacio' => 'Sant Antoni de Calonge'],
        'DESPESES > DESPESES PROPIETATS > ST.ANTONI, GENERAL'               => ['poblacio' => 'Sant Antoni de Calonge'],
        // A l'arbre el municipi hi consta com a "SANT ANTONI"
        'DESPESES > IMMOBLES > SANT ANTONI'                                 => ['poblacio' => 'Sant Antoni de Calonge'],

        // Immoble que l'arbre no diu, o que cal unificar
        'DESPESES > DESPESES PROPIETATS > COLLADO MEDIANO'                  => ['immoble' => 'COLLADO MEDIANO', 'poblacio' => 'Collado Mediano'],
        'DESPESES > IMPOSTOS/TAXES > AJUNTAMENT COLLADO MEDIANO'            => ['immoble' => 'COLLADO MEDIANO', 'poblacio' => 'Collado Mediano'],
        'DESPESES > IMPOSTOS/TAXES > AJUNTAMENT DE PERALADA > IBI CARRER MAJOR' => ['immoble' => 'CARRER MAJOR', 'poblacio' => 'Peralada'],
        'DESPESES > IMPOSTOS/TAXES > AJUNTAMENT DE PERALADA > IBI CAMPS'    => ['immoble' => 'CAMPS', 'poblacio' => 'Peralada'],
        'DESPESES > IMPOSTOS/TAXES > AJUNTAMENT DE FIGUERES > IBI CAMPS'    => ['immoble' => 'CAMPS', 'poblacio' => 'Figueres'],
        'DESPESES > IMMOBLES > PEDRET I MARZÀ > IBI CAMPS'                  => ['immoble' => 'CAMPS', 'poblacio' => 'Pedret i Marzà'],
        // El gual és dels baixos: s'ha d'agrupar amb ells
        'DESPESES > IMMOBLES > SALT > ÀNGEL GUIMERÀ 23 GUAL'                => ['immoble' => 'ÀNGEL GUIMERÀ 23 BAIXOS', 'poblacio' => 'Salt'],

        // Impostos puntuals que no són d'un immoble
        'DESPESES > IMPOSTOS/TAXES/TRÀMITS > IMPOST SUCCESSIONS'            => ['ocult' => true],
        'DESPESES > IMPOSTOS/TAXES/TRÀMITS > PLUSVÀLUA'                     => ['ocult' => true],
    ];

    public function up(): void
    {
        Schema::create('g_taxes_immobles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('categoria_id')->unique()->constrained('g_categories')->cascadeOnDelete();
            $table->string('immoble', 150)->nullable();
            $table->string('poblacio', 100)->nullable();
            $table->boolean('ocult')->default(false);
            $table->timestamps();
        });

        // Només les categories que són taxa: la resta de l'arbre (aigua, llum,
        // assegurances…) no surt mai en aquesta vista.
        $paths = array_intersect_key($this->pathsPerCategoria(), array_flip($this->categoriesTaxa()));
        $ara   = now();

        foreach ($paths as $categoriaId => $path) {
            foreach (self::AJUSTOS as $prefix => $ajust) {
                if ($path !== $prefix && !str_starts_with($path, $prefix . ' > ')) {
                    continue;
                }

                DB::table('g_taxes_immobles')->insert([
                    'categoria_id' => $categoriaId,
                    'immoble'      => $ajust['immoble'] ?? null,
                    'poblacio'     => $ajust['poblacio'] ?? null,
                    'ocult'        => $ajust['ocult'] ?? false,
                    'created_at'   => $ara,
                    'updated_at'   => $ara,
                ]);
                break;
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('g_taxes_immobles');
    }

    /**
     * Ids de les categories el nom de les quals encaixa amb un patró de taxa actiu.
     *
     * @return array<int, int>
     */
    private function categoriesTaxa(): array
    {
        $normalitza = fn (?string $text) => trim(mb_strtoupper(\Illuminate\Support\Str::ascii((string) $text)));

        $patrons = DB::table('g_taxes_patrons')->where('actiu', true)->pluck('patro')
            ->map($normalitza)->filter()->values()->all();

        return DB::table('g_categories')->get(['id', 'nom'])
            ->filter(function ($categoria) use ($patrons, $normalitza) {
                $nom = $normalitza($categoria->nom);

                foreach ($patrons as $patro) {
                    if (str_contains($nom, $patro)) {
                        return true;
                    }
                }

                return false;
            })
            ->pluck('id')
            ->all();
    }

    /**
     * Path complet (arrel > ... > fulla) de cada categoria.
     *
     * @return array<int, string>
     */
    private function pathsPerCategoria(): array
    {
        $categories = DB::table('g_categories')->get(['id', 'nom', 'categoria_pare_id'])->keyBy('id');

        $paths = [];
        foreach ($categories as $categoria) {
            $segments = [];
            $actual   = $categoria;
            $vistos   = [];

            while ($actual !== null && !isset($vistos[$actual->id])) {
                $vistos[$actual->id] = true;
                array_unshift($segments, $actual->nom);
                $actual = $actual->categoria_pare_id ? $categories->get($actual->categoria_pare_id) : null;
            }

            $paths[$categoria->id] = implode(' > ', $segments);
        }

        return $paths;
    }
};
