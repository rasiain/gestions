<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('g_factures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lloguer_id')->constrained('g_lloguers')->cascadeOnDelete();
            $table->foreignId('contracte_id')->nullable()->constrained('g_contractes')->nullOnDelete();
            $table->integer('any');
            $table->integer('mes');
            $table->decimal('base', 10, 2);
            $table->decimal('iva_percentatge', 5, 2);
            $table->decimal('iva_import', 10, 2);
            $table->decimal('irpf_percentatge', 5, 2)->default(0);
            $table->decimal('irpf_import', 10, 2)->default(0);
            $table->decimal('total', 10, 2);
            $table->string('estat', 20)->default('esborrany');
            $table->foreignId('moviment_id')->nullable()->constrained('g_moviments_comptes_corrents')->nullOnDelete();
            $table->string('numero_factura', 50)->nullable();
            $table->date('data_emissio')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['lloguer_id', 'any', 'mes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g_factures');
    }
};
