<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carga fracionada dedicada: `cotacao.dedicado` (só faz sentido com `fracionada`). O frete mínimo ANTT só vale se a carga não é
 * fracionada ou é dedicada. O tipo de carga ANTT passa a ser escolhido pela transportadora na resposta; fica em snapshot na
 * resposta e no fechamento (como `eixos`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cotacao', function (Blueprint $t) {
            $t->boolean('dedicado')->default(false);
        });
        foreach (['negociacao' => 'negociacao', 'negociacao_resposta' => 'negresp'] as $tabela => $abrev) {
            Schema::table($tabela, function (Blueprint $t) use ($abrev) {
                $t->string('tipo_carga_antt_codigo', 30)->nullable();
                $t->foreign('tipo_carga_antt_codigo', "fk_frete_{$abrev}_tipocarga")->references('codigo')->on('tipo_carga_antt');
            });
        }
    }

    public function down(): void
    {
        foreach (['negociacao' => 'negociacao', 'negociacao_resposta' => 'negresp'] as $tabela => $abrev) {
            Schema::table($tabela, function (Blueprint $t) use ($abrev) {
                $t->dropForeign("fk_frete_{$abrev}_tipocarga");
                $t->dropColumn('tipo_carga_antt_codigo');
            });
        }
        Schema::table('cotacao', function (Blueprint $t) {
            $t->dropColumn('dedicado');
        });
    }
};
