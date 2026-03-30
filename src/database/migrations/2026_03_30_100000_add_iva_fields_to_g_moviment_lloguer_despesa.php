<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('g_moviment_lloguer_despesa', function (Blueprint $table) {
            $table->decimal('base_imposable', 10, 2)->nullable()->after('notes');
            $table->decimal('iva_import', 10, 2)->nullable()->after('base_imposable');
        });
    }

    public function down(): void
    {
        Schema::table('g_moviment_lloguer_despesa', function (Blueprint $table) {
            $table->dropColumn(['base_imposable', 'iva_import']);
        });
    }
};
