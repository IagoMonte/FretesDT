<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Dedicado" deixa de ser da cotação (vendedor) e passa a ser da resposta da transportadora; o fechamento guarda o snapshot.
 * `cotacao.dedicado` fica só como legado (não é mais gravado nem exposto).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['negociacao', 'negociacao_resposta'] as $tabela) {
            Schema::table($tabela, function (Blueprint $t) {
                $t->boolean('dedicado')->default(false);
            });
        }
    }

    public function down(): void
    {
        foreach (['negociacao', 'negociacao_resposta'] as $tabela) {
            Schema::table($tabela, function (Blueprint $t) {
                $t->dropColumn('dedicado');
            });
        }
    }
};
