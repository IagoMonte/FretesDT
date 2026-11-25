<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** O diff de uma edição de cotação (campos + itens, antes/depois) não cabe em 1000 caracteres. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('log_operacao', function (Blueprint $t) {
            $t->text('detalhe')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('log_operacao', function (Blueprint $t) {
            $t->string('detalhe', 1000)->nullable()->change();
        });
    }
};
