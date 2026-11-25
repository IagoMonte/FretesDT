<?php

namespace App\Services\Admin;

use App\Models\Cotacao;
use App\Models\Transportadora;
use App\Support\Periodo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Carrega o recorte de cotações do período já normalizado (uma linha por cotação, com itens,
 * respostas, fechamento e programações). Todas as análises do /admin partem daqui, para que
 * "saving", "venceu" e "perdeu" tenham uma definição só:
 *
 *  - preços efetivos = respostas RESPONDIDA da própria cotação + as da cotação original (repetida),
 *    sem duplicar a transportadora;
 *  - saving = (valor fechado − menor preço efetivo) × toneladas, só em negociação FECHADA; pode ser negativo
 *    (positivo = fechou acima da menor resposta; negativo = fechou abaixo dela, p.ex. negociado por fora);
 *  - venceu = é a transportadora do fechamento; perdeu = respondeu e não venceu.
 */
class Base
{
    public const FECHADAS = ['FECHADA', 'PROGRAMADA'];

    /** @return Collection<int, array<string, mixed>> */
    public function cotacoes(Periodo $p): Collection
    {
        $cotacoes = Cotacao::query()
            ->whereBetween('data_solicitacao', [$p->de->toDateString(), $p->ate->toDateString()])
            ->with(['itens.produto', 'negociacao.respostas', 'programacoes', 'cliente', 'fornecedor', 'vendedor'])
            ->orderBy('data_solicitacao')->orderBy('codigo')
            ->get();

        $origens = $cotacoes->pluck('cotacao_origem_id')->filter()->unique()->values();
        $respostasOrigem = $origens->isEmpty() ? collect() : Cotacao::with('negociacao.respostas')->whereIn('id', $origens)->get()
            ->filter(fn ($o) => $o->status->value !== 'CANCELADA')->keyBy('id');

        return $cotacoes->map(fn (Cotacao $c) => $this->linha($c, $respostasOrigem->get($c->cotacao_origem_id)))->values();
    }

    /** @return array<string, string> id => nome */
    public function transportadoras(): array
    {
        return Transportadora::query()->get()->mapWithKeys(fn ($t) => [$t->id => $t->nome_fantasia ?: $t->razao_social])->all();
    }

    private function linha(Cotacao $c, ?Cotacao $origem): array
    {
        $kg = (float) $c->itens->sum('quantidade_kg');
        $neg = $c->negociacao;
        $fechada = $neg && $neg->status->value === 'FECHADA';

        $proprias = $neg ? $neg->respostas->map(fn ($r) => [
            'tid' => $r->transportadora_id, 'status' => $r->status->value,
            'vt' => $r->valor_tonelada !== null ? (float) $r->valor_tonelada : null,
            'prazo' => $r->prazo_dias, 'enc' => $r->encaminhado_em, 'resp' => $r->respondido_em,
        ])->values()->all() : [];

        $efetivos = collect($proprias)->where('status', 'RESPONDIDA')->pluck('vt', 'tid');
        if ($origem?->negociacao) {
            foreach ($origem->negociacao->respostas as $r) {
                if ($r->status->value === 'RESPONDIDA' && ! $efetivos->has($r->transportadora_id)) {
                    $efetivos->put($r->transportadora_id, (float) $r->valor_tonelada);
                }
            }
        }

        $fechadoVt = $fechada ? (float) $neg->valor_tonelada : null;
        $toneladas = $kg / 1000;
        $min = $efetivos->isNotEmpty() ? $efetivos->min() : null;
        $saving = $fechada && $min !== null ? ($fechadoVt - $min) * $toneladas : 0.0;
        $savingMedia = $fechada && $efetivos->isNotEmpty() ? max(0.0, ($efetivos->avg() - $fechadoVt) * $toneladas) : 0.0;

        return [
            'id' => $c->id,
            'codigo' => $c->codigo,
            'status' => $c->status->value,
            'data' => substr((string) $c->getRawOriginal('data_solicitacao'), 0, 10),
            'criado_em' => $c->criado_em,
            'vendedor_id' => $c->vendedor_id,
            'vendedor' => $c->vendedor?->nome ?? '—',
            'operacao' => $c->operacao->value,
            'parte' => $c->cliente?->razao_social ?? $c->fornecedor?->razao_social ?? '—',
            'rota' => "{$c->origem_uf} → {$c->destino_uf}",
            'veiculo' => $c->tipo_veiculo_codigo,
            'valor_nf' => (float) $c->valor_nf,
            'kg' => $kg,
            't' => $toneladas,
            'repetida' => $c->cotacao_origem_id !== null,
            'produtos' => $c->itens->map(fn ($i) => ['id' => $i->produto_id, 'nome' => $i->produto?->nome ?? '—', 'kg' => (float) $i->quantidade_kg])->values()->all(),
            'fechada' => $fechada && in_array($c->status->value, self::FECHADAS, true),
            'vencedora' => $fechada ? $neg->transportadora_id : null,
            'fechado_vt' => $fechadoVt,
            'fechado_frete' => $fechada ? (float) $neg->valor_frete : null,
            'fechado_em' => $fechada ? $neg->fechado_em : null,
            'respostas' => $proprias,
            'precos' => $efetivos->all(),
            'saving' => $saving,
            'saving_media' => $savingMedia,
            'saving_ref' => $fechada && $min !== null ? $min * $toneladas : 0.0,
            'programacoes' => $c->programacoes->filter(fn ($g) => $g->status->value !== 'CANCELADO')->map(fn ($g) => [
                'id' => $g->id, 'codigo' => $g->codigo, 'status' => $g->status->value, 'tid' => $g->transportadora_id,
                'total' => (float) $g->valor_total, 'prevista' => $g->data_entrega_prevista, 'realizada' => $g->data_entrega_realizada,
            ])->values()->all(),
        ];
    }

    public static function dia(mixed $d): ?string
    {
        if ($d === null || $d === '') {
            return null;
        }

        return $d instanceof CarbonImmutable || $d instanceof \Carbon\Carbon ? $d->format('Y-m-d') : substr((string) $d, 0, 10);
    }

    /** Dias de atraso (positivo = atrasou); null se faltar data. */
    public static function atraso(array $g): ?int
    {
        $prev = self::dia($g['prevista']);
        $real = self::dia($g['realizada']);
        if (! $prev || ! $real) {
            return null;
        }

        return (int) CarbonImmutable::parse($prev)->diffInDays(CarbonImmutable::parse($real), false);
    }

    public static function media(iterable $v, int $casas = 2): float
    {
        $c = collect($v)->filter(fn ($x) => $x !== null);

        return $c->isEmpty() ? 0.0 : round((float) $c->avg(), $casas);
    }

    public static function pct(float|int $parte, float|int $todo): float
    {
        return $todo > 0 ? round($parte * 100 / $todo, 2) : 0.0;
    }
}
