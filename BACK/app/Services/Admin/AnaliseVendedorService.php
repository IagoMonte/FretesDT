<?php

namespace App\Services\Admin;

use App\Support\Periodo;
use Illuminate\Support\Collection;

/** Indicadores comerciais por vendedor (quem criou a cotação). */
class AnaliseVendedorService
{
    private const DIAS = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];

    public function __construct(private Base $base) {}

    /** Ordenado por frete total contratado. */
    public function ranking(Periodo $p): array
    {
        return $this->base->cotacoes($p)->groupBy('vendedor_id')
            ->map(fn (Collection $g, string $vid) => $this->linha($vid, $g))
            ->sortByDesc(fn ($l) => [$l['frete_total'], $l['cotacoes']])->values()->all();
    }

    private function linha(string $vid, Collection $g): array
    {
        $total = $g->count();
        $fechadas = $g->filter(fn ($c) => in_array($c['status'], Base::FECHADAS, true));
        $progs = $g->flatMap(fn ($c) => $c['programacoes']);
        $toneladas = (float) $fechadas->sum('t');
        $frete = (float) $progs->sum('total');
        $horas = $g->filter(fn ($c) => $c['fechada'] && $c['fechado_em'] && $c['criado_em'])->map(fn ($c) => $c['criado_em']->diffInMinutes($c['fechado_em']) / 60);

        return [
            'id' => $vid,
            'nome' => $g->first()['vendedor'],
            'cotacoes' => $total,
            'fechadas' => $fechadas->count(),
            'canceladas' => $g->where('status', 'CANCELADA')->count(),
            'abertas' => $g->whereIn('status', ['ABERTA', 'EM_NEGOCIACAO'])->count(),
            'taxa_fechamento' => Base::pct($fechadas->count(), $total),
            'taxa_cancelamento' => Base::pct($g->where('status', 'CANCELADA')->count(), $total),
            'saving' => round((float) $g->sum('saving'), 2),
            'toneladas' => round($toneladas, 3),
            'frete_total' => round($frete, 2),
            'frete_t_medio' => $toneladas > 0 ? round($frete / $toneladas, 2) : 0.0,
            'tempo_fechamento_horas' => Base::media($horas, 1),
            'cargas' => $progs->count(),
            'valor_nf' => round((float) $g->sum('valor_nf'), 2),
            'pct_repetidas' => Base::pct($g->where('repetida', true)->count(), $total),
        ];
    }

    public function detalhe(string $vid, Periodo $p): ?array
    {
        $g = $this->base->cotacoes($p)->where('vendedor_id', $vid)->values();
        if ($g->isEmpty()) {
            $nome = \App\Models\Usuario::whereKey($vid)->value('nome');
            if ($nome === null) {
                return null;
            }
            $g = collect();
            $linha = ['id' => $vid, 'nome' => $nome] + array_fill_keys(['cotacoes', 'fechadas', 'canceladas', 'abertas', 'cargas'], 0)
                + array_fill_keys(['taxa_fechamento', 'taxa_cancelamento', 'saving', 'toneladas', 'frete_total', 'frete_t_medio', 'tempo_fechamento_horas', 'valor_nf', 'pct_repetidas'], 0.0);
        } else {
            $linha = $this->linha($vid, $g);
        }

        $semana = array_fill(0, 7, 0);
        $mensal = [];
        foreach ($g as $c) {
            $semana[(int) $c['criado_em']->setTimezone(config('frete.fuso_exibicao'))->format('N') - 1]++;
            $m = substr($c['data'], 0, 7);
            $mensal[$m] ??= ['mes' => $m, 'cotacoes' => 0, 'fechadas' => 0, 'saving' => 0.0];
            $mensal[$m]['cotacoes']++;
            $mensal[$m]['fechadas'] += in_array($c['status'], Base::FECHADAS, true) ? 1 : 0;
            $mensal[$m]['saving'] = round($mensal[$m]['saving'] + $c['saving'], 2);
        }
        ksort($mensal);

        return $linha + [
            'top_clientes' => $g->groupBy('parte')->map(fn ($x, $nome) => ['cliente' => $nome, 'cotacoes' => $x->count(), 'toneladas' => round((float) $x->sum('t'), 3), 'valor_nf' => round((float) $x->sum('valor_nf'), 2)])
                ->sortByDesc('cotacoes')->take(8)->values()->all(),
            'mix_operacao' => ['CARREGAMENTO' => $g->where('operacao', 'CARREGAMENTO')->count(), 'RECEBIMENTO' => $g->where('operacao', 'RECEBIMENTO')->count()],
            'por_dia_semana' => array_map(fn ($i) => ['dia' => self::DIAS[$i], 'cotacoes' => $semana[$i]], array_keys(self::DIAS)),
            'por_mes' => array_values($mensal),
            'ultimas' => $g->sortByDesc('data')->take(10)->map(fn ($c) => [
                'id' => $c['id'], 'codigo' => $c['codigo'], 'status' => $c['status'], 'parte' => $c['parte'], 'rota' => $c['rota'], 'toneladas' => round($c['t'], 3), 'saving' => round($c['saving'], 2),
            ])->values()->all(),
        ];
    }
}
