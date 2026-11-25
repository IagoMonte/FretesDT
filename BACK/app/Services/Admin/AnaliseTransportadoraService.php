<?php

namespace App\Services\Admin;

use App\Support\Periodo;
use Illuminate\Support\Collection;

/** Desempenho e preço por transportadora: quem responde, quem ganha, onde ganha e onde perde. */
class AnaliseTransportadoraService
{
    public function __construct(private Base $base) {}

    /** Uma linha por transportadora, ordenada por saving gerado. */
    public function ranking(Periodo $p): array
    {
        $cot = $this->base->cotacoes($p);
        $nomes = $this->base->transportadoras();
        $linhas = [];

        foreach ($nomes as $tid => $nome) {
            $linhas[] = $this->linha($tid, $nome, $cot);
        }

        usort($linhas, fn ($a, $b) => [$b['saving'], $b['vitorias'], $a['nome']] <=> [$a['saving'], $a['vitorias'], $b['nome']]);

        return $linhas;
    }

    private function linha(string $tid, string $nome, Collection $cot): array
    {
        $consultas = collect();
        $vitorias = collect();
        $perdas = collect();
        $posicoes = [];
        $progs = collect();

        foreach ($cot as $c) {
            foreach ($c['respostas'] as $r) {
                if ($r['tid'] === $tid) {
                    $consultas->push($r + ['cotacao' => $c]);
                }
            }
            if ($c['fechada'] && $c['vencedora'] === $tid && $c['status'] !== 'CANCELADA') {
                $vitorias->push($c);
            }
            foreach ($c['programacoes'] as $g) {
                if ($g['tid'] === $tid) {
                    $progs->push($g + ['vencedora' => $c['vencedora']]);
                }
            }
        }

        $respondidas = $consultas->where('status', 'RESPONDIDA');
        foreach ($respondidas as $r) {
            $c = $r['cotacao'];
            if ($c['fechada'] && $c['vencedora'] !== $tid) {
                $perdas->push(['gap' => $r['vt'] - $c['fechado_vt'], 'pct' => $c['fechado_vt'] > 0 ? ($r['vt'] - $c['fechado_vt']) * 100 / $c['fechado_vt'] : 0.0]);
            }
            $ordem = collect($c['respostas'])->where('status', 'RESPONDIDA')->pluck('vt')->sort()->values();
            $posicoes[] = $ordem->search($r['vt']) + 1;
        }
        $declinadas = $consultas->where('status', 'DECLINADA')->count();
        $vts = $respondidas->pluck('vt');
        $entregues = $progs->where('status', 'ENTREGUE')->filter(fn ($g) => Base::atraso($g) !== null);

        return [
            'id' => $tid,
            'nome' => $nome,
            'consultas' => $consultas->count(),
            'respondidas' => $respondidas->count(),
            'declinadas' => $declinadas,
            'aguardando' => $consultas->where('status', 'AGUARDANDO')->count(),
            'vitorias' => $vitorias->count(),
            'taxa_vitoria' => Base::pct($vitorias->count(), $respondidas->count()),
            'taxa_resposta' => Base::pct($respondidas->count(), $consultas->count()),
            'taxa_declinio' => Base::pct($declinadas, $respondidas->count() + $declinadas),
            'min' => $vts->isEmpty() ? 0.0 : (float) $vts->min(),
            'max' => $vts->isEmpty() ? 0.0 : (float) $vts->max(),
            'media' => Base::media($vts),
            'prazo_medio' => Base::media($respondidas->pluck('prazo')),
            'tempo_resposta_horas' => Base::media($respondidas->filter(fn ($r) => $r['enc'] && $r['resp'])->map(fn ($r) => $r['enc']->diffInMinutes($r['resp']) / 60), 1),
            'saving' => round((float) $vitorias->sum('saving'), 2),
            'gap_medio' => Base::media($perdas->pluck('gap')),
            'gap_pct' => Base::media($perdas->pluck('pct')),
            'posicao_media' => Base::media($posicoes, 1),
            'frete_contratado' => round((float) $progs->sum('total'), 2),
            'cargas' => $progs->count(),
            'pct_no_prazo' => Base::pct($entregues->filter(fn ($g) => Base::atraso($g) <= 0)->count(), $entregues->count()),
            'divergencias' => $progs->filter(fn ($g) => $g['vencedora'] !== null && $g['vencedora'] !== $tid)->count(),
        ];
    }

    /** Faixa de preço do mercado por produto (todas as respostas RESPONDIDA). */
    public function mercadoPorProduto(Periodo $p): array
    {
        $acc = [];
        foreach ($this->base->cotacoes($p) as $c) {
            foreach ($c['respostas'] as $r) {
                if ($r['status'] !== 'RESPONDIDA') {
                    continue;
                }
                foreach ($c['produtos'] as $pr) {
                    $acc[$pr['id']]['produto'] = $pr['nome'];
                    $acc[$pr['id']]['vts'][] = $r['vt'];
                }
            }
        }

        return collect($acc)->map(fn ($a, $id) => ['produto_id' => $id, 'produto' => $a['produto']] + $this->faixa($a['vts']))
            ->sortBy('produto')->values()->all();
    }

    /** transportadora × produto, com min/máx/média do valor/t respondido. */
    public function matrizProduto(Periodo $p): array
    {
        $nomes = $this->base->transportadoras();
        $acc = [];
        foreach ($this->base->cotacoes($p) as $c) {
            foreach ($c['respostas'] as $r) {
                if ($r['status'] !== 'RESPONDIDA') {
                    continue;
                }
                foreach ($c['produtos'] as $pr) {
                    $k = $r['tid'].'|'.$pr['id'];
                    $acc[$k] ??= ['transportadora_id' => $r['tid'], 'transportadora' => $nomes[$r['tid']] ?? '—', 'produto_id' => $pr['id'], 'produto' => $pr['nome'], 'vts' => []];
                    $acc[$k]['vts'][] = $r['vt'];
                }
            }
        }

        return collect($acc)->map(fn ($a) => collect($a)->except('vts')->all() + $this->faixa($a['vts']))
            ->sortBy([['produto', 'asc'], ['transportadora', 'asc']])->values()->all();
    }

    /**
     * Onde cada transportadora ganha e perde, por 'rota', 'produto', 'veiculo' ou 'operacao'.
     *
     * @return list<array<string, mixed>>
     */
    public function ganhaPerde(Periodo $p, string $dimensao = 'rota', ?string $transportadoraId = null): array
    {
        $nomes = $this->base->transportadoras();
        $acc = [];

        foreach ($this->base->cotacoes($p) as $c) {
            if (! $c['fechada']) {
                continue;
            }
            $chaves = match ($dimensao) {
                'produto' => collect($c['produtos'])->pluck('nome')->unique()->all(),
                'veiculo' => [$c['veiculo']],
                'operacao' => [$c['operacao']],
                default => [$c['rota']],
            };
            $respondidas = collect($c['respostas'])->where('status', 'RESPONDIDA');
            $segundo = $respondidas->where('tid', '!=', $c['vencedora'])->pluck('vt')->sort()->first();

            foreach ($chaves as $chave) {
                $ganhou = $c['vencedora'];
                if ($ganhou && (! $transportadoraId || $transportadoraId === $ganhou)) {
                    $a = &$acc[$ganhou.'|'.$chave];
                    $a ??= $this->vazia($ganhou, $nomes, $chave);
                    $a['vitorias']++;
                    if ($segundo !== null) {
                        $a['margens'][] = $segundo - $c['fechado_vt'];
                    }
                    unset($a);
                }
                foreach ($respondidas->where('tid', '!=', $c['vencedora']) as $r) {
                    if ($transportadoraId && $transportadoraId !== $r['tid']) {
                        continue;
                    }
                    $a = &$acc[$r['tid'].'|'.$chave];
                    $a ??= $this->vazia($r['tid'], $nomes, $chave);
                    $a['derrotas']++;
                    $a['gaps'][] = $r['vt'] - $c['fechado_vt'];
                    $a['gaps_pct'][] = $c['fechado_vt'] > 0 ? ($r['vt'] - $c['fechado_vt']) * 100 / $c['fechado_vt'] : 0.0;
                    unset($a);
                }
            }
        }

        return collect($acc)->map(fn ($a) => [
            'transportadora_id' => $a['transportadora_id'], 'transportadora' => $a['transportadora'], 'chave' => $a['chave'],
            'vitorias' => $a['vitorias'], 'derrotas' => $a['derrotas'],
            'gap_medio' => Base::media($a['gaps']), 'gap_pct' => Base::media($a['gaps_pct']), 'margem_media' => Base::media($a['margens']),
        ])->sortBy([['transportadora', 'asc'], ['chave', 'asc']])->values()->all();
    }

    private function vazia(string $tid, array $nomes, string $chave): array
    {
        return ['transportadora_id' => $tid, 'transportadora' => $nomes[$tid] ?? '—', 'chave' => $chave, 'vitorias' => 0, 'derrotas' => 0, 'gaps' => [], 'gaps_pct' => [], 'margens' => []];
    }

    /** Onde há espaço para melhorar a base de custo. */
    public function oportunidades(Periodo $p): array
    {
        $poucos = [];
        $fora = [];
        $nomes = $this->base->transportadoras();
        $porProduto = [];

        foreach ($this->base->cotacoes($p) as $c) {
            if ($c['status'] === 'CANCELADA') {
                continue;
            }
            $precos = collect($c['precos']);
            $produtos = collect($c['produtos'])->pluck('nome')->unique()->implode(', ');
            if ($precos->count() >= 1 && $precos->count() <= 2) {
                $poucos[] = ['codigo' => $c['codigo'], 'rota' => $c['rota'], 'produto' => $produtos, 'respostas' => $precos->count()];
            }
            if ($c['fechada'] && $precos->isNotEmpty() && $c['fechado_vt'] > $precos->min()) {
                $fora[] = [
                    'codigo' => $c['codigo'], 'rota' => $c['rota'], 'produto' => $produtos, 'vencedora' => $nomes[$c['vencedora']] ?? '—',
                    'fechado' => $c['fechado_vt'], 'menor' => (float) $precos->min(),
                    'perdido' => round(($c['fechado_vt'] - $precos->min()) * $c['t'], 2),
                ];
            }
            foreach ($c['produtos'] as $pr) {
                foreach ($c['respostas'] as $r) {
                    if ($r['status'] === 'RESPONDIDA') {
                        $porProduto[$pr['nome']][] = $r['vt'];
                    }
                }
            }
        }

        $dispersao = collect($porProduto)->filter(fn ($v) => count($v) >= 2)->map(fn ($v, $nome) => ['produto' => $nome] + $this->faixa($v) + ['amplitude' => round(max($v) - min($v), 2)])
            ->sortByDesc('amplitude')->values()->all();

        return ['poucos_concorrentes' => $poucos, 'fora_do_menor' => $fora, 'dispersao' => $dispersao];
    }

    public function detalhe(string $tid, Periodo $p): ?array
    {
        $nomes = $this->base->transportadoras();
        if (! isset($nomes[$tid])) {
            return null;
        }
        $cot = $this->base->cotacoes($p);
        $linha = $this->linha($tid, $nomes[$tid], $cot);

        $mercado = collect($this->mercadoPorProduto($p))->keyBy('produto');
        $porProduto = collect($this->matrizProduto($p))->where('transportadora_id', $tid)->map(fn ($l) => $l + [
            'mercado_min' => $mercado[$l['produto']]['min'] ?? 0.0,
            'mercado_max' => $mercado[$l['produto']]['max'] ?? 0.0,
            'mercado_media' => $mercado[$l['produto']]['media'] ?? 0.0,
        ])->values()->all();

        $ultimas = [];
        foreach ($cot as $c) {
            foreach ($c['respostas'] as $r) {
                if ($r['tid'] === $tid && in_array($r['status'], ['RESPONDIDA', 'DECLINADA'], true)) {
                    $ultimas[] = [
                        'codigo' => $c['codigo'], 'cotacao_id' => $c['id'], 'rota' => $c['rota'], 'produto' => collect($c['produtos'])->pluck('nome')->unique()->implode(', '),
                        'status' => $r['status'], 'vt' => $r['vt'], 'prazo' => $r['prazo'], 'quando' => $r['resp'] ?? $r['enc'],
                        'venceu' => $c['fechada'] && $c['vencedora'] === $tid, 'fechado_vt' => $c['fechado_vt'],
                    ];
                }
            }
        }
        usort($ultimas, fn ($a, $b) => $b['quando'] <=> $a['quando']);

        $serie = [];
        foreach ($cot as $c) {
            foreach ($c['respostas'] as $r) {
                if ($r['status'] === 'RESPONDIDA') {
                    $mes = substr($c['data'], 0, 7);
                    $serie[$mes]['mercado'][] = $r['vt'];
                    if ($r['tid'] === $tid) {
                        $serie[$mes]['propria'][] = $r['vt'];
                    }
                }
            }
        }
        ksort($serie);

        return $linha + [
            'ganha_perde_rota' => $this->ganhaPerde($p, 'rota', $tid),
            'ganha_perde_produto' => $this->ganhaPerde($p, 'produto', $tid),
            'ganha_perde_veiculo' => $this->ganhaPerde($p, 'veiculo', $tid),
            'por_produto' => $porProduto,
            'ultimas_respostas' => array_slice($ultimas, 0, 15),
            'serie_preco' => collect($serie)->map(fn ($s, $mes) => ['mes' => $mes, 'propria' => empty($s['propria']) ? null : Base::media($s['propria']), 'mercado' => Base::media($s['mercado'])])->values()->all(),
        ];
    }

    private function faixa(array $vts): array
    {
        return ['min' => (float) min($vts), 'max' => (float) max($vts), 'media' => Base::media($vts), 'respostas' => count($vts)];
    }
}
