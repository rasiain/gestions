<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('g_moviments_comptes_corrents', function (Blueprint $table) {
            // Drop the old concepte column
            $table->dropColumn('concepte');

            // Add concepte_id as foreign key
            $table->foreignId('concepte_id')->after('data_moviment')->constrained('g_moviments_conceptes')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('g_moviments_comptes_corrents', function (Blueprint $table) {
            // Drop foreign key and concepte_id column
            $table->dropForeign(['concepte_id']);
            $table->dropColumn('concepte_id');

            // Restore the old concepte column
            $table->string('concepte')->after('data_moviment');
        });
    }
};
