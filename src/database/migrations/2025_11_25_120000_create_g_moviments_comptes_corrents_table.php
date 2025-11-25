<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('g_moviments_comptes_corrents', function (Blueprint $table) {
            $table->id();
            $table->date('data_moviment');
            $table->string('concepte', 255);
            $table->decimal('import', 10, 2);
            $table->decimal('saldo_posterior', 10, 2)->nullable();
            $table->string('hash', 64)->unique();
            $table->boolean('conciliat')->default(false);
            $table->text('notes')->nullable();

            // Foreign keys
            $table->foreignId('compte_corrent_id')
                ->constrained('g_comptes_corrents')
                ->onDelete('cascade');

            $table->foreignId('categoria_id')
                ->nullable()
                ->constrained('g_categories')
                ->onDelete('set null');

            $table->timestamps();

            // Indexes
            $table->index(['compte_corrent_id', 'data_moviment']);
            $table->index('data_moviment');
            $table->index('categoria_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('g_moviments_comptes_corrents');
    }
};
