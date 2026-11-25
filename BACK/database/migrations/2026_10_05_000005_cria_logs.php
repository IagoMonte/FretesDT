<?php

use App\Support\Esquema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_operacao', function (Blueprint $t) {
            $t->uuidCol('id');
            $t->dateTime('ocorrido_em', 3);
            $t->uuidCol('usuario_id')->nullable();
            $t->string('entidade', 30);
            $t->string('registro_id', 36);
            $t->string('registro_codigo', 20)->nullable();
            $t->string('evento', 40);
            $t->string('status_de', 30)->nullable();
            $t->string('status_para', 30)->nullable();
            $t->string('detalhe', 1000)->nullable();
            $t->uuidCol('correlacao_id')->nullable();
            $t->primary('id', 'pk_frete_log_operacao');
            $t->index(['entidade', 'registro_id', 'ocorrido_em'], 'ix_frete_logop_registro');
            $t->index(['usuario_id', 'ocorrido_em'], 'ix_frete_logop_usuario');
            $t->index('ocorrido_em', 'ix_frete_logop_data');
        });

        Schema::create('log_sistema', function (Blueprint $t) {
            $t->uuidCol('id');
            $t->dateTime('ocorrido_em', 3);
            $t->string('nivel', 10);
            $t->string('categoria', 20);
            $t->string('evento', 60);
            $t->uuidCol('usuario_id')->nullable();
            $t->string('usuario_login', 50)->nullable();
            $t->string('tabela', 64)->nullable();
            $t->string('registro_id', 36)->nullable();
            $t->string('mensagem', 1000)->nullable();
            $t->longText('dados_antes')->nullable();
            $t->longText('dados_depois')->nullable();
            $t->longText('contexto')->nullable();
            $t->string('metodo', 10)->nullable();
            $t->string('rota', 200)->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->uuidCol('correlacao_id')->nullable();
            $t->primary('id', 'pk_frete_log_sistema');
            $t->index('ocorrido_em', 'ix_frete_logsis_data');
            $t->index('correlacao_id', 'ix_frete_logsis_correlacao');
            $t->index(['tabela', 'registro_id'], 'ix_frete_logsis_registro');
        });
        Esquema::check('log_sistema', 'ck_frete_logsis_nivel', "nivel IN ('DEBUG','INFO','WARNING','ERROR')");

        Schema::create('log_acesso', function (Blueprint $t) {
            $t->uuidCol('id');
            $t->dateTime('ocorrido_em', 3);
            $t->uuidCol('usuario_id');
            $t->string('ip', 45)->nullable();
            $t->primary('id', 'pk_frete_log_acesso');
            $t->index(['usuario_id', 'ocorrido_em'], 'ix_frete_logac_usuario');
            $t->index('ocorrido_em', 'ix_frete_logac_data');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_acesso');
        Schema::dropIfExists('log_sistema');
        Schema::dropIfExists('log_operacao');
    }
};
