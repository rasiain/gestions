<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('g_lloguer_revisions_ipc', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lloguer_id')->constrained('g_lloguers')->cascadeOnDelete();
            $table->integer('any_aplicacio');
            $table->decimal('base_anterior', 10, 2);
            $table->decimal('base_nova', 10, 2);
            $table->decimal('ipc_percentatge', 5, 2);
            $table->date('data_efectiva');
            $table->integer('mesos_regularitzats')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g_lloguer_revisions_ipc');
    }
};
