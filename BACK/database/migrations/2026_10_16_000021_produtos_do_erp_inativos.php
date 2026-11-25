<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * O ERP tem centenas de itens que não são frete: produto importado do ERP passa a nascer inativo e a equipe ativa só os que usa.
 * Aqui os já importados também ficam inativos. Produto cadastrado à mão (origem MANUAL) não muda.
 */
return new class extends Migration
{
    public function up(): void
    {
        $agora = now()->format('Y-m-d H:i:s.v');
        DB::table('produto')->where('origem_cadastro', 'ERP')->where('ativo', true)
            ->update(['ativo' => false, 'atualizado_em' => $agora, 'versao' => DB::raw('versao + 1')]);
    }

    public function down(): void
    {
    }
};
