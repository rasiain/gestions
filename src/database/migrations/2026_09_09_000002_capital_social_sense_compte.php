<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capital social que no viu en cap compte bancari.
 *
 * Les aportacions a una cooperativa de consum (Som Energia) no són cap compte: no tenen
 * número ni entitat, i el certificat anual només diu el saldo a 31 de desembre. Tres
 * supòsits del disseny inicial venien de l'extracte d'una cooperativa de crèdit i aquí no
 * es compleixen:
 *
 *   - **El compte passa a ser opcional.** Quan no n'hi ha, el contracte porta l'emissor
 *     (nom i NIF) i els seus propis titulars: sense compte no hi ha d'on treure'ls.
 *   - **Els títols també.** «15.000 €» és tot el que hi ha, i fiscalment és tot el que
 *     compta: el nombre de títols no surt enlloc ni a patrimoni ni a l'IRPF.
 *   - **Els rendiments porten retenció**, que és el que es declara a l'IRPF i que fins ara
 *     no es desava enlloc.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('g_cs_contractes', function (Blueprint $table) {
            $table->string('emissor', 150)->nullable()->after('id');
            $table->string('nif', 20)->nullable()->after('emissor');
        });

        // SQLite no sap fer nullable una clau forana existent: es refà la taula
        Schema::table('g_cs_contractes', function (Blueprint $table) {
            $table->unsignedBigInteger('compte_corrent_id')->nullable()->change();
        });

        // Els titulars de les aportacions que no tenen compte d'on treure'ls
        Schema::create('g_cs_contracte_titular', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contracte_id')->constrained('g_cs_contractes')->cascadeOnDelete();
            $table->foreignId('titular_id')->constrained('g_persones')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['contracte_id', 'titular_id']);
        });

        Schema::table('g_cs_valors', function (Blueprint $table) {
            // El saldo, quan no ve desglossat en títols
            $table->decimal('import', 14, 2)->nullable()->after('contracte_id');
        });

        Schema::table('g_cs_valors', function (Blueprint $table) {
            $table->unsignedInteger('titols')->nullable()->change();
            $table->decimal('valor_unitari', 12, 2)->nullable()->change();
        });

        Schema::table('g_cs_rendiments', function (Blueprint $table) {
            $table->decimal('retencio', 14, 2)->nullable()->after('import');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g_cs_contracte_titular');

        Schema::table('g_cs_contractes', function (Blueprint $table) {
            $table->dropColumn(['emissor', 'nif']);
        });

        Schema::table('g_cs_valors', function (Blueprint $table) {
            $table->dropColumn('import');
        });

        Schema::table('g_cs_rendiments', function (Blueprint $table) {
            $table->dropColumn('retencio');
        });
    }
};
