<?php

namespace App\Http\Resources;

use App\Enums\StatusProgramacao;
use App\Enums\StatusResposta;
use App\Support\Dinheiro;
use App\Support\Fmt;
use App\Support\Rota;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProgramacaoResource extends JsonResource
{
    public const RELACOES = ['cotacao.negociacao.respostas', 'representante', 'cliente', 'fornecedor', 'transportadora', 'itens.produto'];

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'codigo' => $this->codigo,
            'status' => $this->status->value,
            'operacao' => $this->operacao->value,
            'cotacao' => $this->cotacao ? ['id' => $this->cotacao->id, 'codigo' => $this->cotacao->codigo] : null,
            'data_pedido' => Fmt::data($this->data_pedido),
            'pedido_venda' => $this->pedido_venda,
            'nfe_numero' => $this->nfe_numero,
            'nfe_serie' => $this->nfe_serie,
            'nfe_chave' => $this->nfe_chave,
            'representante' => $this->representante ? ['id' => $this->representante->id, 'nome' => $this->representante->nome] : null,
            'parte' => ParteResource::resumo($this->parte()),
            'deposito' => $this->deposito,
            'modalidade_frete' => $this->modalidade_frete,
            'transportadora' => TransportadoraResource::resumo($this->transportadora),
            'tipo_veiculo_codigo' => $this->tipo_veiculo_codigo,
            'origem' => Rota::resumo($this, 'origem'),
            'destino' => Rota::resumo($this, 'destino'),
            'valor_tonelada' => $this->valor_tonelada,
            'valor_total' => $this->valor_total,
            'valor_proposto' => $this->valorProposto(),
            'prazo_dias' => $this->prazo_dias,
            'prazo_coleta_dias' => $this->prazo_coleta_dias,
            'data_coleta_prevista' => Fmt::data($this->data_coleta_prevista),
            'data_coleta_realizada' => Fmt::data($this->data_coleta_realizada),
            'data_entrega_prevista' => Fmt::data($this->data_entrega_prevista),
            'data_entrega_realizada' => Fmt::data($this->data_entrega_realizada),
            'entregue_em' => Fmt::iso($this->entregue_em),
            'entregue_no_prazo' => $this->entregueNoPrazo(),
            'observacao' => $this->observacao,
            'itens' => $this->itens->map(fn ($i) => [
                'id' => $i->id,
                'sequencia' => $i->sequencia,
                'cotacao_item_id' => $i->cotacao_item_id,
                'produto' => $i->produto->resumo($request->user()->ehTransportadora()),
                'embalagem_codigo' => $i->embalagem_codigo,
                'quantidade_kg' => $i->quantidade_kg,
                'volumes' => $i->volumes,
            ])->values(),
            'quantidade_total_kg' => Dinheiro::soma($this->itens->pluck('quantidade_kg')),
            'versao' => $this->versao,
        ];
    }

    /** R$/t que a transportadora da carga propôs na cotação, quando o fechamento saiu com outro valor (senão null). */
    private function valorProposto(): ?string
    {
        $resposta = $this->cotacao?->negociacao?->respostas
            ->first(fn ($r) => $r->transportadora_id === $this->transportadora_id && $r->status === StatusResposta::Respondida);
        if (! $resposta || $resposta->valor_tonelada === null || $this->valor_tonelada === null) {
            return null;
        }

        return Dinheiro::dec($resposta->valor_tonelada, 2) === Dinheiro::dec($this->valor_tonelada, 2) ? null : Dinheiro::dec($resposta->valor_tonelada, 2);
    }

    private function entregueNoPrazo(): ?bool
    {
        if ($this->status !== StatusProgramacao::Entregue || ! $this->data_entrega_prevista || ! $this->data_entrega_realizada) {
            return null;
        }

        return Fmt::data($this->data_entrega_realizada) <= Fmt::data($this->data_entrega_prevista);
    }
}