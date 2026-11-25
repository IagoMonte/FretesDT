<?php

namespace App\Services\Erp;

use App\Models\LogOperacao;
use App\Services\AnttService;
use App\Services\Erp\Fontes\ClienteFonte;
use App\Services\Erp\Fontes\EmbalagemFonte;
use App\Services\Erp\Fontes\ErpFonte;
use App\Services\Erp\Fontes\FornecedorFonte;
use App\Services\Erp\Fontes\ProdutoFonte;
use App\Services\Erp\Fontes\TransportadoraFonte;
use App\Services\LogService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Espelha os cadastros do ERP (via API-ponte) nas tabelas locais, em passada única por entidade:
 * pré-carrega os índices locais, lê página a página, normaliza, compara o hash e grava em lote só o que mudou.
 * O banco local é o cache: nada aqui roda durante uma requisição de usuário (o sob demanda responde e executa depois).
 */
class SincronizaService
{
    private const FORMATO = 'Y-m-d H:i:s.v';

    /** Linhas de log por execução (o resto só entra na contagem do resumo). */
    private const LIMITE_LOG = 100;

    public function __construct(private ErpFretesClient $erp, private LogService $log) {}

    /** @return array<string, ErpFonte> entidade => fonte, na ordem em que rodam (embalagem antes do produto). */
    public function fontes(): array
    {
        $todas = [new EmbalagemFonte, new TransportadoraFonte, new ClienteFonte, new FornecedorFonte, new ProdutoFonte];

        return collect($todas)->keyBy(fn (ErpFonte $f) => $f->entidade())->all();
    }

    public function fonte(string $entidade): ?ErpFonte
    {
        return $this->fontes()[strtoupper($entidade)] ?? null;
    }

    /** Entidades ligadas cujo intervalo venceu desde o último sucesso (o agendador roda a cada minuto). */
    public function devidas(): array
    {
        if (! config('services.erp.url') || ! config('services.erp.chave')) {
            return [];
        }

        return DB::table('sincronizacao')->where('ativo', true)->whereNull('executando_desde')->get()
            ->filter(fn ($s) => ! $s->ultima_execucao_em || Carbon::parse($s->ultima_execucao_em)->addMinutes($s->intervalo_minutos)->lte(now()))
            ->pluck('entidade')->intersect(array_keys($this->fontes()))->values()->all();
    }

    /** Reserva a trava da entidade. False = já tem execução rodando (ou a entidade não existe). */
    public function reservar(string $entidade, string $origem, ?string $usuarioId = null): bool
    {
        return DB::transaction(function () use ($entidade, $origem, $usuarioId) {
            $s = DB::table('sincronizacao')->where('entidade', $entidade)->lockForUpdate()->first();
            if (! $s) {
                return false;
            }
            if ($s->executando_desde && Carbon::parse($s->executando_desde)->gt(now()->subMinutes(config('frete.erp.lock_minutos')))) {
                return false;
            }
            DB::table('sincronizacao')->where('entidade', $entidade)->update([
                'executando_desde' => now()->format(self::FORMATO), 'executando_origem' => $origem, 'executando_por_id' => $usuarioId,
            ]);

            return true;
        });
    }

    /**
     * Reserva e executa. Null = já havia execução em andamento.
     *
     * @param  array<string, scalar>  $registro  `codigo` ou `cnpj` para sincronizar um registro só (a ponte filtra no servidor)
     */
    public function executar(string $entidade, string $origem, ?string $usuarioId = null, bool $completa = false, array $registro = []): ?array
    {
        return $this->reservar($entidade, $origem, $usuarioId) ? $this->processar($entidade, $completa, $registro) : null;
    }

    /**
     * Executa com a trava já reservada e a libera no fim, aconteça o que acontecer.
     * Três modos: COMPLETA (lê tudo, é a única que inativa ausentes), INCREMENTAL (só o que a ponte alterou desde a última
     * leitura, via `alteradoDesde`) e REGISTRO (um código/CNPJ). A completa roda na primeira vez e a cada `full_horas`.
     *
     * @return array{status: string, resumo: string, totais: array<string, int|string>}
     */
    public function processar(string $entidade, bool $completa = false, array $registro = []): array
    {
        $fonte = $this->fonte($entidade) ?? throw new \InvalidArgumentException("Entidade desconhecida: {$entidade}");
        $totais = ['modo' => '', 'lidos' => 0, 'criados' => 0, 'atualizados' => 0, 'inalterados' => 0, 'vinculados' => 0, 'pulados' => 0, 'avisos' => 0, 'inativados' => 0];
        $inicio = microtime(true);
        $notas = [];
        $linha = DB::table('sincronizacao')->where('entidade', $entidade)->first(['executando_por_id', 'marca_dagua']);
        $usuarioId = $linha->executando_por_id;
        $marca = $this->lerMarca($linha->marca_dagua);
        $comeco = now();
        [$totais['modo'], $filtros] = $registro ? ['REGISTRO', $registro] : $this->modo($fonte, $marca, $completa);

        try {
            if ($totais['modo'] === 'COMPLETA') {
                $notas[] = $this->conferirFrescor($fonte);
            }
            $this->varrer($fonte, $totais, $filtros);
            $status = $totais['pulados'] > 0 ? 'PARCIAL' : 'SUCESSO';
        } catch (Throwable $e) {
            $status = 'ERRO';
            $notas[] = 'erro: '.mb_substr($e->getMessage(), 0, 200);
            $this->log->sistema('ERROR', 'SINCRONIZACAO', 'SINCRONIZACAO_ERRO', "{$entidade}: ".$e->getMessage(), ['contexto' => ['entidade' => $entidade]]);
        }

        $resumo = $this->resumo($totais, array_filter($notas), microtime(true) - $inicio);
        $agora = now()->format(self::FORMATO);
        $entidadeInteira = $status !== 'ERRO' && $totais['modo'] !== 'REGISTRO';
        if ($entidadeInteira) {
            $marca['D'] = $comeco->toIso8601String();
            if ($totais['modo'] === 'COMPLETA') {
                $marca['F'] = $comeco->toIso8601String();
            }
        }
        DB::table('sincronizacao')->where('entidade', $entidade)->update([
            'executando_desde' => null, 'executando_origem' => null, 'executando_por_id' => null,
            'ultima_execucao_em' => $agora, 'ultima_execucao_status' => $status, 'ultima_execucao_resumo' => $resumo,
        ] + ($entidadeInteira ? ['ultimo_sucesso_em' => $agora, 'marca_dagua' => $this->gravarMarca($marca)] : []));

        LogOperacao::create([
            'ocorrido_em' => now(), 'usuario_id' => $usuarioId, 'entidade' => 'SINCRONIZACAO', 'registro_id' => $entidade, 'registro_codigo' => $entidade,
            'evento' => 'SINCRONIZACAO_CONCLUIDA', 'status_para' => $status, 'detalhe' => $resumo, 'correlacao_id' => Context::get('correlacao_id'),
        ]);

        return ['status' => $status, 'resumo' => $resumo, 'totais' => $totais];
    }

    /** @return array{0: string, 1: array<string, string>} modo e filtros da ponte */
    private function modo(ErpFonte $fonte, array $marca, bool $forcarCompleta): array
    {
        $fullEm = isset($marca['F']) ? Carbon::parse($marca['F']) : null;
        if ($forcarCompleta || ! $fonte->temDelta() || ! $fullEm || ! isset($marca['D']) || $fullEm->addHours(config('frete.erp.full_horas'))->lte(now())) {
            return ['COMPLETA', []];
        }
        $desde = Carbon::parse($marca['D'])->setTimezone(config('frete.fuso_exibicao'))->subDay()->startOfDay();

        return ['INCREMENTAL', ['alteradoDesde' => $desde->format('Y-m-d\TH:i:s')]];
    }

    /** `marca_dagua` = "D=<última leitura>;F=<última leitura completa>" (ISO 8601). */
    private function lerMarca(?string $v): array
    {
        $m = [];
        foreach (explode(';', (string) $v) as $par) {
            [$k, $val] = array_pad(explode('=', $par, 2), 2, '');
            if (in_array($k, ['D', 'F'], true) && $val !== '') {
                $m[$k] = $val;
            }
        }

        return $m;
    }

    private function gravarMarca(array $m): string
    {
        return 'D='.($m['D'] ?? '').';F='.($m['F'] ?? '');
    }

    /** Avisa (sem falhar) quando o último FULL da ponte para esta rota é velho: o espelho dela é que está defasado. */
    private function conferirFrescor(ErpFonte $fonte): ?string
    {
        try {
            $quando = $this->erp->frescor()[$fonte->rota()] ?? null;
        } catch (ErpIndisponivelException) {
            return null;
        }
        if (! $quando) {
            return 'ponte sem FULL registrado';
        }
        $idade = Carbon::parse($quando, config('frete.fuso_exibicao'))->diffInHours(now(), true);
        $nota = 'FULL da ponte '.Carbon::parse($quando)->format('d/m H:i');
        if ($idade > config('frete.erp.frescor_horas')) {
            $this->log->sistema('WARNING', 'SINCRONIZACAO', 'ESPELHO_DEFASADO', "{$fonte->entidade()}: último FULL da ponte há ".(int) $idade.' h.', ['contexto' => ['ultimo_full' => $quando]]);

            return $nota.' (defasado)';
        }

        return $nota;
    }

    private function varrer(ErpFonte $fonte, array &$totais, array $filtros): void
    {
        $completa = $totais['modo'] === 'COMPLETA';
        if ($fonte instanceof ProdutoFonte) {
            app(ProdutoEspelho::class)->varrer($fonte, $totais, $filtros, $completa);

            return;
        }
        $tabela = $fonte->tabela();
        $pk = $fonte->pk();
        $agora = now()->format(self::FORMATO);

        $porErp = [];
        $porChave = [];
        $porCnpj = [];
        $usados = [];
        foreach (DB::table($tabela)->get([$pk, 'codigo_erp', 'erp_hash', ...$fonte->colunasLocais()]) as $l) {
            $usados[$l->$pk] = true;
            if ($l->codigo_erp !== null) {
                $porErp[$l->codigo_erp] = $l;
            } else {
                foreach (array_filter($fonte->chavesLocais($l)) as $c) {
                    $porChave[$c] ??= $l;
                }
            }
            if ($fonte->cnpjUnico() && ($l->cnpj ?? null) !== null) {
                $porCnpj[$l->cnpj] = $l;
            }
        }
        $ibge = $this->resolvedorIbge();

        $vistos = [];
        $logados = 0;
        foreach ($this->erp->paginas($fonte->rota(), $filtros) as $pagina) {
            $novos = [];
            $inalterados = [];
            $mudancas = [];
            foreach ($pagina as $cru) {
                $totais['lidos']++;
                $codigo = $fonte->codigoErp($cru);
                if ($codigo === '') {
                    $this->pular($fonte, $cru, 'Registro sem código no ERP.', $totais, $logados);

                    continue;
                }
                $vistos[$codigo] = true;

                try {
                    ['campos' => $campos, 'avisos' => $avisos] = $fonte->normalizar($cru, $ibge);
                    $existente = $porErp[$codigo] ?? null;
                    $chave = $fonte->chaveCasamento($campos);
                    $dono = $existente ?? ($chave ? ($porChave[$chave] ?? null) : null);
                    if ($fonte->cnpjUnico() && $campos['cnpj'] !== null && ($outro = $porCnpj[$campos['cnpj']] ?? null) && $outro !== $dono) {
                        $avisos[] = "CNPJ {$campos['cnpj']} já pertence a ".($outro->codigo_erp ? "outro código do ERP ({$outro->codigo_erp})" : 'um cadastro local').': entra em branco e vai para revisão.';
                        $campos['cnpj'] = null;
                        $campos['revisao_pendente'] = true;
                        $dono = $existente;
                    }
                } catch (RegistroInvalido $e) {
                    $this->pular($fonte, $cru, $e->getMessage(), $totais, $logados);

                    continue;
                }
                foreach ($avisos as $a) {
                    $totais['avisos']++;
                    if ($logados++ < self::LIMITE_LOG) {
                        $this->log->sistema('WARNING', 'SINCRONIZACAO', 'REGISTRO_COM_AVISO', "{$fonte->entidade()} {$codigo}: {$a}", ['contexto' => ['linha' => $cru]]);
                    }
                }

                $hash = hash('sha256', json_encode($campos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).'|v'.config('frete.erp.versao_normalizador'));
                if ($existente) {
                    if ($existente->erp_hash === $hash) {
                        $inalterados[] = $codigo;
                    } else {
                        $gravar = $campos;
                        if (array_key_exists('cnpj', $campos) && $campos['cnpj'] === null && ! empty($existente->cnpj)) {
                            unset($gravar['cnpj'], $gravar['revisao_pendente']);
                        }
                        $mudancas[] = [$existente->$pk, $gravar, $hash, $codigo];
                        $totais['atualizados']++;
                    }
                } elseif ($dono) {
                    $porChave = array_filter($porChave, fn ($o) => $o !== $dono);
                    $dono->codigo_erp = $codigo;
                    $porErp[$codigo] = $dono;
                    $mudancas[] = [$dono->$pk, $fonte->camposNaFusao($campos), $hash, $codigo];
                    $totais['vinculados']++;
                } else {
                    $extra = $fonte->novo($campos, $codigo, $usados);
                    $chave = $extra[$pk] ?? (string) Str::uuid7();
                    $usados[$chave] = true;
                    $novo = (object) ($campos + [$pk => $chave, 'codigo_erp' => $codigo, 'erp_hash' => $hash, 'cnpj' => $campos['cnpj'] ?? null]);
                    $porErp[$codigo] = $novo;
                    if ($fonte->cnpjUnico() && $campos['cnpj'] !== null) {
                        $porCnpj[$campos['cnpj']] = $novo;
                    }
                    $novos[] = $campos + $extra + [
                        $pk => $chave, 'codigo_erp' => $codigo, 'origem_cadastro' => 'ERP', 'erp_hash' => $hash, 'sincronizado_em' => $agora,
                        'criado_em' => $agora, 'atualizado_em' => $agora, 'versao' => 0,
                    ];
                    $totais['criados']++;
                }
            }

            DB::transaction(function () use ($tabela, $pk, $novos, $mudancas, $inalterados, $agora) {
                foreach (array_chunk($novos, 200) as $lote) {
                    DB::table($tabela)->insert($lote);
                }
                foreach ($mudancas as [$id, $campos, $hash, $codigo]) {
                    DB::table($tabela)->where($pk, $id)->update($campos + [
                        'codigo_erp' => $codigo, 'origem_cadastro' => 'ERP', 'erp_hash' => $hash,
                        'sincronizado_em' => $agora, 'atualizado_em' => $agora, 'versao' => DB::raw('versao + 1'),
                    ]);
                }
                foreach (array_chunk($inalterados, 500) as $lote) {
                    DB::table($tabela)->whereIn('codigo_erp', $lote)->update(['sincronizado_em' => $agora]);
                }
            });
            $totais['inalterados'] += count($inalterados);
        }

        if (! $completa) {
            return;
        }
        foreach (array_chunk(array_map('strval', array_keys(array_diff_key($porErp, $vistos))), 500) as $lote) {
            $totais['inativados'] += DB::table($tabela)->where('origem_cadastro', 'ERP')->where('ativo', true)->whereIn('codigo_erp', $lote)
                ->update(['ativo' => false, 'atualizado_em' => $agora, 'versao' => DB::raw('versao + 1')]);
        }
    }

    private function pular(ErpFonte $fonte, array $cru, string $motivo, array &$totais, int &$logados): void
    {
        $totais['pulados']++;
        if ($logados++ < self::LIMITE_LOG) {
            $this->log->sistema('WARNING', 'SINCRONIZACAO', 'REGISTRO_PULADO', "{$fonte->entidade()} {$fonte->codigoErp($cru)}: {$motivo}", ['contexto' => ['linha' => $cru]]);
        }
    }

    /** (cidade, uf) => IBGE, com a tabela de municípios lida uma vez e só se alguém pedir. */
    private function resolvedorIbge(): \Closure
    {
        $mapa = null;

        return function (?string $cidade, ?string $uf) use (&$mapa): ?string {
            $mapa ??= DB::table('municipio')->get(['ibge', 'uf', 'nome_busca'])->mapWithKeys(fn ($m) => ["{$m->uf}|{$m->nome_busca}" => $m->ibge])->all();

            return $mapa[mb_strtoupper((string) $uf).'|'.AnttService::normalizar((string) $cidade)] ?? null;
        };
    }

    private function resumo(array $t, array $notas, float $segundos): string
    {
        $partes = [strtolower((string) $t['modo']), "lidos {$t['lidos']}", "criados {$t['criados']}", "atualizados {$t['atualizados']}", "vinculados {$t['vinculados']}", "inalterados {$t['inalterados']}", "inativados {$t['inativados']}", "pulados {$t['pulados']}"];
        if ($t['avisos']) {
            $partes[] = "avisos {$t['avisos']}";
        }
        $partes[] = number_format($segundos, 1, ',', '').' s';

        return mb_substr(implode(' · ', $partes).($notas ? ' · '.implode(' · ', $notas) : ''), 0, 500);
    }
}
