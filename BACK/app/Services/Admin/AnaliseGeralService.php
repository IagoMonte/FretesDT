<?php

namespace App\Services\Admin;

use App\Models\NegociacaoResposta;
use App\Models\Programacao;
use App\Models\Usuario;
use App\Support\Periodo;
use Carbon\CarbonImmutable;

/** Visão geral: KPIs, séries, rotas e alertas acionáveis. */
class AnaliseGeralService
{
    public const STATUS = ['ABERTA', 'EM_NEGOCIACAO', 'FECHADA', 'PROGRAMADA', 'CANCELADA', 'ENCERRADA'];

    public const DIAS_COTACAO_PARADA = 3;

    public const DIAS_RESPOSTA_VENCIDA = 2;

    public function __construct(private Base $base) {}

    public function kpis(Periodo $p): array
    {
        $cot = $this->base->cotacoes($p);

        $porStatus = array_fill_keys(self::STATUS, 0);
        foreach ($cot as $c) {
            $porStatus[$c['status']]++;
        }

        $programadas = $cot->where('status', 'PROGRAMADA');
        $progs = $cot->flatMap(fn ($c) => $c['programacoes']);
        $frete = (float) $progs->sum('total');
        $toneladas = (float) $programadas->sum('t');
        $entregues = $progs->where('status', 'ENTREGUE')->filter(fn ($g) => Base::atraso($g) !== null);
        $atrasos = $entregues->map(fn ($g) => Base::atraso($g))->filter(fn ($d) => $d > 0);
        $fechadas = $cot->filter(fn ($c) => $c['fechada']);
        $horas = $fechadas->filter(fn ($c) => $c['fechado_em'] && $c['criado_em'])
            ->map(fn ($c) => $c['criado_em']->diffInMinutes($c['fechado_em']) / 60);

        return [
            'cotacoes_total' => $cot->count(),
            'por_status' => $porStatus,
            'conversao_pct' => Base::pct($porStatus['PROGRAMADA'], $cot->count()),
            'saving_total' => round((float) $cot->sum('saving'), 2),
            'saving_pct' => Base::pct((float) $cot->sum('saving'), (float) $cot->sum('saving_ref')),
            'frete_total' => round($frete, 2),
            'toneladas' => round($toneladas, 3),
            'frete_t_medio' => $toneladas > 0 ? round($frete / $toneladas, 2) : 0.0,
            'pct_no_prazo' => Base::pct($entregues->filter(fn ($g) => Base::atraso($g) <= 0)->count(), $entregues->count()),
            'atraso_medio_dias' => Base::media($atrasos),
            'tempo_fechamento_horas' => Base::media($horas, 1),
            'entregas' => $entregues->count(),
            'cargas' => $progs->count(),
        ];
    }

    /** Cada KPI com o valor do período anterior e a variação percentual. */
    public function comparativo(Periodo $p): array
    {
        $atual = $this->kpis($p);
        $ant = $this->kpis($p->anterior());
        $out = [];
        foreach (['cotacoes_total', 'conversao_pct', 'saving_total', 'frete_total', 'toneladas', 'frete_t_medio', 'pct_no_prazo', 'tempo_fechamento_horas'] as $k) {
            $out[$k] = ['atual' => $atual[$k], 'anterior' => $ant[$k], 'delta' => $ant[$k] > 0 ? round(($atual[$k] - $ant[$k]) * 100 / $ant[$k], 1) : null];
        }

        return $out;
    }

    /** @return list<array{mes: string, cotacoes: int, frete_total: float, saving: float}> */
    public function serieMensal(Periodo $p): array
    {
        $meses = [];
        foreach ($this->base->cotacoes($p)->groupBy(fn ($c) => substr($c['data'], 0, 7))->sortKeys() as $mes => $grupo) {
            $meses[] = [
                'mes' => $mes,
                'cotacoes' => $grupo->count(),
                'frete_total' => round((float) $grupo->flatMap(fn ($c) => $c['programacoes'])->sum('total'), 2),
                'saving' => round((float) $grupo->sum('saving'), 2),
                'frete_t' => ($t = (float) $grupo->where('status', 'PROGRAMADA')->sum('t')) > 0
                    ? round((float) $grupo->flatMap(fn ($c) => $c['programacoes'])->sum('total') / $t, 2) : 0.0,
            ];
        }

        return $meses;
    }

    /** @return list<array{rota: string, cotacoes: int, toneladas: float, frete_total: float}> */
    public function topRotas(Periodo $p, int $limite = 8): array
    {
        return $this->base->cotacoes($p)->where('status', '!=', 'CANCELADA')->groupBy('rota')->map(fn ($g, $rota) => [
            'rota' => $rota,
            'cotacoes' => $g->count(),
            'toneladas' => round((float) $g->sum('t'), 3),
            'frete_total' => round((float) $g->flatMap(fn ($c) => $c['programacoes'])->sum('total'), 2),
        ])->sortByDesc(fn ($r) => [$r['cotacoes'], $r['toneladas']])->take($limite)->values()->all();
    }

    /** @return list<array{produto: string, cotacoes: int, toneladas: float}> */
    public function topProdutos(Periodo $p, int $limite = 8): array
    {
        $linhas = [];
        foreach ($this->base->cotacoes($p)->where('status', '!=', 'CANCELADA') as $c) {
            foreach ($c['produtos'] as $pr) {
                $l = &$linhas[$pr['nome']];
                $l = ($l ?? ['produto' => $pr['nome'], 'cotacoes' => 0, 'toneladas' => 0.0]);
                $l['cotacoes']++;
                $l['toneladas'] = round($l['toneladas'] + $pr['kg'] / 1000, 3);
                unset($l);
            }
        }

        return collect($linhas)->sortByDesc('toneladas')->take($limite)->values()->all();
    }

    /** @return array{CARREGAMENTO: int, RECEBIMENTO: int} */
    public function porOperacao(Periodo $p): array
    {
        $cot = $this->base->cotacoes($p);

        return ['CARREGAMENTO' => $cot->where('operacao', 'CARREGAMENTO')->count(), 'RECEBIMENTO' => $cot->where('operacao', 'RECEBIMENTO')->count()];
    }

    /** Pendências de hoje (independem do período). */
    public function alertas(): array
    {
        $agora = CarbonImmutable::now();

        $paradas = \App\Models\Cotacao::query()->whereIn('status', ['ABERTA', 'EM_NEGOCIACAO'])
            ->where('criado_em', '<', $agora->subDays(self::DIAS_COTACAO_PARADA)->format('Y-m-d H:i:s.v'))->count();

        $vencidas = NegociacaoResposta::query()->where('status', 'AGUARDANDO')
            ->where('encaminhado_em', '<', $agora->subDays(self::DIAS_RESPOSTA_VENCIDA)->format('Y-m-d H:i:s.v'))
            ->whereHas('negociacao', fn ($q) => $q->where('status', 'ABERTA'))->count();

        $hoje = $agora->setTimezone(config('frete.fuso_exibicao'))->toDateString();
        $atrasadas = Programacao::query()->where('status', '!=', 'CANCELADO')->get()->filter(function ($g) use ($hoje) {
            $prev = Base::dia($g->data_entrega_prevista);
            $real = Base::dia($g->data_entrega_realizada);

            return $prev && ($real ? $real > $prev : ! in_array($g->status->value, ['ENTREGUE', 'DEVOLVIDO'], true) && $prev < $hoje);
        })->count();

        return [
            'cotacoes_paradas' => $paradas,
            'respostas_vencidas' => $vencidas,
            'entregas_atrasadas' => $atrasadas,
            'usuarios_pendentes' => Usuario::where('situacao', 'PENDENTE')->count(),
        ];
    }
}
