<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Embalagem passa a dizer se aceita paletização/remontagem; o item da cotação registra a escolha. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('embalagem', function (Blueprint $t) {
            $t->boolean('permite_paletizar')->default(false);
            $t->boolean('permite_remontar')->default(false);
        });
        Schema::table('cotacao_item', function (Blueprint $t) {
            $t->boolean('paletizado')->default(false);
            $t->boolean('remontado')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('cotacao_item', function (Blueprint $t) {
            $t->dropColumn(['paletizado', 'remontado']);
        });
        Schema::table('embalagem', function (Blueprint $t) {
            $t->dropColumn(['permite_paletizar', 'permite_remontar']);
        });
    }
};
