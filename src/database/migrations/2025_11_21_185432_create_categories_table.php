<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('g_categories', function (Blueprint $table) {
            $table->id();
            $table->string('nom', 100);
            $table->foreignId('categoria_pare_id')->nullable()->constrained('g_categories')->onDelete('cascade');
            $table->unsignedTinyInteger('ordre')->default(0);
            $table->timestamps();

            // Index for better performance on hierarchical queries
            $table->index(['categoria_pare_id', 'ordre']);
        });

        // Insert default parent categories
        DB::table('g_categories')->insert([
            [
                'nom' => 'Ingressos',
                'categoria_pare_id' => null,
                'ordre' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nom' => 'Despeses',
                'categoria_pare_id' => null,
                'ordre' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('g_categories');
    }
};
