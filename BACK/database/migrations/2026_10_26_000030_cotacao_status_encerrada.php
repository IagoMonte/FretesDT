<?php

use App\Support\Esquema;
use Illuminate\Database\Migrations\Migration;

/** Novo status de cotação ENCERRADA (encerrada sem fechar). A negociação não muda de status. */
return new class extends Migration
{
    private const ANTIGOS = ['ABERTA', 'EM_NEGOCIACAO', 'FECHADA', 'PROGRAMADA', 'CANCELADA'];

    public function up(): void
    {
        Esquema::removerCheck('cotacao', 'ck_frete_cotacao_status');
        Esquema::check('cotacao', 'ck_frete_cotacao_status', 'status IN ('.Esquema::lista([...self::ANTIGOS, 'ENCERRADA']).')');
    }

    public function down(): void
    {
        Esquema::removerCheck('cotacao', 'ck_frete_cotacao_status');
        Esquema::check('cotacao', 'ck_frete_cotacao_status', 'status IN ('.Esquema::lista(self::ANTIGOS).')');
    }
};
