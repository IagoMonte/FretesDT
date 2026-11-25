<?php

namespace App\Http\Resources;

use App\Enums\StatusResposta;
use App\Services\AnttService;
use App\Services\CotacaoAnteriorService;
use App\Support\Dinheiro;
use App\Support\Fmt;
use App\Support\Rota;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CotacaoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $usuario = $request->user();

        return [
            'id' => $this->id,
            'codigo' => $this->codigo,
            'data_solicitacao' => Fmt::data($this->data_solicitacao),
            'status' => $usuario->ehTransportadora() ? $this->resource->statusParaTransportadora((string) $usuario->transportadora_id) : $this->status->value,
            'operacao' => $this->operacao->value,
            'vendedor' => $this->vendedor ? ['id' => $this->vendedor->id, 'nome' => $this->vendedor->nome] : null,
            'parte' => ParteResource::resumo($this->parte()),
            'tipo_veiculo_codigo' => $this->tipo_veiculo_codigo,
            'fracionada' => (bool) $this->fracionada,
            'origem' => Rota::resumo($this, 'origem'),
            'destino' => Rota::resumo($this, 'destino'),
            'valor_nf' => $this->valor_nf,
            'tipo_carga_antt_codigo' => $this->tipo_carga_antt_codigo,
            'distancia_km' => $this->distancia_km,
            'observacao' => $this->observacao,
            'itens' => $this->itens->map(fn ($i) => [
                'id' => $i->id,
                'sequencia' => $i->sequencia,
                'produto' => $i->produto->resumo($usuario->ehTransportadora()),
                'embalagem_codigo' => $i->embalagem_codigo,
                'quantidade_kg' => $i->quantidade_kg,
                'paletes' => $i->paletes,
                'volumes' => $i->volumes,
                'paletizado' => (bool) $i->paletizado,
                'remontado' => (bool) $i->remontado,
            ])->values(),
            'quantidade_total_kg' => Dinheiro::soma($this->itens->pluck('quantidade_kg')),
            'negociacao' => $this->negociacao ? self::negociacao($this->negociacao, $usuario, $this->resource) : null,
            'programacoes' => $this->programacoesVisiveis($usuario)->map(fn ($p) => [
                'id' => $p->id, 'codigo' => $p->codigo, 'status' => $p->status->value,
            ])->values(),
            'cotacao_origem' => $this->cotacaoOrigem($usuario),
            'versao' => $this->versao,
        ];
    }

    /** Cotação original referenciada: só aparece para usuário INTERNO. */
    private function cotacaoOrigem($usuario): ?array
    {
        $origem = $this->resource->origem;
        if (! $origem || ! CotacaoAnteriorService::pode($usuario)) {
            return null;
        }

        return [
            'id' => $origem->id,
            'codigo' => $origem->codigo,
            'data_solicitacao' => Fmt::data($origem->data_solicitacao),
            'status' => $origem->status->value,
        ];
    }

    /** Prévia (GET /cotacoes/anterior): a cotação original com as respostas RESPONDIDA. */
    public static function anterior($cotacao, $usuario): array
    {
        return [
            'id' => $cotacao->id,
            'codigo' => $cotacao->codigo,
            'data_solicitacao' => Fmt::data($cotacao->data_solicitacao),
            'status' => $cotacao->status->value,
            'respostas' => $cotacao->negociacao->respostas->where('status', StatusResposta::Respondida)
                ->map(fn ($r) => self::resposta($r, 'PROPRIA', $cotacao->codigo))->values(),
        ];
    }

    private function programacoesVisiveis($usuario)
    {
        $restrito = $usuario && $usuario->escopo('PROGRAMACAO') === 'TRANSPORTADORA';

        return $restrito
            ? $this->programacoes->where('transportadora_id', $usuario->transportadora_id)
            : $this->programacoes;
    }

    /**
     * Esconde a concorrência: a transportadora só vê a própria resposta, e o fechamento
     * só aparece se ela for a vencedora.
     */
    public static function negociacao($negociacao, $usuario, $cotacao = null): array
    {
        $restrito = $usuario && $usuario->escopo('NEGOCIACAO') === 'TRANSPORTADORA';
        $minha = $restrito ? $usuario->transportadora_id : null;
        $cotacao ??= $negociacao->cotacao;

        $proprias = $negociacao->respostas;
        $referenciadas = collect();
        $origemValida = $cotacao?->origemValida();
        if ($origemValida?->negociacao) {
            $referenciadas = $origemValida->negociacao->respostas;
        }
        if ($restrito) {
            $proprias = $proprias->where('transportadora_id', $minha);
            $referenciadas = $referenciadas->where('transportadora_id', $minha);
        }
        $kg = Dinheiro::soma($cotacao?->itens->pluck('quantidade_kg') ?? []);

        $codigo = fn ($c) => $restrito ? null : $c;
        $respostas = $proprias->map(fn ($r) => self::resposta($r, 'PROPRIA', $codigo($cotacao?->codigo), null, $usuario))
            ->concat($referenciadas->map(fn ($r) => self::resposta(
                $r, $restrito ? 'PROPRIA' : 'REFERENCIADA', $codigo($origemValida->codigo),
                $r->valor_tonelada === null ? null : ($cotacao?->fracionada ? $r->valor_frete : Dinheiro::frete($r->valor_tonelada, $kg)), $usuario,
            )));

        $fechamento = (! $restrito || $negociacao->transportadora_id === $minha) ? self::fechamento($negociacao, $usuario, $cotacao) : null;

        return [
            'id' => $negociacao->id,
            'status' => $negociacao->status->value,
            'respostas' => $respostas->values(),
            'fechamento' => $fechamento,
        ];
    }

    public static function fechamento($negociacao, $usuario = null, $cotacao = null): ?array
    {
        if ($negociacao->status->value !== 'FECHADA') {
            return null;
        }
        $cotacao ??= $negociacao->cotacao;

        return [
            'piso_valido' => $cotacao->pisoValido((bool) $negociacao->dedicado),
            'transportadora' => TransportadoraResource::resumo($negociacao->transportadora),
            'valor_tonelada' => $negociacao->valor_tonelada,
            'valor_frete' => $negociacao->valor_frete,
            'tipo_veiculo_codigo' => $negociacao->tipo_veiculo_codigo,
            'prazo_dias' => $negociacao->prazo_dias,
            'prazo_coleta_dias' => $negociacao->prazo_coleta_dias,
            'observacao' => $negociacao->observacao_fechamento,
            'eixos' => $negociacao->eixos,
            'tipo_carga_antt_codigo' => $negociacao->tipo_carga_antt_codigo,
            'antt' => $usuario && $usuario->pode('ANTT.ver') ? AnttService::bloco($negociacao, $negociacao->valor_frete) : null,
            'fechado_em' => Fmt::iso($negociacao->fechado_em),
            'fechado_por' => $negociacao->fechadoPor ? ['id' => $negociacao->fechadoPor->id, 'nome' => $negociacao->fechadoPor->nome] : null,
        ];
    }

    public static function resposta($r, string $origem = 'PROPRIA', ?string $cotacaoCodigo = null, ?string $valorFrete = null, $usuario = null): array
    {
        $dedicado = $usuario && $usuario->escopo('NEGOCIACAO') === 'TRANSPORTADORA' ? ['dedicado' => (bool) $r->dedicado] : [];

        return $dedicado + [
            'piso_valido' => $r->negociacao->cotacao->pisoValido((bool) $r->dedicado),
            'id' => $r->id,
            'origem' => $origem,
            'cotacao_codigo' => $cotacaoCodigo,
            'transportadora' => TransportadoraResource::resumo($r->transportadora),
            'status' => $r->status->value,
            'encaminhado_em' => Fmt::iso($r->encaminhado_em),
            'valor_tonelada' => $r->valor_tonelada,
            'valor_frete' => $valorFrete ?? $r->valor_frete,
            'tipo_veiculo_codigo' => $r->tipo_veiculo_codigo,
            'eixos' => $r->eixos,
            'tipo_carga_antt_codigo' => $r->tipo_carga_antt_codigo,
            'antt' => $usuario && $usuario->pode('ANTT.ver') && $origem !== 'REFERENCIADA' ? AnttService::bloco($r, $r->valor_frete) : null,
            'prazo_dias' => $r->prazo_dias,
            'prazo_coleta_dias' => $r->prazo_coleta_dias,
            'validade' => Fmt::data($r->validade),
            'observacao' => $r->observacao,
            'respondido_em' => Fmt::iso($r->respondido_em),
            'versao' => $r->versao,
        ];
    }
}
