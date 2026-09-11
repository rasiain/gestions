<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les notes que expliquen els salts del patrimoni.
 *
 * La gràfica dels totals mobiliaris ensenya que un any el patrimoni cau i no diu per què;
 * darrere d'un salt hi sol haver un impost, un préstec o uns diners que se'n van a una
 * inversió. Les notes hi posen nom.
 *
 * **Cap nota automàtica no es desa.** Són els moviments grans dels comptes corrents —tot el
 * que passa d'un llindar— i es dedueixen cada cop: desar-les faria que un moviment esborrat
 * o corregit deixés enrere una nota que ja no és certa. D'aquesta taula només en surt el que
 * s'hi afegeix a mà: la `descripcio` d'un moviment gros, o una nota sencera per al que no és
 * cap moviment (una revaloració, un fet de fora).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('g_patrimoni_notes', function (Blueprint $table) {
            $table->id();
            // Amb moviment, la nota només hi afegeix text: data, import i titulars són d'ell
            $table->foreignId('moviment_id')->nullable()->unique()
                ->constrained('g_moviments_comptes_corrents')->cascadeOnDelete();
            $table->date('data')->nullable();
            $table->string('titol', 200)->nullable();
            $table->text('descripcio')->nullable();
            $table->decimal('import', 14, 2)->nullable();
            // Per treure de la vista un moviment gros que no explica res (un traspàs intern)
            $table->boolean('ocult')->default(false);
            $table->timestamps();
        });

        // Només per a les notes que no vénen de cap moviment: les altres agafen els del compte
        Schema::create('g_patrimoni_nota_titular', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nota_id')->constrained('g_patrimoni_notes')->cascadeOnDelete();
            $table->foreignId('titular_id')->constrained('g_persones')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['nota_id', 'titular_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g_patrimoni_nota_titular');
        Schema::dropIfExists('g_patrimoni_notes');
    }
};
