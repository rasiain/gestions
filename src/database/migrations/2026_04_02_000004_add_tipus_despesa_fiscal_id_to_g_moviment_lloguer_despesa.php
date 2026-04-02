<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('g_moviment_lloguer_despesa', function (Blueprint $table) {
            $table->foreignId('tipus_despesa_fiscal_id')
                ->nullable()
                ->after('iva_import')
                ->constrained('g_tipus_despesa_fiscal')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('g_moviment_lloguer_despesa', function (Blueprint $table) {
            $table->dropForeign(['tipus_despesa_fiscal_id']);
            $table->dropColumn('tipus_despesa_fiscal_id');
        });
    }
};
