<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('g_comunitats_bens', function (Blueprint $table) {
            $table->string('adreca', 255)->nullable()->after('nif');
            $table->string('activitat', 50)->nullable()->after('adreca');
            $table->string('codi_activitat', 3)->nullable()->after('activitat');
            $table->unsignedSmallInteger('epigraf_iae')->nullable()->after('codi_activitat');
        });

        Schema::create('g_comunitat_bens_comuner', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comunitat_bens_id')->constrained('g_comunitats_bens')->onDelete('cascade');
            $table->foreignId('persona_id')->constrained('g_persones')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['comunitat_bens_id', 'persona_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g_comunitat_bens_comuner');

        Schema::table('g_comunitats_bens', function (Blueprint $table) {
            $table->dropColumn(['adreca', 'activitat', 'codi_activitat', 'epigraf_iae']);
        });
    }
};
