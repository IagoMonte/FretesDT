<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notificações (WhatsApp plugável + e-mail). `notificacao` é o histórico/fila de envio (1 linha por destinatário e canal);
 * `notificacao_preferencia` é a matriz evento x canal x grupo que o administrador liga/desliga (sem override por usuário).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notificacao', function (Blueprint $t) {
            $t->uuidCol('id');
            $t->string('evento', 40);
            $t->string('entidade', 30);
            $t->string('registro_id', 36);
            $t->string('registro_codigo', 20)->nullable();
            $t->string('canal', 10);
            $t->string('grupo_codigo', 30);
            $t->string('destino_nome', 150)->nullable();
            $t->string('endereco', 200)->nullable();
            $t->string('template', 40)->nullable();
            $t->string('payload', 1000)->nullable();
            $t->string('status', 10);
            $t->integer('tentativas')->default(0);
            $t->string('erro', 500)->nullable();
            $t->string('id_externo', 100)->nullable();
            $t->string('idempotencia_key', 190);
            $t->dateTime('enviado_em', 3)->nullable();
            $t->uuidCol('correlacao_id')->nullable();
            $t->auditoria();
            $t->primary('id', 'pk_frete_notificacao');
            $t->unique('idempotencia_key', 'uk_frete_notif_idem');
            $t->index(['status', 'canal'], 'ix_frete_notif_status');
            $t->index(['entidade', 'registro_id'], 'ix_frete_notif_registro');
        });

        Schema::create('notificacao_preferencia', function (Blueprint $t) {
            $t->string('evento', 40);
            $t->string('canal', 10);
            $t->string('grupo_codigo', 30);
            $t->boolean('ativo');
            $t->auditoria();
            $t->primary(['evento', 'canal', 'grupo_codigo'], 'pk_frete_notif_pref');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notificacao_preferencia');
        Schema::dropIfExists('notificacao');
    }
};
