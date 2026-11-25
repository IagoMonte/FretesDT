<?php

namespace App\Services;

use App\Models\Programacao;
use App\Models\Usuario;
use App\Support\Dinheiro;
use App\Support\Fmt;
use Illuminate\Support\Collection;

/** Números do Painel Operacional (administrador/logística e transportadora), sempre no escopo do usuário. */
class PainelService
{
    private const STATUS = ['EM_COTACAO', 'AGUARDANDO_CARREGAMENTO', 'EM_TRANSITO', 'ENTREGUE', 'DEVOLVIDO'];

    public function resumo(Usuario $u, array $f = []): array
    {
        $transp = $u->ehTransportadora();
        $todas = $this->cargas($u);

        $semStatus = $todas->filter(fn ($c) => $this->passa($c, $f))->values();
        $linhas = isset($f['status']) ? $semStatus->where('status', $f['status'])->values() : $semStatus;

        $porStatus = array_fill_keys(self::STATUS, 0);
        foreach ($semStatus as $c) {
            $porStatus[$c['status']]++;
        }

        $dados = [
            'kpis' => $this->kpis($linhas),
            'por_status' => $porStatus,
            'cargas' => $this->tabela($linhas, $f, $transp),
            'opcoes' => $this->opcoes($todas, $transp),
        ];
        if ($transp) {
            $dados['ranking_destinos'] = $this->rankingDestinos($linhas);
        } else {
            $dados['ranking_transportadoras'] = $this->rankingTransportadoras($linhas);
        }

        return $dados;
    }

    /** Todas as cargas visíveis (menos canceladas), já achatadas para filtrar e somar em memória. */
    private function cargas(Usuario $u): Collection
    {
        $hoje = Fmt::hoje();

        return Programacao::visivelPara($u)->where('status', '<>', 'CANCELADO')
            ->with('itens.produto', 'cliente', 'fornecedor', 'representante', 'transportadora')->get()
            ->map(function (Programacao $p) use ($hoje) {
                return [
                    'id' => $p->id, 'codigo' => $p->codigo,
                    'data' => Fmt::data($p->data_coleta_prevista) ?? Fmt::data($p->data_pedido),
                    'deposito' => $p->deposito, 'pedido' => $p->pedido_venda,
                    'vendedor' => $p->representante?->nome,
                    'produtos' => $p->itens->filter(fn ($i) => $i->produto)->map(fn ($i) => ['id' => $i->produto_id, 'nome' => $i->produto->nome])->unique('id')->values()->all(),
                    'produto' => $p->itens->map(fn ($i) => $i->produto?->nome)->filter()->unique()->implode(', '),
                    'embalagens' => $p->itens->pluck('embalagem_codigo')->unique()->values()->all(),
                    'embalagem' => $p->itens->pluck('embalagem_codigo')->unique()->implode(', '),
                    'kg' => Dinheiro::soma($p->itens->pluck('quantidade_kg')),
                    'cliente' => $p->parte()?->razao_social,
                    'origem' => $this->local($p->origem_cidade, $p->origem_uf),
                    'destino' => $this->local($p->destino_cidade, $p->destino_uf),
                    'frete' => $p->modalidade_frete,
                    'valor_tonelada' => Dinheiro::dec($p->valor_tonelada, 2),
                    'valor_total' => Dinheiro::dec($p->valor_total ?? 0, 2),
                    'transportadora_id' => $p->transportadora_id,
                    'transportadora' => $p->transportadora?->nome_fantasia,
                    'status' => $p->status->value,
                    'prazo' => $this->prazo($p, $hoje),
                ];
            });
    }

    private function local(?string $cidade, ?string $uf): ?string
    {
        return $cidade ? trim($cidade.($uf ? " - {$uf}" : '')) : null;
    }

    /** 'ok' | 'bad' | null. Entregue: realizada ≤ prevista. Em trânsito: previsão de entrega vencida. Aguardando: coleta vencida. */
    private function prazo(Programacao $p, string $hoje): ?string
    {
        return match ($p->status->value) {
            'ENTREGUE' => $p->data_entrega_prevista && $p->data_entrega_realizada
                ? (Fmt::data($p->data_entrega_realizada) <= Fmt::data($p->data_entrega_prevista) ? 'ok' : 'bad') : null,
            'EM_TRANSITO' => $p->data_entrega_prevista && Fmt::data($p->data_entrega_prevista) < $hoje ? 'bad' : 'ok',
            'AGUARDANDO_CARREGAMENTO' => $p->data_coleta_prevista ? (Fmt::data($p->data_coleta_prevista) < $hoje ? 'bad' : 'ok') : null,
            default => null,
        };
    }

    /** Todos os filtros menos o de status (a pizza precisa da contagem inteira). */
    private function passa(array $c, array $f): bool
    {
        if (! empty($f['ano']) && (int) substr((string) $c['data'], 0, 4) !== (int) $f['ano']) {
            return false;
        }
        if (! empty($f['mes']) && (int) substr((string) $c['data'], 5, 2) !== (int) $f['mes']) {
            return false;
        }
        if (! empty($f['produto_id']) && ! in_array($f['produto_id'], array_column($c['produtos'], 'id'), true)) {
            return false;
        }
        if (! empty($f['embalagem']) && ! in_array($f['embalagem'], $c['embalagens'], true)) {
            return false;
        }
        foreach (['frete', 'origem', 'destino', 'transportadora_id'] as $k) {
            if (! empty($f[$k]) && $c[$k] !== $f[$k]) {
                return false;
            }
        }
        if (! empty($f['busca'])) {
            $texto = mb_strtolower(implode(' ', [$c['pedido'], $c['codigo'], $c['cliente'], $c['vendedor'], $c['produto']]));
            if (! str_contains($texto, mb_strtolower($f['busca']))) {
                return false;
            }
        }

        return true;
    }

    private function kpis(Collection $l): array
    {
        $ok = $l->where('prazo', 'ok')->count();
        $bad = $l->where('prazo', 'bad')->count();
        $comValor = $l->filter(fn ($c) => Dinheiro::maior($c['valor_total'], 0))->count();
        $total = Dinheiro::soma($l->pluck('valor_total'), 2);
        $n = fn (string $s) => $l->where('status', $s)->count();

        return [
            'cargas' => $l->count(),
            'aguardando_carregamento' => $n('AGUARDANDO_CARREGAMENTO'),
            'em_transito' => $n('EM_TRANSITO'),
            'entregues' => $n('ENTREGUE'),
            'devolvidas' => $n('DEVOLVIDO'),
            'no_prazo' => $ok,
            'atrasadas' => $bad,
            'pct_no_prazo' => $ok + $bad ? (int) round($ok * 100 / ($ok + $bad)) : 0,
            'cif' => $l->where('frete', 'CIF')->count(),
            'fob' => $l->where('frete', 'FOB')->count(),
            'total_frete' => $total,
            'ticket_medio' => $comValor ? bcdiv($total, (string) $comValor, 2) : '0.00',
            'volume_kg' => Dinheiro::soma($l->pluck('kg')),
        ];
    }

    private function rankingTransportadoras(Collection $l): array
    {
        return $l->filter(fn ($c) => $c['transportadora_id'] && Dinheiro::maior($c['valor_total'], 0))
            ->groupBy('transportadora_id')
            ->map(fn ($g) => ['id' => $g[0]['transportadora_id'], 'nome' => $g[0]['transportadora'], 'pedidos' => $g->count(), 'total' => Dinheiro::soma($g->pluck('valor_total'), 2)])
            ->sortByDesc(fn ($r) => (float) $r['total'])->take(5)->values()->all();
    }

    private function rankingDestinos(Collection $l): array
    {
        return $l->filter(fn ($c) => $c['destino'])->groupBy('destino')
            ->map(fn ($g, $d) => ['destino' => $d, 'cargas' => $g->count(), 'total' => Dinheiro::soma($g->pluck('valor_total'), 2)])
            ->sort(fn ($a, $b) => [(float) $b['total'], $b['cargas']] <=> [(float) $a['total'], $a['cargas']])
            ->take(8)->values()->all();
    }

    private function tabela(Collection $l, array $f, bool $transp): array
    {
        $por = (int) ($f['por_pagina'] ?? 25);
        $pagina = max(1, (int) ($f['pagina'] ?? 1));
        $ordenadas = $l->sort(fn ($a, $b) => [$b['data'], $b['codigo']] <=> [$a['data'], $a['codigo']])->values();
        $campos = ['id', 'codigo', 'data', 'deposito', 'pedido', 'vendedor', 'produto', 'kg', 'embalagem', 'cliente', 'origem', 'destino', 'frete', 'valor_tonelada', 'valor_total', 'transportadora', 'status', 'prazo'];
        if ($transp) {
            $campos = array_diff($campos, ['vendedor', 'transportadora']);
        }

        return [
            'total' => $l->count(), 'pagina' => $pagina, 'por_pagina' => $por,
            'itens' => $ordenadas->slice(($pagina - 1) * $por, $por)->map(fn ($c) => array_intersect_key($c, array_flip($campos)))->values()->all(),
            'total_kg' => Dinheiro::soma($l->pluck('kg')), 'total_frete' => Dinheiro::soma($l->pluck('valor_total'), 2),
        ];
    }

    private function opcoes(Collection $todas, bool $transp): array
    {
        $unicos = fn (string $k) => $todas->pluck($k)->filter()->unique()->sort()->values()->all();
        $op = [
            'anos' => $todas->map(fn ($c) => (int) substr((string) $c['data'], 0, 4))->filter()->unique()->sort()->values()->all(),
            'produtos' => $todas->flatMap(fn ($c) => $c['produtos'])->unique('id')->sortBy('nome')->values()->all(),
            'embalagens' => $todas->flatMap(fn ($c) => $c['embalagens'])->unique()->sort()->values()->all(),
            'origens' => $unicos('origem'),
            'destinos' => $unicos('destino'),
        ];
        if (! $transp) {
            $op['transportadoras'] = $todas->filter(fn ($c) => $c['transportadora_id'])->unique('transportadora_id')
                ->map(fn ($c) => ['id' => $c['transportadora_id'], 'nome' => $c['transportadora']])->sortBy('nome')->values()->all();
        }

        return $op;
    }
}
