<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** v1.1: cotação repetida (granel líquido) vinculada à cotação original (referência viva às respostas). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cotacao', function (Blueprint $t) {
            $t->uuidCol('cotacao_origem_id')->nullable();
            $t->foreign('cotacao_origem_id', 'fk_frete_cotacao_origem')->references('id')->on('cotacao');
            $t->index('cotacao_origem_id', 'ix_frete_cotacao_origem');
        });
    }

    public function down(): void
    {
        Schema::table('cotacao', function (Blueprint $t) {
            $t->dropForeign('fk_frete_cotacao_origem');
            $t->dropIndex('ix_frete_cotacao_origem');
            $t->dropColumn('cotacao_origem_id');
        });
    }
};
