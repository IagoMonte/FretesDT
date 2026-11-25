<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Momento exato (data e hora) em que a carga foi marcada como ENTREGUE; a data_entrega_realizada segue como o dia (fuso de negócio). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programacao', function (Blueprint $t) {
            $t->dateTime('entregue_em', 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('programacao', function (Blueprint $t) {
            $t->dropColumn('entregue_em');
        });
    }
};
