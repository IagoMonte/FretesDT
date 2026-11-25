<?php

namespace App\Services\Erp;

use Illuminate\Support\Facades\DB;

/**
 * O que o espelho deixou para a equipe resolver no painel FretesDT. Calculado na hora (consultas de contagem),
 * então nunca fica velho: resolveu o cadastro, a pendência some.
 */
class SincronizacaoPendencias
{
    /** @return array<string, int> */
    public function todas(): array
    {
        return [
            'embalagens_a_revisar' => DB::table('embalagem')->where('revisao_pendente', true)->count(),
            'produtos_sem_embalagem' => DB::table('produto')->where('origem_cadastro', 'ERP')->where('ativo', true)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('produto_embalagem')->whereColumn('produto_embalagem.produto_id', 'produto.id'))->count(),
            'clientes_sem_ibge' => $this->semIbge('cliente'),
            'fornecedores_sem_ibge' => $this->semIbge('fornecedor'),
            'transportadoras_sem_ibge' => $this->semIbge('transportadora'),
            'clientes_sem_cnpj' => DB::table('cliente')->where('revisao_pendente', true)->count(),
            'fornecedores_sem_cnpj' => DB::table('fornecedor')->where('revisao_pendente', true)->count(),
            'transportadoras_sem_cnpj' => DB::table('transportadora')->where('revisao_pendente', true)->count(),
            'registros_pulados_7d' => DB::table('log_sistema')->where('categoria', 'SINCRONIZACAO')->where('evento', 'REGISTRO_PULADO')
                ->where('ocorrido_em', '>=', now()->subDays(7))->count(),
        ];
    }

    public function total(): int
    {
        return array_sum($this->todas());
    }

    private function semIbge(string $tabela): int
    {
        return DB::table($tabela)->where('origem_cadastro', 'ERP')->where('ativo', true)->whereNotNull('cidade')->whereNull('cidade_ibge')->count();
    }
}
