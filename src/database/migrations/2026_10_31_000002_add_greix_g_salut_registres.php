<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** El percentatge de greix corporal (el de la bàscula de bioimpedància), com una dada més del registre. */
    public function up(): void
    {
        Schema::table('g_salut_registres', function (Blueprint $table) {
            $table->decimal('greix', 4, 1)->nullable()->after('pes');
        });
    }

    public function down(): void
    {
        Schema::table('g_salut_registres', function (Blueprint $table) {
            $table->dropColumn('greix');
        });
    }
};
