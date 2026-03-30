<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('g_lloguers', function (Blueprint $table) {
            $table->decimal('iva_percentatge', 5, 2)->default(21.00)->after('retencio_irpf');
            $table->decimal('irpf_percentatge', 5, 2)->default(19.00)->after('iva_percentatge');
            $table->boolean('despeses_separades')->default(false)->after('irpf_percentatge');
        });
    }

    public function down(): void
    {
        Schema::table('g_lloguers', function (Blueprint $table) {
            $table->dropColumn(['iva_percentatge', 'irpf_percentatge', 'despeses_separades']);
        });
    }
};
