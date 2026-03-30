<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('g_factura_linies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('factura_id')->constrained('g_factures')->cascadeOnDelete();
            $table->string('concepte', 30);
            $table->string('descripcio', 200)->nullable();
            $table->decimal('base', 10, 2);
            $table->decimal('iva_import', 10, 2)->default(0);
            $table->decimal('irpf_import', 10, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g_factura_linies');
    }
};
