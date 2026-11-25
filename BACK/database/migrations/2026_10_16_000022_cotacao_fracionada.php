<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cotação fracionada (algum item com embalagem fracionada): o vendedor não escolhe o tipo de carro, a transportadora responde com
 * o carro que consegue usar e informa o valor TOTAL do frete (não por tonelada). `cotacao.fracionada` guarda o que valia na criação.
 * `tipo_veiculo_codigo` na resposta e no fechamento é o carro oferecido pela transportadora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cotacao', function (Blueprint $t) {
            $t->string('tipo_veiculo_codigo', 30)->nullable()->change();
            $t->boolean('fracionada')->default(false);
        });
        foreach (['negociacao', 'negociacao_resposta'] as $tabela) {
            Schema::table($tabela, function (Blueprint $t) use ($tabela) {
                $t->string('tipo_veiculo_codigo', 30)->nullable();
                $t->foreign('tipo_veiculo_codigo', "fk_frete_{$tabela}_veiculo")->references('codigo')->on('tipo_veiculo');
            });
        }

        $ids = DB::table('cotacao_item')->join('embalagem', 'embalagem.codigo', '=', 'cotacao_item.embalagem_codigo')
            ->where('embalagem.fracionada', true)->where('embalagem.granel', false)->distinct()->pluck('cotacao_item.cotacao_id');
        foreach ($ids->chunk(500) as $lote) {
            DB::table('cotacao')->whereIn('id', $lote->all())->update(['fracionada' => true]);
        }
    }

    public function down(): void
    {
        foreach (['negociacao', 'negociacao_resposta'] as $tabela) {
            Schema::table($tabela, function (Blueprint $t) use ($tabela) {
                $t->dropForeign("fk_frete_{$tabela}_veiculo");
                $t->dropColumn('tipo_veiculo_codigo');
            });
        }
        $primeiro = DB::table('tipo_veiculo')->orderBy('codigo')->value('codigo');
        if ($primeiro) {
            DB::table('cotacao')->whereNull('tipo_veiculo_codigo')->update(['tipo_veiculo_codigo' => $primeiro]);
        }
        Schema::table('cotacao', function (Blueprint $t) {
            $t->dropColumn('fracionada');
            $t->string('tipo_veiculo_codigo', 30)->nullable(false)->change();
        });
    }
};
