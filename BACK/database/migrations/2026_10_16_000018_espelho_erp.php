<?php

use App\Support\Esquema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Espelho do ERP via API-ponte: transportadora e embalagem ganham o bloco [ERP] que produto, cliente e fornecedor já têm,
 * a embalagem ganha a marca de revisão (cadastro automático que o painel confirma) e a tabela `sincronizacao` ganha as duas linhas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transportadora', function (Blueprint $t) {
            $t->string('origem_cadastro', 10)->default('MANUAL');
            $t->asciiChar('erp_hash', 64)->nullable();
            $t->dateTime('sincronizado_em', 3)->nullable();
            $t->string('cidade', 100)->nullable();
            $t->asciiChar('uf', 2)->nullable();
            $t->asciiChar('cidade_ibge', 7)->nullable();
        });
        DB::table('transportadora')->whereNotNull('codigo_erp')->update(['origem_cadastro' => 'ERP']);
        Esquema::check('transportadora', 'ck_frete_transp_origem', "origem_cadastro IN ('ERP','MANUAL')");

        Schema::table('embalagem', function (Blueprint $t) {
            $t->string('codigo_erp', 50)->nullable();
            $t->string('origem_cadastro', 10)->default('MANUAL');
            $t->asciiChar('erp_hash', 64)->nullable();
            $t->dateTime('sincronizado_em', 3)->nullable();
            $t->boolean('revisao_pendente')->default(false);
        });
        Esquema::unicoNulavel('embalagem', 'codigo_erp', 'uk_frete_embalagem_erp');
        Esquema::check('embalagem', 'ck_frete_embalagem_origem', "origem_cadastro IN ('ERP','MANUAL')");

        $agora = now()->format('Y-m-d H:i:s.v');
        foreach (['TRANSPORTADORA', 'EMBALAGEM'] as $entidade) {
            if (! DB::table('sincronizacao')->where('entidade', $entidade)->exists()) {
                DB::table('sincronizacao')->insert([
                    'entidade' => $entidade, 'ativo' => true, 'intervalo_minutos' => 60,
                    'criado_em' => $agora, 'atualizado_em' => $agora, 'versao' => 0,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('sincronizacao')->whereIn('entidade', ['TRANSPORTADORA', 'EMBALAGEM'])->delete();

        Esquema::removerCheck('embalagem', 'ck_frete_embalagem_origem');
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('DROP INDEX uk_frete_embalagem_erp ON '.DB::getTablePrefix().'embalagem');
        } else {
            Schema::table('embalagem', fn (Blueprint $t) => $t->dropUnique('uk_frete_embalagem_erp'));
        }
        Schema::table('embalagem', fn (Blueprint $t) => $t->dropColumn(['codigo_erp', 'origem_cadastro', 'erp_hash', 'sincronizado_em', 'revisao_pendente']));

        Esquema::removerCheck('transportadora', 'ck_frete_transp_origem');
        Schema::table('transportadora', fn (Blueprint $t) => $t->dropColumn(['origem_cadastro', 'erp_hash', 'sincronizado_em', 'cidade', 'uf', 'cidade_ibge']));
    }
};
