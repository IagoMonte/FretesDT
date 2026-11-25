<?php

use App\Support\Esquema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transportadora', function (Blueprint $t) {
            $t->uuidCol('id');
            $t->string('razao_social', 200);
            $t->string('nome_fantasia', 150);
            $t->asciiChar('cnpj', 14);
            $t->string('email', 200)->nullable();
            $t->string('telefone', 20)->nullable();
            $t->string('codigo_erp', 50)->nullable();
            $t->boolean('ativo')->default(true);
            $t->auditoria();
            $t->primary('id', 'pk_frete_transportadora');
            $t->unique('cnpj', 'uk_frete_transp_cnpj');
        });

        Esquema::unicoNulavel('transportadora', 'codigo_erp', 'uk_frete_transp_erp');

        Schema::create('recurso', function (Blueprint $t) {
            $t->string('codigo', 30);
            $t->string('nome', 100);
            $t->string('modulo', 30);
            $t->boolean('usa_escopo')->default(false);
            $t->boolean('ativo')->default(true);
            $t->auditoria();
            $t->primary('codigo', 'pk_frete_recurso');
        });

        Schema::create('recurso_acao', function (Blueprint $t) {
            $t->string('recurso_codigo', 30);
            $t->string('acao_codigo', 30);
            $t->string('nome', 100);
            $t->boolean('ativo')->default(true);
            $t->auditoria();
            $t->primary(['recurso_codigo', 'acao_codigo'], 'pk_frete_recurso_acao');
            $t->foreign('recurso_codigo', 'fk_frete_recacao_recurso')->references('codigo')->on('recurso');
        });

        Schema::create('grupo', function (Blueprint $t) {
            $t->uuidCol('id');
            $t->string('codigo', 30);
            $t->string('nome', 100);
            $t->string('descricao', 500)->nullable();
            $t->string('tipo_usuario', 20);
            $t->boolean('sistema')->default(false);
            $t->boolean('ativo')->default(true);
            $t->auditoria();
            $t->primary('id', 'pk_frete_grupo');
            $t->unique('codigo', 'uk_frete_grupo_codigo');
        });
        Esquema::check('grupo', 'ck_frete_grupo_tipo', "tipo_usuario IN ('INTERNO','TRANSPORTADORA')");

        Schema::create('grupo_permissao', function (Blueprint $t) {
            $t->uuidCol('grupo_id');
            $t->string('recurso_codigo', 30);
            $t->boolean('pode_ver')->default(false);
            $t->boolean('pode_inserir')->default(false);
            $t->boolean('pode_alterar')->default(false);
            $t->boolean('pode_cancelar')->default(false);
            $t->string('escopo', 20)->default('TODOS');
            $t->auditoria();
            $t->primary(['grupo_id', 'recurso_codigo'], 'pk_frete_grupo_permissao');
            $t->foreign('grupo_id', 'fk_frete_grupoperm_grupo')->references('id')->on('grupo');
            $t->foreign('recurso_codigo', 'fk_frete_grupoperm_recurso')->references('codigo')->on('recurso');
        });
        Esquema::check('grupo_permissao', 'ck_frete_grupoperm_escopo', "escopo IN ('PROPRIOS','TRANSPORTADORA','TODOS')");

        Schema::create('grupo_acao', function (Blueprint $t) {
            $t->uuidCol('grupo_id');
            $t->string('recurso_codigo', 30);
            $t->string('acao_codigo', 30);
            $t->primary(['grupo_id', 'recurso_codigo', 'acao_codigo'], 'pk_frete_grupo_acao');
            $t->foreign('grupo_id', 'fk_frete_grupoacao_grupo')->references('id')->on('grupo');
            $t->foreign(['recurso_codigo', 'acao_codigo'], 'fk_frete_grupoacao_acao')
                ->references(['recurso_codigo', 'acao_codigo'])->on('recurso_acao');
        });

        Schema::create('usuario', function (Blueprint $t) {
            $t->uuidCol('id');
            $t->string('nome', 150);
            $t->string('login', 200);
            $t->string('email', 200)->nullable();
            $t->string('senha_hash', 255)->nullable();
            $t->uuidCol('transportadora_id')->nullable();
            $t->string('situacao', 20);
            $t->dateTime('liberado_em', 3)->nullable();
            $t->uuidCol('liberado_por')->nullable();
            $t->integer('token_versao')->default(0);
            $t->dateTime('ultimo_acesso_em', 3)->nullable();
            $t->auditoria();
            $t->primary('id', 'pk_frete_usuario');
            $t->unique('login', 'uk_frete_usuario_login');
            $t->foreign('transportadora_id', 'fk_frete_usuario_transp')->references('id')->on('transportadora');
            $t->index('situacao', 'ix_frete_usuario_situacao');
        });
        Esquema::unicoNulavel('usuario', 'email', 'uk_frete_usuario_email');
        Esquema::check('usuario', 'ck_frete_usuario_situacao', "situacao IN ('PENDENTE','ATIVO','BLOQUEADO')");

        Schema::create('usuario_grupo', function (Blueprint $t) {
            $t->uuidCol('usuario_id');
            $t->uuidCol('grupo_id');
            $t->primary(['usuario_id', 'grupo_id'], 'pk_frete_usuario_grupo');
            $t->foreign('usuario_id', 'fk_frete_usugrupo_usuario')->references('id')->on('usuario');
            $t->foreign('grupo_id', 'fk_frete_usugrupo_grupo')->references('id')->on('grupo');
            $t->index('grupo_id', 'ix_frete_usugrupo_grupo');
        });

        Schema::create('usuario_identidade', function (Blueprint $t) {
            $t->uuidCol('id');
            $t->uuidCol('usuario_id');
            $t->string('provedor', 30);
            $t->string('subject', 255);
            $t->string('email', 200)->nullable();
            $t->dateTime('vinculado_em', 3);
            $t->dateTime('ultimo_uso_em', 3)->nullable();
            $t->boolean('ativo')->default(true);
            $t->auditoria();
            $t->primary('id', 'pk_frete_usuario_identidade');
            $t->unique(['provedor', 'subject'], 'uk_frete_usuident_prov_sub');
            $t->foreign('usuario_id', 'fk_frete_usuident_usuario')->references('id')->on('usuario');
            $t->index('usuario_id', 'ix_frete_usuident_usuario');
        });

        Schema::create('refresh_token', function (Blueprint $t) {
            $t->uuidCol('id');
            $t->uuidCol('usuario_id');
            $t->uuidCol('familia_id');
            $t->asciiChar('token_hash', 64);
            $t->dateTime('emitido_em', 3);
            $t->dateTime('expira_em', 3);
            $t->dateTime('usado_em', 3)->nullable();
            $t->dateTime('revogado_em', 3)->nullable();
            $t->string('motivo_revogacao', 30)->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->primary('id', 'pk_frete_refresh_token');
            $t->unique('token_hash', 'uk_frete_refresh_hash');
            $t->foreign('usuario_id', 'fk_frete_refresh_usuario')->references('id')->on('usuario');
            $t->index('usuario_id', 'ix_frete_refresh_usuario');
            $t->index('familia_id', 'ix_frete_refresh_familia');
            $t->index('expira_em', 'ix_frete_refresh_expira');
        });
    }

    public function down(): void
    {
        foreach (['refresh_token', 'usuario_identidade', 'usuario_grupo', 'usuario', 'grupo_acao', 'grupo_permissao', 'grupo', 'recurso_acao', 'recurso', 'transportadora'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
