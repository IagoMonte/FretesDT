<?php

use App\Support\Esquema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v1.2: auto-cadastro com aprovação do administrador. A situação do usuário ganha RECUSADO e a
 * tabela guarda o pedido (tipo, dados da transportadora) e a decisão (quem, quando, motivo).
 * Usuários existentes não mudam de situação.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuario', function (Blueprint $t) {
            $t->string('tipo_solicitado', 20)->nullable();
            $t->string('cad_razao_social', 200)->nullable();
            $t->string('cad_nome_fantasia', 150)->nullable();
            $t->asciiChar('cad_cnpj', 14)->nullable();
            $t->string('motivo_recusa', 500)->nullable();
            $t->uuidCol('decidido_por')->nullable();
            $t->dateTime('decidido_em', 3)->nullable();
        });

        Esquema::removerCheck('usuario', 'ck_frete_usuario_situacao');
        Esquema::check('usuario', 'ck_frete_usuario_situacao', "situacao IN ('PENDENTE','ATIVO','BLOQUEADO','RECUSADO')");
        Esquema::check('usuario', 'ck_frete_usuario_tipo_solic', "tipo_solicitado IN ('VENDEDOR','TRANSPORTADORA')");
    }

    public function down(): void
    {
        DB::table('usuario')->where('situacao', 'RECUSADO')->update(['situacao' => 'BLOQUEADO']);
        Esquema::removerCheck('usuario', 'ck_frete_usuario_tipo_solic');
        Esquema::removerCheck('usuario', 'ck_frete_usuario_situacao');
        Esquema::check('usuario', 'ck_frete_usuario_situacao', "situacao IN ('PENDENTE','ATIVO','BLOQUEADO')");
        Schema::table('usuario', function (Blueprint $t) {
            $t->dropColumn(['tipo_solicitado', 'cad_razao_social', 'cad_nome_fantasia', 'cad_cnpj', 'motivo_recusa', 'decidido_por', 'decidido_em']);
        });
    }
};
