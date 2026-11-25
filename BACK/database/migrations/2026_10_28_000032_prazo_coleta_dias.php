<?php

use App\Support\Esquema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prazo de coleta (dias inteiros), ao lado do prazo de entrega (prazo_dias). Só informativo: não altera datas previstas nem cálculos.
 * NULL = não informado (dados legados e cotações que não são fracionadas).
 */
return new class extends Migration
{
    private const TABELAS = [
        'negociacao' => 'ck_frete_negociacao_coleta',
        'negociacao_resposta' => 'ck_frete_negresp_coleta',
        'programacao' => 'ck_frete_prog_coleta',
    ];

    public function up(): void
    {
        foreach (self::TABELAS as $tabela => $check) {
            Schema::table($tabela, function (Blueprint $t) {
                $t->smallInteger('prazo_coleta_dias')->nullable()->after('prazo_dias');
            });
            Esquema::check($tabela, $check, '(prazo_coleta_dias IS NULL OR prazo_coleta_dias >= 0)');
        }
    }

    public function down(): void
    {
        foreach (self::TABELAS as $tabela => $check) {
            Esquema::removerCheck($tabela, $check);
            Schema::table($tabela, function (Blueprint $t) {
                $t->dropColumn('prazo_coleta_dias');
            });
        }
    }
};
