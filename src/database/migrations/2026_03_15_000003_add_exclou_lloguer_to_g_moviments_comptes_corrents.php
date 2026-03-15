<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('g_moviments_comptes_corrents', function (Blueprint $table) {
            $table->boolean('exclou_lloguer')->default(false)->after('conciliat');
        });
    }

    public function down(): void
    {
        Schema::table('g_moviments_comptes_corrents', function (Blueprint $table) {
            $table->dropColumn('exclou_lloguer');
        });
    }
};
