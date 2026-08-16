<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * La població no es podia saber sense llegir el text lliure de l'adreça, i cal
 * per agrupar els immobles a la vista de taxes. Es precarrega a partir de
 * l'adreça actual; és editable des del formulari d'immobles.
 */
return new class extends Migration
{
    /**
     * Patrons cercats a l'adreça, dels més específics als més genèrics: a
     * "SALT 17190-GIRONA" el "GIRONA" final és la província, no la població.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const PATRONS = [
        ['CASTELL-PLATJA D ARO', "Platja d'Aro"],
        ['PLATJA D ARO',         "Platja d'Aro"],
        ['VILANOVA DE LA MUGA',  'Vilanova de la Muga'],
        ['SALT',                 'Salt'],
        ['BARCELONA',            'Barcelona'],
        ['FIGUERES',             'Figueres'],
        ['PERALADA',             'Peralada'],
        ['GIRONA',               'Girona'],
    ];

    public function up(): void
    {
        Schema::table('g_immobles', function (Blueprint $table) {
            $table->string('poblacio', 100)->nullable()->after('adreca');
        });

        foreach (DB::table('g_immobles')->get(['id', 'adreca']) as $immoble) {
            $poblacio = $this->deduirPoblacio($immoble->adreca);
            if ($poblacio !== null) {
                DB::table('g_immobles')->where('id', $immoble->id)->update(['poblacio' => $poblacio]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('g_immobles', function (Blueprint $table) {
            $table->dropColumn('poblacio');
        });
    }

    private function deduirPoblacio(?string $adreca): ?string
    {
        // Apòstrofs i accents fora: "PLATJA D’ARO" i "PLATJA D'ARO" han de coincidir
        $normalitzada = preg_replace('/[^A-Z0-9]+/', ' ', mb_strtoupper(Str::ascii((string) $adreca)));

        foreach (self::PATRONS as [$patro, $poblacio]) {
            if (str_contains($normalitzada, $patro)) {
                return $poblacio;
            }
        }

        return null;
    }
};
