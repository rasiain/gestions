<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El compte de Gmail on es creen els esborranys de les factures. N'hi ha un de sol: és
     * qui envia les factures. Els tokens es desen xifrats; el que importa és el de
     * refresc, que és el que dura, i l'altre es renova sol quan caduca.
     */
    public function up(): void
    {
        Schema::create('g_google_connexions', function (Blueprint $table) {
            $table->id();
            $table->string('email')->nullable();
            $table->text('refresh_token');
            $table->text('access_token')->nullable();
            $table->dateTime('access_token_expira')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('g_google_connexions');
    }
};
