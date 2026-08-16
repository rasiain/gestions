<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un contracte pot tenir més d'un arrendador: quan els copropietaris arrenden en
 * proindivís, cadascun hi consta com a arrendador. Substitueix el camp únic
 * g_contractes.arrendador_id per una taula pivot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('g_arrendador_contracte', function (Blueprint $table) {
            $table->foreignId('contracte_id')->constrained('g_contractes')->cascadeOnDelete();
            $table->foreignId('arrendador_id')->constrained('g_arrendadors')->cascadeOnDelete();
            $table->primary(['contracte_id', 'arrendador_id']);
        });

        // Traspassar els arrendadors ja assignats
        if (Schema::hasColumn('g_contractes', 'arrendador_id')) {
            $existents = DB::table('g_contractes')
                ->whereNotNull('arrendador_id')
                ->get(['id', 'arrendador_id']);

            foreach ($existents as $contracte) {
                DB::table('g_arrendador_contracte')->insert([
                    'contracte_id'  => $contracte->id,
                    'arrendador_id' => $contracte->arrendador_id,
                ]);
            }

            Schema::table('g_contractes', function (Blueprint $table) {
                $table->dropForeign(['arrendador_id']);
                $table->dropColumn('arrendador_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('g_contractes', 'arrendador_id')) {
            Schema::table('g_contractes', function (Blueprint $table) {
                $table->foreignId('arrendador_id')->nullable()->constrained('g_arrendadors')->nullOnDelete();
            });

            // Es recupera només el primer arrendador de cada contracte
            $parelles = DB::table('g_arrendador_contracte')->get();
            foreach ($parelles->groupBy('contracte_id') as $contracteId => $files) {
                DB::table('g_contractes')
                    ->where('id', $contracteId)
                    ->update(['arrendador_id' => $files->first()->arrendador_id]);
            }
        }

        Schema::dropIfExists('g_arrendador_contracte');
    }
};
