<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('g_llogaters', function (Blueprint $table) {
            // El destinatari de la factura: surt d'aquí i no de cap plantilla, perquè
            // si el client canvia d'adreça només s'ha de canviar en un lloc.
            $table->string('email', 150)->nullable()->after('poblacio');
            $table->string('email_cc', 150)->nullable()->after('email');
            $table->string('contacte', 100)->nullable()->after('email_cc');
        });
    }

    public function down(): void
    {
        Schema::table('g_llogaters', function (Blueprint $table) {
            $table->dropColumn(['email', 'email_cc', 'contacte']);
        });
    }
};
