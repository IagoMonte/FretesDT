<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** O espelho do ERP passa a rodar sozinho: liga as cinco entidades (a trava de URL/chave da ponte continua valendo). */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('sincronizacao')->whereIn('entidade', ['PRODUTO', 'CLIENTE', 'FORNECEDOR', 'TRANSPORTADORA', 'EMBALAGEM'])->update(['ativo' => true]);
    }

    public function down(): void
    {
        DB::table('sincronizacao')->whereIn('entidade', ['PRODUTO', 'CLIENTE', 'FORNECEDOR', 'TRANSPORTADORA', 'EMBALAGEM'])->update(['ativo' => false]);
    }
};
