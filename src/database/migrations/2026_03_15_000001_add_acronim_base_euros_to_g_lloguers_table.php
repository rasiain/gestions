<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('g_lloguers', function (Blueprint $table) {
            $table->string('acronim', 20)->nullable()->after('nom');
            $table->decimal('base_euros', 10, 2)->nullable()->after('compte_corrent_id');
        });
    }

    public function down(): void
    {
        Schema::table('g_lloguers', function (Blueprint $table) {
            $table->dropColumn(['acronim', 'base_euros']);
        });
    }
};
