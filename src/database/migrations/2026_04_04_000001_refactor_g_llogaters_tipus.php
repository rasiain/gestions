<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('g_llogaters', function (Blueprint $table) {
            $table->string('tipus', 10)->default('persona')->after('id');
            $table->foreignId('persona_id')->nullable()->constrained('g_persones')->nullOnDelete()->after('tipus');
            $table->string('nom_rao_social', 150)->nullable()->after('persona_id');
            $table->string('nif', 20)->nullable()->after('nom_rao_social');
            $table->string('adreca', 200)->nullable()->after('nif');
            $table->string('codi_postal', 10)->nullable()->after('adreca');
            $table->string('poblacio', 100)->nullable()->after('codi_postal');
        });

        // Eliminem les columnes antigues (les dades s'han de migrar manualment)
        Schema::table('g_llogaters', function (Blueprint $table) {
            $table->dropColumn(['nom', 'cognoms', 'identificador']);
        });
    }

    public function down(): void
    {
        Schema::table('g_llogaters', function (Blueprint $table) {
            $table->string('nom', 50)->after('id');
            $table->string('cognoms', 100)->after('nom');
            $table->string('identificador', 20)->nullable()->after('cognoms');
        });

        Schema::table('g_llogaters', function (Blueprint $table) {
            $table->dropColumn(['tipus', 'persona_id', 'nom_rao_social', 'nif', 'adreca', 'codi_postal', 'poblacio']);
        });
    }
};
