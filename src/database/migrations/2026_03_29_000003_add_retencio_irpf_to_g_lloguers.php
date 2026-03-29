<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('g_lloguers', function (Blueprint $table) {
            $table->boolean('retencio_irpf')->default(false)->after('es_habitatge');
        });
    }

    public function down(): void
    {
        Schema::table('g_lloguers', function (Blueprint $table) {
            $table->dropColumn('retencio_irpf');
        });
    }
};
