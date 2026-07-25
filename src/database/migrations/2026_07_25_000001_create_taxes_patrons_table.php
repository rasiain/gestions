<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('g_taxes_patrons', function (Blueprint $table) {
            $table->id();
            $table->string('etiqueta', 100);   // Nom visible del tipus (IBI, Escombraries, ...)
            $table->string('patro', 100);       // Text a cercar dins el nom de categoria (contains, normalitzat)
            $table->boolean('actiu')->default(true);
            $table->unsignedInteger('ordre')->default(0);
            $table->timestamps();
        });

        // Patrons per defecte (validats amb les dades existents)
        $now = now();
        $defaults = [
            ['IBI', 'IBI'],
            ['Escombraries', 'ESCOMBRAR'],
            ['Escombraries', 'ESCOMBRER'],
            ['Gual', 'GUAL'],
            ['Impost circulació', 'IMPOST CIRCULACIO'],
            ['Taxa habitatge', 'TAXA HABITATGE'],
            ['Plusvàlues', 'PLUSVAL'],
            ['Impost successions', 'IMPOST SUCCESSIONS'],
        ];

        foreach ($defaults as $i => [$etiqueta, $patro]) {
            DB::table('g_taxes_patrons')->insert([
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
        Schema::dropIfExists('g_taxes_patrons');
    }
};
