<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Patrons que detecten una assegurança pel nom d'un node de l'arbre de categories.
 *
 * A diferència de les taxes, el patró no es busca només a la fulla: la fulla
 * sovint és la COMPANYIA (… > BONASTRUCH DE PORTA 35 > ASSEGURANÇA > SEGURCAIXA)
 * i la paraula "assegurança" és al pare. Val el node coincident més alt.
 *
 * La coincidència és per INICI DE PARAULA, no per subcadena: "CAN MASSEGUR"
 * (complements de casa) i "L'ENSEGUR" (un restaurant) contenen "ASSEGUR" i no
 * són cap pòlissa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('g_assegurances_patrons', function (Blueprint $table) {
            $table->id();
            $table->string('etiqueta', 100);  // Nom visible de la pòlissa (Assegurança, Comunitat, Vehicle…)
            $table->string('patro', 100);      // Text a cercar al principi d'una paraula del node (normalitzat)
            $table->boolean('actiu')->default(true);
            $table->unsignedInteger('ordre')->default(0);
            $table->timestamps();
        });

        // Patrons per defecte, validats amb les dades existents. Els més
        // específics primer: entre dos patrons que encaixen amb el mateix node,
        // mana el d'`ordre` més baix.
        //
        // Deliberadament fora: MUTUALITAT DELS ENGINYERS, que és assegurança
        // però cap patró raonable no enganxa. Serà una inclusió manual.
        $now = now();
        $defaults = [
            ['Comunitat',    'ASSEGURANÇA COMUNITAT'],
            ['Comunitat',    'COMUNITAT ASSEGURANÇA'],
            ['Decessos',     'ASSEGURANÇA DECESOS'],
            ['Vehicle',      'ASSEGURANÇA COTXE'],
            ['Vehicle',      'ASSEGURANÇA MOTO'],
            ['Assegurança',  'ASSEGURAN'],
            ['Assegurança',  'SEGURCAIXA'],
        ];

        foreach ($defaults as $i => [$etiqueta, $patro]) {
            DB::table('g_assegurances_patrons')->insert([
                'etiqueta'   => $etiqueta,
                'patro'      => $patro,
                'actiu'      => true,
                'ordre'      => $i,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('g_assegurances_patrons');
    }
};
