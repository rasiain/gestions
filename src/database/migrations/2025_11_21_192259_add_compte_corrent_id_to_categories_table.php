<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // First, drop existing categories
        DB::table('g_categories')->truncate();

        Schema::table('g_categories', function (Blueprint $table) {
            $table->foreignId('compte_corrent_id')->after('id')->constrained('g_comptes_corrents')->onDelete('cascade');

            // Update unique index to include compte_corrent_id
            $table->dropIndex(['categoria_pare_id', 'ordre']);
            $table->index(['compte_corrent_id', 'categoria_pare_id', 'ordre']);
        });

        // Create default categories for each existing compte corrent
        $comptesCorrents = DB::table('g_comptes_corrents')->get();

        foreach ($comptesCorrents as $compte) {
            DB::table('g_categories')->insert([
                [
                    'compte_corrent_id' => $compte->id,
                    'nom' => 'Ingressos',
                    'categoria_pare_id' => null,
                    'ordre' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'compte_corrent_id' => $compte->id,
                    'nom' => 'Despeses',
                    'categoria_pare_id' => null,
                    'ordre' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('g_categories', function (Blueprint $table) {
            $table->dropIndex(['compte_corrent_id', 'categoria_pare_id', 'ordre']);
            $table->dropForeign(['compte_corrent_id']);
            $table->dropColumn('compte_corrent_id');
            $table->index(['categoria_pare_id', 'ordre']);
        });
    }
};
