<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('g_entitats', function (Blueprint $table) {
            $table->id();
            $table->string('nom', 200)->unique();
            $table->timestamps();
        });

        // Migrate existing entitat strings from g_comptes_corrents
        $noms = DB::table('g_comptes_corrents')
            ->whereNotNull('entitat')
            ->where('entitat', '!=', '')
            ->distinct()
            ->pluck('entitat');

        $now = now();
        foreach ($noms as $nom) {
            DB::table('g_entitats')->insert([
                'nom'        => $nom,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::table('g_comptes_corrents', function (Blueprint $table) {
            $table->foreignId('entitat_id')->nullable()->after('nom')->constrained('g_entitats');
        });

        // Link each compte to its new entitat record
        $entitats = DB::table('g_entitats')->pluck('id', 'nom');
        foreach ($entitats as $nom => $id) {
            DB::table('g_comptes_corrents')
                ->where('entitat', $nom)
                ->update(['entitat_id' => $id]);
        }

        Schema::table('g_comptes_corrents', function (Blueprint $table) {
            $table->dropColumn('entitat');
        });
    }

    public function down(): void
    {
        Schema::table('g_comptes_corrents', function (Blueprint $table) {
            $table->string('entitat', 200)->nullable()->after('nom');
        });

        // Restore entitat string from entitat_id
        $entitats = DB::table('g_entitats')->pluck('nom', 'id');
        foreach ($entitats as $id => $nom) {
            DB::table('g_comptes_corrents')
                ->where('entitat_id', $id)
                ->update(['entitat' => $nom]);
        }

        Schema::table('g_comptes_corrents', function (Blueprint $table) {
            $table->dropForeign(['entitat_id']);
            $table->dropColumn('entitat_id');
        });

        Schema::dropIfExists('g_entitats');
    }
};
