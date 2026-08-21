<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Ajustos manuals de la vista d'assegurances, per categoria: el que ni l'arbre
 * ni les despeses de lloguer poden dir.
 *
 * És el bessó de `g_taxes_immobles`, amb dos camps més que les taxes no
 * necessiten: `inclou`, per a les pòlisses que cap patró no pot enganxar pel
 * nom (MUTUALITAT DELS ENGINYERS), i `companyia`, per unificar l'asseguradora
 * quan l'arbre la deixa escrita de maneres diferents (CATALANA OCCIDENTE /
 * CATALANA OCCIDENT / OCCIDENT són la mateixa).
 */
return new class extends Migration
{
    /**
     * Decisions preses sobre les dades actuals. La clau és el prefix del path
     * complet: hi encaixen totes les categories que en pengen.
     *
     * @var array<string, array{objecte?: string, poblacio?: string, tipus?: string, inclou?: bool, ocult?: bool}>
     */
    private const AJUSTOS = [
        // Municipi que l'arbre no diu (les mateixes decisions que a les taxes)
        'DESPESES > DESPESES PROPIETATS > ST.ANTONI MN 16, BAIXOS'    => ['poblacio' => 'Sant Antoni de Calonge'],
        'DESPESES > DESPESES PROPIETATS > ST.ANTONI MN 16, 1ER 1A'    => ['poblacio' => 'Sant Antoni de Calonge'],
        'DESPESES > DESPESES PROPIETATS > ST.ANTONI MN 16, 1ER 2A'    => ['poblacio' => 'Sant Antoni de Calonge'],
        'DESPESES > DESPESES PROPIETATS > ST.ANTONI MN 16, ÀTIC'      => ['poblacio' => 'Sant Antoni de Calonge'],
        'DESPESES > DESPESES PROPIETATS > ST. ANTONI MN 16, COMUNITAT' => ['poblacio' => 'Sant Antoni de Calonge'],
        'DESPESES > DESPESES PROPIETATS > ST.ANTONI, GENERAL'         => ['poblacio' => 'Sant Antoni de Calonge'],
        'DESPESES > IMMOBLES > SANT ANTONI'                           => ['poblacio' => 'Sant Antoni de Calonge'],
        'DESPESES > DESPESES PROPIETATS > JOAN MARAGALL 33 PRAL 1A'   => ['poblacio' => 'Girona'],
        'DESPESES > DESPESES PROPIETATS > JOAN MARAGALL 33 PRAL 2A'   => ['poblacio' => 'Girona'],
        'DESPESES > DESPESES PROPIETATS > RUTLLA 11 2ON 2A'           => ['poblacio' => 'Girona'],
        'DESPESES > DESPESES PROPIETATS > STA.EUGÈNIA 5, 3ER 1A'      => ['poblacio' => 'Girona'],

        // "PIS" no diu de quin pis és: és el de la Laia, a Barcelona
        'DESPESES > FILLS > LAIA > DESPESES BARCELONA > PIS' => ['objecte' => 'PIS LAIA', 'poblacio' => 'Barcelona'],

        // Assegurança que cap patró raonable no pot enganxar pel nom
        'DESPESES > SERVEIS > MUTUALITAT DELS ENGINYERS' => ['inclou' => true, 'tipus' => 'Mutualitat'],
    ];

    /**
     * Nom de l'asseguradora tal com surt a l'arbre → nom unificat. Només
     * afecta com es llegeix la columna: no agrupa res.
     *
     * @var array<string, string>
     */
    private const COMPANYIES = [
        'CATALANA OCCIDENTE'                  => 'OCCIDENT',
        'CATALANA OCCIDENT'                   => 'OCCIDENT',
        'AXA SEG. GENERALES'                  => 'AXA',
        'REALE SEGUROS GENERALES S.A.'        => 'REALE',
        'BILBAO C. A. DE SEGUROS Y REASEGURO' => 'BILBAO',
        'SEGURCAIXA NEGOCI'                   => 'SEGURCAIXA',
    ];

    public function up(): void
    {
        Schema::create('g_assegurances_polisses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('categoria_id')->unique()->constrained('g_categories')->cascadeOnDelete();
            $table->string('objecte', 150)->nullable();   // Nom del grup: unifica dues categories i en corregeix el nom
            $table->string('poblacio', 100)->nullable();
            $table->string('companyia', 100)->nullable(); // Asseguradora unificada
            $table->string('tipus', 60)->nullable();      // Etiqueta de la fila, per damunt de la del patró
            $table->boolean('inclou')->default(false);    // Pòlissa que cap patró no detecta
            $table->boolean('ocult')->default(false);     // Detectada però que no és cap pòlissa
            $table->timestamps();
        });

        $paths     = $this->pathsPerCategoria();
        $detectada = $this->categoriesAsseguranca($paths);

        // Un sol registre per categoria: els dos mapes s'hi acumulen.
        $files = [];

        foreach ($paths as $categoriaId => $path) {
            foreach (self::AJUSTOS as $prefix => $ajust) {
                if ($path !== $prefix && ! str_starts_with($path, $prefix . ' > ')) {
                    continue;
                }

                // `inclou` és del node de la pòlissa i prou: les seves filles
                // (les companyies) ja hi entren pel node, i marcar-les no diria
                // res que no se sàpiga.
                if ($path !== $prefix) {
                    unset($ajust['inclou'], $ajust['tipus']);
                }

                if ($ajust !== []) {
                    $files[$categoriaId] = ($files[$categoriaId] ?? []) + $ajust;
                }

                break;
            }
        }

        $normalitza = fn (?string $text) => trim(mb_strtoupper(Str::ascii((string) $text)));

        foreach (DB::table('g_categories')->get(['id', 'nom']) as $categoria) {
            $unificada = self::COMPANYIES[$normalitza($categoria->nom)] ?? null;

            if ($unificada !== null) {
                $files[$categoria->id] = ($files[$categoria->id] ?? []) + ['companyia' => $unificada];
            }
        }

        $ara = now();

        foreach ($files as $categoriaId => $ajust) {
            // La resta de l'arbre (aigua, llum, taxes…) no surt mai en aquesta
            // vista: només s'hi desa el que hi pot arribar.
            if (! isset($detectada[$categoriaId]) && ! ($ajust['inclou'] ?? false)) {
                continue;
            }

            DB::table('g_assegurances_polisses')->insert([
                'categoria_id' => $categoriaId,
                'objecte'      => $ajust['objecte'] ?? null,
                'poblacio'     => $ajust['poblacio'] ?? null,
                'companyia'    => $ajust['companyia'] ?? null,
                'tipus'        => $ajust['tipus'] ?? null,
                'inclou'       => $ajust['inclou'] ?? false,
                'ocult'        => $ajust['ocult'] ?? false,
                'created_at'   => $ara,
                'updated_at'   => $ara,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('g_assegurances_polisses');
    }

    /**
     * Categories que la vista detecta: les que tenen un node del seu path que
     * encaixa amb un patró actiu (inici de paraula).
     *
     * @param  array<int, string>  $paths
     * @return array<int, true>
     */
    private function categoriesAsseguranca(array $paths): array
    {
        $normalitza = fn (?string $text) => trim(mb_strtoupper(Str::ascii((string) $text)));

        $patrons = DB::table('g_assegurances_patrons')->where('actiu', true)->pluck('patro')
            ->map($normalitza)->filter()->values()->all();

        $detectada = [];
        foreach ($paths as $categoriaId => $path) {
            foreach (explode(' > ', $normalitza($path)) as $node) {
                foreach ($patrons as $patro) {
                    if (preg_match('/\b' . preg_quote($patro, '/') . '/u', $node)) {
                        $detectada[$categoriaId] = true;
                        continue 3;
                    }
                }
            }
        }

        return $detectada;
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
