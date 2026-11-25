<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Produto ganha descrição (o que a transportadora vê), número ONU e risco. O formato da ONU (4 dígitos) é validado na API. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produto', function (Blueprint $t) {
            $t->string('descricao', 255)->nullable();
            $t->string('onu', 4)->nullable();
            $t->string('risco', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('produto', function (Blueprint $t) {
            $t->dropColumn(['descricao', 'onu', 'risco']);
        });
    }
};
