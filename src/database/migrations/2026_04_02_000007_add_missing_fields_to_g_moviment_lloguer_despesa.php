<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('g_moviment_lloguer_despesa', function (Blueprint $table) {
            $table->string('numero_factura', 50)->nullable()->after('lloguer_id');
            $table->string('concepte', 255)->nullable()->after('numero_factura');
            $table->decimal('iva_percentatge', 5, 2)->nullable()->after('base_imposable');
        });
    }

    public function down(): void
    {
        Schema::table('g_moviment_lloguer_despesa', function (Blueprint $table) {
            $table->dropColumn(['numero_factura', 'concepte', 'iva_percentatge']);
        });
    }
};
