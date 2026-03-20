<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('g_lloguers', function (Blueprint $table) {
            $table->foreignId('proveidor_gestoria_id')->nullable()->constrained('g_proveidors')->nullOnDelete();
            $table->decimal('gestoria_percentatge', 5, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('g_lloguers', function (Blueprint $table) {
            $table->dropForeign(['proveidor_gestoria_id']);
            $table->dropColumn(['proveidor_gestoria_id', 'gestoria_percentatge']);
        });
    }
};
