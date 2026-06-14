<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('g_factures', function (Blueprint $table) {
            $table->string('tipus', 10)->default('mensual')->after('mes');
        });

        Schema::table('g_factures', function (Blueprint $table) {
            $table->dropUnique(['lloguer_id', 'any', 'mes']);
            $table->integer('any')->nullable()->change();
            $table->integer('mes')->nullable()->change();
            $table->index(['lloguer_id', 'any', 'mes']);
        });
    }

    public function down(): void
    {
        Schema::table('g_factures', function (Blueprint $table) {
            $table->dropIndex(['lloguer_id', 'any', 'mes']);
            $table->integer('any')->nullable(false)->change();
            $table->integer('mes')->nullable(false)->change();
            $table->unique(['lloguer_id', 'any', 'mes']);
            $table->dropColumn('tipus');
        });
    }
};
