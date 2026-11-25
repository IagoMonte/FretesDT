<?php

use App\Support\Esquema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OPERACOES = "operacao IN ('CARREGAMENTO','RECEBIMENTO')";

    private const PARTE = "(operacao = 'CARREGAMENTO' AND cliente_id IS NOT NULL AND fornecedor_id IS NULL)
        OR (operacao = 'RECEBIMENTO' AND fornecedor_id IS NOT NULL AND cliente_id IS NULL)";

    public function up(): void
    {
        Schema::create('cotacao', function (Blueprint $t) {
            $t->uuidCol('id');
            $t->string('codigo', 20);
            $t->date('data_solicitacao');
            $t->uuidCol('vendedor_id');
            $t->string('operacao', 20);
            $t->uuidCol('cliente_id')->nullable();
            $t->uuidCol('fornecedor_id')->nullable();
            $t->string('tipo_veiculo_codigo', 30);
            $t->asciiChar('origem_ibge', 7)->nullable();
            $t->string('origem_cidade', 100);
            $t->asciiChar('origem_uf', 2);
            $t->asciiChar('destino_ibge', 7)->nullable();
            $t->string('destino_cidade', 100);
            $t->asciiChar('destino_uf', 2);
            $t->decimal('valor_nf', 15, 2)->nullable();
            $t->string('observacao', 2000)->nullable();
            $t->string('status', 30);
            $t->auditoria();
            $t->primary('id', 'pk_frete_cotacao');
            $t->unique('codigo', 'uk_frete_cotacao_codigo');
            $t->foreign('vendedor_id', 'fk_frete_cotacao_vendedor')->references('id')->on('usuario');
            $t->foreign('cliente_id', 'fk_frete_cotacao_cliente')->references('id')->on('cliente');
            $t->foreign('fornecedor_id', 'fk_frete_cotacao_fornecedor')->references('id')->on('fornecedor');
            $t->foreign('tipo_veiculo_codigo', 'fk_frete_cotacao_veiculo')->references('codigo')->on('tipo_veiculo');
            $t->index('status', 'ix_frete_cotacao_status');
            $t->index(['vendedor_id', 'status'], 'ix_frete_cotacao_vend_status');
            $t->index('data_solicitacao', 'ix_frete_cotacao_data');
            $t->index('cliente_id', 'ix_frete_cotacao_cliente');
            $t->index('fornecedor_id', 'ix_frete_cotacao_fornecedor');
        });
        Esquema::check('cotacao', 'ck_frete_cotacao_operacao', self::OPERACOES);
        Esquema::check('cotacao', 'ck_frete_cotacao_parte', self::PARTE);
        Esquema::check('cotacao', 'ck_frete_cotacao_status', "status IN ('ABERTA','EM_NEGOCIACAO','FECHADA','PROGRAMADA','CANCELADA')");
        Esquema::check('cotacao', 'ck_frete_cotacao_vnf', 'valor_nf IS NULL OR valor_nf >= 0');

        Schema::create('cotacao_item', function (Blueprint $t) {
            $t->uuidCol('id');
            $t->uuidCol('cotacao_id');
            $t->smallInteger('sequencia');
            $t->uuidCol('produto_id');
            $t->string('embalagem_codigo', 30);
            $t->decimal('quantidade_kg', 15, 3);
            $t->integer('volumes')->nullable();
            $t->auditoria();
            $t->primary('id', 'pk_frete_cotacao_item');
            $t->unique(['cotacao_id', 'sequencia'], 'uk_frete_cotitem_seq');
            $t->foreign('cotacao_id', 'fk_frete_cotitem_cotacao')->references('id')->on('cotacao');
            $t->foreign('produto_id', 'fk_frete_cotitem_produto')->references('id')->on('produto');
            $t->foreign('embalagem_codigo', 'fk_frete_cotitem_embalagem')->references('codigo')->on('embalagem');
        });
        Esquema::check('cotacao_item', 'ck_frete_cotitem_qtd', 'quantidade_kg > 0');
        Esquema::check('cotacao_item', 'ck_frete_cotitem_vol', 'volumes IS NULL OR volumes >= 0');

        Schema::create('negociacao', function (Blueprint $t) {
            $t->uuidCol('id');
            $t->uuidCol('cotacao_id');
            $t->string('status', 30);
            $t->uuidCol('transportadora_id')->nullable();
            $t->decimal('valor_tonelada', 15, 2)->nullable();
            $t->decimal('valor_frete', 15, 2)->nullable();
            $t->smallInteger('prazo_dias')->nullable();
            $t->string('observacao_fechamento', 4000)->nullable();
            $t->dateTime('fechado_em', 3)->nullable();
            $t->uuidCol('fechado_por_id')->nullable();
            $t->auditoria();
            $t->primary('id', 'pk_frete_negociacao');
            $t->unique('cotacao_id', 'uk_frete_negociacao_cotacao');
            $t->foreign('cotacao_id', 'fk_frete_negociacao_cotacao')->references('id')->on('cotacao');
            $t->foreign('transportadora_id', 'fk_frete_negociacao_transp')->references('id')->on('transportadora');
            $t->foreign('fechado_por_id', 'fk_frete_negociacao_fechpor')->references('id')->on('usuario');
        });
        Esquema::check('negociacao', 'ck_frete_negociacao_status', "status IN ('ABERTA','FECHADA','CANCELADA')");
        Esquema::check('negociacao', 'ck_frete_negociacao_fech', "status <> 'FECHADA' OR (
            transportadora_id IS NOT NULL AND valor_tonelada IS NOT NULL AND valor_frete IS NOT NULL
            AND prazo_dias IS NOT NULL AND observacao_fechamento IS NOT NULL
            AND fechado_em IS NOT NULL AND fechado_por_id IS NOT NULL)");
        Esquema::check('negociacao', 'ck_frete_negociacao_vals', '(valor_tonelada IS NULL OR valor_tonelada >= 0) AND (valor_frete IS NULL OR valor_frete >= 0) AND (prazo_dias IS NULL OR prazo_dias >= 0)');

        Schema::create('negociacao_resposta', function (Blueprint $t) {
            $t->uuidCol('id');
            $t->uuidCol('negociacao_id');
            $t->uuidCol('transportadora_id');
            $t->string('status', 30);
            $t->dateTime('encaminhado_em', 3);
            $t->uuidCol('encaminhado_por_id');
            $t->decimal('valor_tonelada', 15, 2)->nullable();
            $t->decimal('valor_frete', 15, 2)->nullable();
            $t->smallInteger('prazo_dias')->nullable();
            $t->date('validade')->nullable();
            $t->string('observacao', 2000)->nullable();
            $t->dateTime('respondido_em', 3)->nullable();
            $t->uuidCol('respondido_por_id')->nullable();
            $t->auditoria();
            $t->primary('id', 'pk_frete_negociacao_resposta');
            $t->unique(['negociacao_id', 'transportadora_id'], 'uk_frete_negresp_transp');
            $t->foreign('negociacao_id', 'fk_frete_negresp_negociacao')->references('id')->on('negociacao');
            $t->foreign('transportadora_id', 'fk_frete_negresp_transp')->references('id')->on('transportadora');
            $t->foreign('encaminhado_por_id', 'fk_frete_negresp_encpor')->references('id')->on('usuario');
            $t->foreign('respondido_por_id', 'fk_frete_negresp_resppor')->references('id')->on('usuario');
            $t->index(['transportadora_id', 'status'], 'ix_frete_negresp_transp_status');
        });
        Esquema::check('negociacao_resposta', 'ck_frete_negresp_status', "status IN ('AGUARDANDO','RESPONDIDA','DECLINADA')");
        Esquema::check('negociacao_resposta', 'ck_frete_negresp_resp', "status <> 'RESPONDIDA' OR (
            valor_tonelada IS NOT NULL AND valor_frete IS NOT NULL AND prazo_dias IS NOT NULL
            AND respondido_em IS NOT NULL AND respondido_por_id IS NOT NULL)");
        Esquema::check('negociacao_resposta', 'ck_frete_negresp_vals', '(valor_tonelada IS NULL OR valor_tonelada >= 0) AND (valor_frete IS NULL OR valor_frete >= 0) AND (prazo_dias IS NULL OR prazo_dias >= 0)');

        Schema::create('programacao', function (Blueprint $t) {
            $t->uuidCol('id');
            $t->string('codigo', 20);
            $t->uuidCol('cotacao_id')->nullable();
            $t->string('operacao', 20);
            $t->string('status', 30);
            $t->date('data_pedido');
            $t->string('pedido_venda', 30)->nullable();
            $t->string('nfe_numero', 20)->nullable();
            $t->string('nfe_serie', 5)->nullable();
            $t->asciiChar('nfe_chave', 44)->nullable();
            $t->uuidCol('representante_id');
            $t->uuidCol('cliente_id')->nullable();
            $t->uuidCol('fornecedor_id')->nullable();
            $t->string('deposito', 20);
            $t->string('modalidade_frete', 3);
            $t->uuidCol('transportadora_id')->nullable();
            $t->string('tipo_veiculo_codigo', 30)->nullable();
            $t->asciiChar('origem_ibge', 7)->nullable();
            $t->string('origem_cidade', 100)->nullable();
            $t->asciiChar('origem_uf', 2)->nullable();
            $t->asciiChar('destino_ibge', 7)->nullable();
            $t->string('destino_cidade', 100)->nullable();
            $t->asciiChar('destino_uf', 2)->nullable();
            $t->decimal('valor_tonelada', 15, 2)->nullable();
            $t->decimal('valor_total', 15, 2)->nullable();
            $t->smallInteger('prazo_dias')->nullable();
            $t->date('data_coleta_prevista')->nullable();
            $t->date('data_coleta_realizada')->nullable();
            $t->date('data_entrega_prevista')->nullable();
            $t->date('data_entrega_realizada')->nullable();
            $t->string('observacao', 2000)->nullable();
            $t->auditoria();
            $t->primary('id', 'pk_frete_programacao');
            $t->unique('codigo', 'uk_frete_programacao_codigo');
            $t->foreign('cotacao_id', 'fk_frete_prog_cotacao')->references('id')->on('cotacao');
            $t->foreign('representante_id', 'fk_frete_prog_repres')->references('id')->on('usuario');
            $t->foreign('cliente_id', 'fk_frete_prog_cliente')->references('id')->on('cliente');
            $t->foreign('fornecedor_id', 'fk_frete_prog_fornecedor')->references('id')->on('fornecedor');
            $t->foreign('transportadora_id', 'fk_frete_prog_transp')->references('id')->on('transportadora');
            $t->foreign('tipo_veiculo_codigo', 'fk_frete_prog_veiculo')->references('codigo')->on('tipo_veiculo');
            $t->index('status', 'ix_frete_prog_status');
            $t->index('cotacao_id', 'ix_frete_prog_cotacao');
            $t->index(['transportadora_id', 'status'], 'ix_frete_prog_transp_status');
            $t->index(['representante_id', 'status'], 'ix_frete_prog_repres_status');
            $t->index('cliente_id', 'ix_frete_prog_cliente');
            $t->index('fornecedor_id', 'ix_frete_prog_fornecedor');
            $t->index('pedido_venda', 'ix_frete_prog_pedido');
            $t->index('nfe_chave', 'ix_frete_prog_nfe_chave');
            $t->index('data_coleta_prevista', 'ix_frete_prog_coleta');
        });
        Esquema::check('programacao', 'ck_frete_prog_operacao', self::OPERACOES);
        Esquema::check('programacao', 'ck_frete_prog_parte', self::PARTE);
        Esquema::check('programacao', 'ck_frete_prog_status', "status IN ('EM_COTACAO','AGUARDANDO_CARREGAMENTO','EM_TRANSITO','ENTREGUE','CANCELADO','DEVOLVIDO')");
        Esquema::check('programacao', 'ck_frete_prog_deposito', "deposito IN ('VIRTUAL','FISICO')");
        Esquema::check('programacao', 'ck_frete_prog_modalidade', "modalidade_frete IN ('CIF','FOB')");
        Esquema::check('programacao', 'ck_frete_prog_vals', '(valor_tonelada IS NULL OR valor_tonelada >= 0) AND (valor_total IS NULL OR valor_total >= 0) AND (prazo_dias IS NULL OR prazo_dias >= 0)');

        Schema::create('programacao_item', function (Blueprint $t) {
            $t->uuidCol('id');
            $t->uuidCol('programacao_id');
            $t->smallInteger('sequencia');
            $t->uuidCol('cotacao_item_id')->nullable();
            $t->uuidCol('produto_id');
            $t->string('embalagem_codigo', 30);
            $t->decimal('quantidade_kg', 15, 3);
            $t->integer('volumes')->nullable();
            $t->auditoria();
            $t->primary('id', 'pk_frete_programacao_item');
            $t->unique(['programacao_id', 'sequencia'], 'uk_frete_progitem_seq');
            $t->foreign('programacao_id', 'fk_frete_progitem_prog')->references('id')->on('programacao');
            $t->foreign('cotacao_item_id', 'fk_frete_progitem_cotitem')->references('id')->on('cotacao_item');
            $t->foreign('produto_id', 'fk_frete_progitem_produto')->references('id')->on('produto');
            $t->foreign('embalagem_codigo', 'fk_frete_progitem_embalagem')->references('codigo')->on('embalagem');
        });
        Esquema::check('programacao_item', 'ck_frete_progitem_qtd', 'quantidade_kg > 0');
        Esquema::check('programacao_item', 'ck_frete_progitem_vol', 'volumes IS NULL OR volumes >= 0');
    }

    public function down(): void
    {
        foreach (['programacao_item', 'programacao', 'negociacao_resposta', 'negociacao', 'cotacao_item', 'cotacao'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
