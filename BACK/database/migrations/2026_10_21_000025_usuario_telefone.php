<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Telefone do usuário (só dígitos, com DDD): destino do WhatsApp de vendedor, administrador, gestor e usuários de transportadora. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuario', function (Blueprint $t) {
            $t->string('telefone', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('usuario', function (Blueprint $t) {
            $t->dropColumn('telefone');
        });
    }
};
