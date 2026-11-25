<?php

use App\Support\Esquema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1. CNPJ zerado/repetido no ERP não derruba o cadastro: entra com CNPJ em branco e `revisao_pendente` (o painel completa depois).
 * 2. Produto do ERP vira N:1: o ERP tem um código por local/estoque (HIDROXIDO DE SODIO SOLUCAO 49% PORTO ALFA, PORTO BETA, ESTOQUE...)
 *    e para o frete é um produto só. `frete_produto_erp` guarda cada código do ERP e a que produto local ele pertence.
 */
return new class extends Migration
{
    public function up(): void
    {
        $p = DB::getTablePrefix();
        DB::statement("DROP VIEW IF EXISTS {$p}vw_parte");

        foreach (['transportadora', 'cliente', 'fornecedor'] as $tabela) {
            Schema::table($tabela, function (Blueprint $t) {
                $t->asciiChar('cnpj', 14)->nullable()->change();
                $t->boolean('revisao_pendente')->default(false);
            });
        }
        Schema::table('transportadora', fn (Blueprint $t) => $t->dropUnique('uk_frete_transp_cnpj'));
        Esquema::unicoNulavel('transportadora', 'cnpj', 'uk_frete_transp_cnpj');

        DB::statement("CREATE VIEW {$p}vw_parte AS
            SELECT 'CLIENTE' AS tipo, id, codigo_erp, razao_social, nome_fantasia, cnpj, cidade, uf, ativo, revisao_pendente FROM {$p}cliente
            UNION ALL
            SELECT 'FORNECEDOR' AS tipo, id, codigo_erp, razao_social, nome_fantasia, cnpj, cidade, uf, ativo, revisao_pendente FROM {$p}fornecedor");

        Schema::create('produto_erp', function (Blueprint $t) {
            $t->string('codigo_erp', 50);
            $t->uuidCol('produto_id');
            $t->string('descricao_erp', 200);
            $t->boolean('ativo')->default(true);
            $t->asciiChar('erp_hash', 64)->nullable();
            $t->dateTime('sincronizado_em', 3)->nullable();
            $t->dateTime('criado_em', 3);
            $t->dateTime('atualizado_em', 3);
            $t->primary('codigo_erp', 'pk_frete_produto_erp');
            $t->foreign('produto_id', 'fk_frete_prodmap_produto')->references('id')->on('produto');
            $t->index('produto_id', 'ix_frete_produto_erp_produto');
        });

        $agora = now()->format('Y-m-d H:i:s.v');
        DB::table('produto')->whereNotNull('codigo_erp')->orderBy('id')->each(function ($pr) use ($agora) {
            DB::table('produto_erp')->insert([
                'codigo_erp' => $pr->codigo_erp, 'produto_id' => $pr->id, 'descricao_erp' => $pr->nome, 'ativo' => (bool) $pr->ativo,
                'erp_hash' => null, 'sincronizado_em' => $pr->sincronizado_em, 'criado_em' => $agora, 'atualizado_em' => $agora,
            ]);
        });
    }

    public function down(): void
    {
        $p = DB::getTablePrefix();
        Schema::dropIfExists('produto_erp');
        DB::statement("DROP VIEW IF EXISTS {$p}vw_parte");

        Schema::table('transportadora', fn (Blueprint $t) => $t->dropUnique('uk_frete_transp_cnpj'));
        foreach (['transportadora', 'cliente', 'fornecedor'] as $tabela) {
            Schema::table($tabela, function (Blueprint $t) {
                $t->dropColumn('revisao_pendente');
            });
        }
        foreach (['transportadora', 'cliente', 'fornecedor'] as $tabela) {
            DB::table($tabela)->whereNull('cnpj')->orderBy('id')->each(fn ($l, $i) => DB::table($tabela)->where('id', $l->id)->update(['cnpj' => str_pad((string) (900000 + $i), 14, '0', STR_PAD_LEFT)]));
            Schema::table($tabela, fn (Blueprint $t) => $t->asciiChar('cnpj', 14)->nullable(false)->change());
        }
        Schema::table('transportadora', fn (Blueprint $t) => $t->unique('cnpj', 'uk_frete_transp_cnpj'));

        DB::statement("CREATE VIEW {$p}vw_parte AS
            SELECT 'CLIENTE' AS tipo, id, codigo_erp, razao_social, nome_fantasia, cnpj, cidade, uf, ativo FROM {$p}cliente
            UNION ALL
            SELECT 'FORNECEDOR' AS tipo, id, codigo_erp, razao_social, nome_fantasia, cnpj, cidade, uf, ativo FROM {$p}fornecedor");
    }
};
