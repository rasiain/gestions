<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('g_lloguers', function (Blueprint $table) {
            $table->string('ruta_export', 500)->nullable()->after('ruta_descarrega');
        });
    }

    public function down(): void
    {
        Schema::table('g_lloguers', function (Blueprint $table) {
            $table->dropColumn('ruta_export');
        });
    }
};
