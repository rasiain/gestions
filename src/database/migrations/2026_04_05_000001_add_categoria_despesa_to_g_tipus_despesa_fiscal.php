<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('g_tipus_despesa_fiscal', function (Blueprint $table) {
            $table->string('categoria_despesa', 50)->nullable()->after('descripcio');
        });
    }

    public function down(): void
    {
        Schema::table('g_tipus_despesa_fiscal', function (Blueprint $table) {
            $table->dropColumn('categoria_despesa');
        });
    }
};
