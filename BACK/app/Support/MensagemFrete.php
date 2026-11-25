<?php

namespace App\Support;

use App\Models\Cotacao;
use App\Models\Embalagem;
use App\Models\Produto;
use App\Models\Programacao;
use App\Models\TipoVeiculo;
use Carbon\Carbon;

/**
 * Textos de valor e de pedido que vão no payload das notificações (e-mail e WhatsApp). Montados aqui, uma vez, para os dois canais
 * dizerem a mesma coisa: valor cheio (frete total) por padrão e R$/t só em cotação/carga 100% granel; produto sempre pela descrição.
 */
class MensagemFrete
{
    /** "R$ 2.100,00" (frete total) ou, se todos os itens são granel, "R$ 175,00 por tonelada". */
    public static function valor(Cotacao $cotacao, mixed $valorTonelada, mixed $valorFrete): string
    {
        $cotacao->loadMissing('itens');

        return self::formatar(
            self::granel($cotacao->itens->pluck('embalagem_codigo')),
            (string) $cotacao->itens->sum('quantidade_kg'),
            $valorTonelada,
            $valorFrete,
        );
    }

    /** Mesma regra para a carga: valor cheio = `valor_total`; granel = R$/t. */
    public static function valorDaCarga(Programacao $programacao): string
    {
        $programacao->loadMissing('itens');

        return self::formatar(
            self::granel($programacao->itens->pluck('embalagem_codigo')),
            (string) $programacao->itens->sum('quantidade_kg'),
            $programacao->valor_tonelada,
            $programacao->valor_total,
        );
    }

    /** Todos os itens com embalagem granel (granel é item único, mas a regra vale para a lista toda). */
    public static function granel(iterable $codigosEmbalagem): bool
    {
        $codigos = collect($codigosEmbalagem)->unique()->values();

        return $codigos->isNotEmpty() && Embalagem::whereIn('codigo', $codigos)->where('granel', true)->count() === $codigos->count();
    }

    /**
     * Produto como a transportadora o vê, em TODA notificação para qualquer destinatário: a descrição (o nome só quando não há descrição),
     * seguida de ONU e risco rotulados: "Líquido alcalino: ONU:1824 - RISCO:80".
     */
    public static function produto(Produto $produto): string
    {
        $codigos = array_filter([
            $produto->onu ? "ONU:{$produto->onu}" : null,
            $produto->risco ? "RISCO:{$produto->risco}" : null,
        ]);

        return $produto->nomeParaTransportadora().($codigos ? ': '.implode(' - ', $codigos) : '');
    }

    /**
     * Campos do pedido (template programar_pedido): cliente, modalidade, carga, rota e datas.
     *
     * @return array{cliente: string, modalidade: string, carga: string, rota: string, datas: string}
     */
    public static function pedido(Programacao $programacao): array
    {
        $programacao->loadMissing(['itens.produto', 'cliente', 'fornecedor']);
        $parte = $programacao->parte();
        $cnpj = $parte?->cnpj ? Fmt::cnpjMascara($parte->cnpj) : null;

        $veiculo = $programacao->tipo_veiculo_codigo ? (TipoVeiculo::find($programacao->tipo_veiculo_codigo)?->descricao ?: $programacao->tipo_veiculo_codigo) : 'Carga';
        $produtos = $programacao->itens->pluck('produto')->filter()->unique('id')->map(fn (Produto $p) => self::produto($p))->implode(' e ');

        return [
            'cliente' => $parte ? $parte->razao_social.($cnpj ? ", CNPJ {$cnpj}" : '') : '-',
            'modalidade' => (string) ($programacao->modalidade_frete ?: '-'),
            'carga' => $veiculo.($produtos !== '' ? " de {$produtos}" : ''),
            'rota' => "{$programacao->origem_cidade}/{$programacao->origem_uf} x {$programacao->destino_cidade}/{$programacao->destino_uf}",
            'datas' => self::data($programacao->data_coleta_prevista).' e '.self::data($programacao->data_entrega_prevista),
        ];
    }

    public static function data(mixed $d): string
    {
        return $d ? Carbon::parse($d)->format('d/m/Y') : 'a combinar';
    }

    private static function formatar(bool $granel, string $kg, mixed $valorTonelada, mixed $valorFrete): string
    {
        $temTonelada = $valorTonelada !== null && $valorTonelada !== '';
        $temFrete = $valorFrete !== null && $valorFrete !== '';

        if ($granel) {
            $porTonelada = $temTonelada ? $valorTonelada : ($temFrete ? Dinheiro::tonelada($valorFrete, $kg) : null);
            if ($porTonelada !== null) {
                return 'R$ '.Fmt::dinheiroWhatsapp($porTonelada).' por tonelada';
            }
        }
        $frete = $temFrete ? $valorFrete : ($temTonelada ? Dinheiro::frete($valorTonelada, $kg) : null);

        return $frete === null ? '-' : 'R$ '.Fmt::dinheiroWhatsapp($frete);
    }
}
