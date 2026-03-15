<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('g_contractes', function (Blueprint $table) {
            $table->foreignId('lloguer_id')->after('id')->constrained('g_lloguers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('g_contractes', function (Blueprint $table) {
            $table->dropForeign(['lloguer_id']);
            $table->dropColumn('lloguer_id');
        });
    }
};
