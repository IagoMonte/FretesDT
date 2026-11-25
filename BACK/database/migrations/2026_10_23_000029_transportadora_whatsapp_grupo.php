<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** ID do grupo de WhatsApp da transportadora (JID, ex.: 120363123456789012@g.us): destino do encaminhamento, validado na Evolution. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transportadora', function (Blueprint $t) {
            $t->string('whatsapp_grupo_id', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('transportadora', function (Blueprint $t) {
            $t->dropColumn('whatsapp_grupo_id');
        });
    }
};
