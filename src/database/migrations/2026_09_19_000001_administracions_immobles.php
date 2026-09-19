<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * L'administradora d'un immoble —l'empresa, l'identificador amb què el coneix i la
 * comissió que cobra— canvia amb els anys, i fins ara només se'n desava la d'avui:
 * canviar d'empresa esborrava l'anterior.
 *
 * També hi era dues vegades: `g_immobles.administrador_id` i
 * `g_lloguers.proveidor_gestoria_id`, sempre iguals. L'administració és de l'immoble
 * (n'hi ha d'administrats sense lloguer) i el lloguer la llegeix d'ell a cada data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('g_administracions_immobles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('immoble_id')->constrained('g_immobles')->cascadeOnDelete();
            // Restrict: esborrar l'empresa no ha d'esborrar en silenci l'històric
            $table->foreignId('proveidor_id')->constrained('g_proveidors')->restrictOnDelete();
            $table->string('referencia', 50)->nullable();
            $table->decimal('percentatge', 5, 2)->nullable();
            // Buida: des de sempre, o des d'una data que no se sap
            $table->date('data_inici')->nullable();
            $table->date('data_fi')->nullable();
            $table->timestamps();

            $table->index(['immoble_id', 'data_inici']);
        });

        $ara = now();

        foreach (DB::table('g_immobles')->whereNotNull('administrador_id')->get() as $immoble) {
            $lloguer = DB::table('g_lloguers')
                ->where('immoble_id', $immoble->id)
                ->where('proveidor_gestoria_id', $immoble->administrador_id)
                ->first();

            DB::table('g_administracions_immobles')->insert([
                'immoble_id'   => $immoble->id,
                'proveidor_id' => $immoble->administrador_id,
                'referencia'   => $immoble->referencia_administracio,
                'percentatge'  => $lloguer?->gestoria_percentatge,
                'created_at'   => $ara,
                'updated_at'   => $ara,
            ]);
        }

        // Una gestoria de lloguer sense administrador a l'immoble no es pot perdre
        $ambTram = DB::table('g_administracions_immobles')->pluck('immoble_id')->all();

        foreach (DB::table('g_lloguers')->whereNotNull('proveidor_gestoria_id')->whereNotIn('immoble_id', $ambTram)->get() as $lloguer) {
            DB::table('g_administracions_immobles')->insert([
                'immoble_id'   => $lloguer->immoble_id,
                'proveidor_id' => $lloguer->proveidor_gestoria_id,
                'percentatge'  => $lloguer->gestoria_percentatge,
                'created_at'   => $ara,
                'updated_at'   => $ara,
            ]);
            $ambTram[] = $lloguer->immoble_id;
        }

        Schema::table('g_lloguers', function (Blueprint $table) {
            $table->dropForeign(['proveidor_gestoria_id']);
            $table->dropColumn(['proveidor_gestoria_id', 'gestoria_percentatge']);
        });

        Schema::table('g_immobles', function (Blueprint $table) {
            $table->dropForeign(['administrador_id']);
            $table->dropColumn(['administrador_id', 'referencia_administracio']);
        });
    }

    public function down(): void
    {
        Schema::table('g_immobles', function (Blueprint $table) {
            $table->string('referencia_administracio', 50)->nullable();
            $table->foreignId('administrador_id')->nullable()->constrained('g_proveidors')->onDelete('set null');
        });

        Schema::table('g_lloguers', function (Blueprint $table) {
            $table->foreignId('proveidor_gestoria_id')->nullable()->constrained('g_proveidors')->nullOnDelete();
            $table->decimal('gestoria_percentatge', 5, 2)->nullable();
        });

        // Només hi cap el tram obert: l'històric es perd
        $oberts = DB::table('g_administracions_immobles')->whereNull('data_fi')->get();

        foreach ($oberts as $tram) {
            DB::table('g_immobles')->where('id', $tram->immoble_id)->update([
                'administrador_id'         => $tram->proveidor_id,
                'referencia_administracio' => $tram->referencia,
            ]);
            DB::table('g_lloguers')->where('immoble_id', $tram->immoble_id)->update([
                'proveidor_gestoria_id' => $tram->proveidor_id,
                'gestoria_percentatge'  => $tram->percentatge,
            ]);
        }

        Schema::dropIfExists('g_administracions_immobles');
    }
};
