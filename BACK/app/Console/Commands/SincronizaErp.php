<?php

namespace App\Console\Commands;

use App\Services\Erp\SincronizaService;
use Illuminate\Console\Command;

/**
 * Espelha os cadastros do ERP (API-ponte). Sem entidade: as ligadas e vencidas (é o que o agendador chama a cada minuto).
 * Com entidade: roda essa, ligada ou não. O sob demanda do painel usa o mesmo serviço e a mesma trava.
 */
class SincronizaErp extends Command
{
    protected $signature = 'erp:sincroniza
        {entidade? : PRODUTO, CLIENTE, FORNECEDOR, TRANSPORTADORA ou EMBALAGEM}
        {--origem=AGENDADA : AGENDADA ou SOB_DEMANDA}
        {--completa : Força a leitura completa (inativa o que sumiu do ERP)}
        {--codigo= : Sincroniza só o registro com este código do ERP}
        {--cnpj= : Sincroniza só o registro com este CNPJ}';

    protected $description = 'Espelha produtos, clientes, fornecedores, transportadoras e embalagens do ERP via API-ponte.';

    public function handle(SincronizaService $sync): int
    {
        $origem = strtoupper((string) $this->option('origem'));
        if (! in_array($origem, ['AGENDADA', 'SOB_DEMANDA'], true)) {
            $this->error('--origem deve ser AGENDADA ou SOB_DEMANDA.');

            return self::INVALID;
        }

        $entidades = $this->argument('entidade') ? [strtoupper($this->argument('entidade'))] : $sync->devidas();
        $registro = array_filter(['codigo' => $this->option('codigo'), 'cnpj' => preg_replace('/\D+/', '', (string) $this->option('cnpj')) ?: null]);
        if ($registro && count($entidades) !== 1) {
            $this->error('--codigo/--cnpj pedem uma entidade.');

            return self::INVALID;
        }

        $falhou = false;
        foreach ($entidades as $entidade) {
            if (! $sync->fonte($entidade)) {
                $this->error("Entidade desconhecida: {$entidade}");

                return self::INVALID;
            }
            $r = $sync->executar($entidade, $origem, null, (bool) $this->option('completa'), $registro);
            if ($r === null) {
                $this->warn("{$entidade}: já há uma sincronização em andamento.");

                continue;
            }
            $this->line("{$entidade}: {$r['status']} · {$r['resumo']}");
            $falhou = $falhou || $r['status'] === 'ERRO';
        }

        return $falhou ? self::FAILURE : self::SUCCESS;
    }
}
