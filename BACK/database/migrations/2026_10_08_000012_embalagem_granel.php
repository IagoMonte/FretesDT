<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Embalagem passa a dizer se é a granel (sem contagem de volumes) em vez de depender do código. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('embalagem', function (Blueprint $t) {
            $t->boolean('granel')->default(false);
        });
        DB::table('embalagem')->whereIn('codigo', ['GRANEL_LIQUIDO', 'GRANEL_SOLIDO'])->update(['granel' => true]);
    }

    public function down(): void
    {
        Schema::table('embalagem', function (Blueprint $t) {
            $t->dropColumn('granel');
        });
    }
};
