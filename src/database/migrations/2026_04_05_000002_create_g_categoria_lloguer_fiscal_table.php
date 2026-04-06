<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('g_categoria_lloguer_fiscal', function (Blueprint $table) {
            $table->string('categoria', 50)->primary();
            $table->foreignId('tipus_despesa_fiscal_id')->nullable()->constrained('g_tipus_despesa_fiscal')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('g_tipus_despesa_fiscal', function (Blueprint $table) {
            $table->dropColumn('categoria_despesa');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g_categoria_lloguer_fiscal');

        Schema::table('g_tipus_despesa_fiscal', function (Blueprint $table) {
            $table->string('categoria_despesa', 50)->nullable()->after('descripcio');
        });
    }
};
