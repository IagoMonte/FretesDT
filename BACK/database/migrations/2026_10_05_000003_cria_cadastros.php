<?php

use App\Support\Esquema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produto', function (Blueprint $t) {
            $t->uuidCol('id');
            $t->string('nome', 150);
            $t->decimal('densidade', 6, 3)->nullable();
            $t->boolean('ativo')->default(true);
            $this->erp($t);
            $t->auditoria();
            $t->primary('id', 'pk_frete_produto');
            $t->index('nome', 'ix_frete_produto_nome');
        });
        Esquema::unicoNulavel('produto', 'codigo_erp', 'uk_frete_produto_erp');
        Esquema::check('produto', 'ck_frete_produto_dens', 'densidade IS NULL OR densidade > 0');
        Esquema::check('produto', 'ck_frete_produto_origem', "origem_cadastro IN ('ERP','MANUAL')");

        Schema::create('produto_embalagem', function (Blueprint $t) {
            $t->uuidCol('produto_id');
            $t->string('embalagem_codigo', 30);
            $t->primary(['produto_id', 'embalagem_codigo'], 'pk_frete_produto_embalagem');
            $t->foreign('produto_id', 'fk_frete_prodemb_produto')->references('id')->on('produto');
            $t->foreign('embalagem_codigo', 'fk_frete_prodemb_embalagem')->references('codigo')->on('embalagem');
        });

        foreach (['cliente', 'fornecedor'] as $tabela) {
            Schema::create($tabela, function (Blueprint $t) use ($tabela) {
                $t->uuidCol('id');
                $t->string('codigo_erp', 50)->nullable();
                $t->string('razao_social', 200);
                $t->string('nome_fantasia', 150)->nullable();
                $t->asciiChar('cnpj', 14);
                $t->asciiChar('cidade_ibge', 7)->nullable();
                $t->string('cidade', 100)->nullable();
                $t->asciiChar('uf', 2)->nullable();
                $t->string('origem_cadastro', 10);
                $t->asciiChar('erp_hash', 64)->nullable();
                $t->dateTime('sincronizado_em', 3)->nullable();
                $t->boolean('ativo')->default(true);
                $t->auditoria();
                $t->primary('id', "pk_frete_{$tabela}");
                $t->index('cnpj', "ix_frete_{$tabela}_cnpj");
            });
            Esquema::unicoNulavel($tabela, 'codigo_erp', "uk_frete_{$tabela}_erp");
            Esquema::check($tabela, "ck_frete_{$tabela}_origem", "origem_cadastro IN ('ERP','MANUAL')");
        }

        Schema::create('sincronizacao', function (Blueprint $t) {
            $t->string('entidade', 30);
            $t->boolean('ativo')->default(true);
            $t->integer('intervalo_minutos');
            $t->string('marca_dagua', 100)->nullable();
            $t->dateTime('executando_desde', 3)->nullable();
            $t->string('executando_origem', 20)->nullable();
            $t->uuidCol('executando_por_id')->nullable();
            $t->dateTime('ultima_execucao_em', 3)->nullable();
            $t->string('ultima_execucao_status', 20)->nullable();
            $t->dateTime('ultimo_sucesso_em', 3)->nullable();
            $t->string('ultima_execucao_resumo', 500)->nullable();
            $t->auditoria();
            $t->primary('entidade', 'pk_frete_sincronizacao');
            $t->foreign('executando_por_id', 'fk_frete_sync_usuario')->references('id')->on('usuario');
        });
        Esquema::check('sincronizacao', 'ck_frete_sync_intervalo', 'intervalo_minutos > 0');
        Esquema::check('sincronizacao', 'ck_frete_sync_origem', "executando_origem IS NULL OR executando_origem IN ('AGENDADA','SOB_DEMANDA')");
        Esquema::check('sincronizacao', 'ck_frete_sync_status', "ultima_execucao_status IS NULL OR ultima_execucao_status IN ('SUCESSO','PARCIAL','ERRO')");

        $p = DB::getTablePrefix();
        DB::statement("CREATE VIEW {$p}vw_parte AS
            SELECT 'CLIENTE' AS tipo, id, codigo_erp, razao_social, nome_fantasia, cnpj, cidade, uf, ativo FROM {$p}cliente
            UNION ALL
            SELECT 'FORNECEDOR' AS tipo, id, codigo_erp, razao_social, nome_fantasia, cnpj, cidade, uf, ativo FROM {$p}fornecedor");
    }

    /** Colunas do espelho do ERP. */
    private function erp(Blueprint $t): void
    {
        $t->string('codigo_erp', 50)->nullable();
        $t->string('origem_cadastro', 10);
        $t->asciiChar('erp_hash', 64)->nullable();
        $t->dateTime('sincronizado_em', 3)->nullable();
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS '.DB::getTablePrefix().'vw_parte');
        foreach (['sincronizacao', 'fornecedor', 'cliente', 'produto_embalagem', 'produto'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
