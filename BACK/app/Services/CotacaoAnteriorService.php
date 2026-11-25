<?php

namespace App\Services;

use App\Enums\StatusCotacao;
use App\Enums\StatusResposta;
use App\Models\Cotacao;
use App\Models\Produto;
use App\Models\Usuario;
use App\Support\Fmt;
use Illuminate\Support\Str;

/**
 * Cotação repetida (granel líquido): acha a cotação anterior mais recente com a mesma rota, veículo e conjunto de
 * produtos (pela descrição) que já tenha resposta. A nova cotação se vincula à original (cotacao_origem_id) e enxerga as respostas ao vivo; nada é copiado.
 */
class CotacaoAnteriorService
{
    public const GRANEL_LIQUIDO = 'GRANEL_LIQUIDO';

    /** Cidade sem acento, maiúscula e sem espaços sobrando; é a chave de comparação. */
    public static function normalizarCidade(string $cidade): string
    {
        return mb_strtoupper(preg_replace('/\s+/', ' ', trim(Str::ascii($cidade))));
    }

    /** Chave de comparação do produto: a descrição (ou o nome, se vazia), sem acento, caixa e espaços sobrando. */
    public static function descricaoChave(Produto $produto): string
    {
        $d = trim((string) $produto->descricao);

        return self::normalizarCidade($d !== '' ? $d : $produto->nome);
    }

    /** Conjunto de descrições distintas, ordenado: duas cotações casam quando a assinatura é igual. */
    public static function assinaturaProdutos(iterable $produtos): array
    {
        $chaves = [];
        foreach ($produtos as $p) {
            $chaves[self::descricaoChave($p)] = true;
        }
        $chaves = array_keys($chaves);
        sort($chaves);

        return $chaves;
    }

    /** Todos os itens (e ao menos um) são granel líquido. */
    public static function granelLiquido(Cotacao $cotacao): bool
    {
        $itens = $cotacao->relationLoaded('itens') ? $cotacao->itens : $cotacao->itens()->get();

        return $itens->isNotEmpty() && $itens->every(fn ($i) => $i->embalagem_codigo === self::GRANEL_LIQUIDO);
    }

    /** Visibilidade: só usuário INTERNO (sem transportadora). */
    public static function pode(?Usuario $usuario): bool
    {
        return $usuario !== null && ! $usuario->ehTransportadora();
    }

    /**
     * Cotação original a ser referenciada: a mais recente que casa rota/veículo, resolvida até a original
     * (nunca vincula a um vínculo) e com ao menos uma resposta RESPONDIDA ainda vigente: dentro da validade
     * informada ou, sem validade, dentro da janela de dias. Os itens da original têm de ter o mesmo conjunto de
     * descrições de produto da nova cotação ($produtoIds).
     */
    public function buscar(string $tipoVeiculo, string $origemCidade, string $origemUf, string $destinoCidade, string $destinoUf, array $produtoIds): ?Cotacao
    {
        $assinatura = self::assinaturaProdutos(Produto::query()->whereIn('id', $produtoIds)->get());
        if ($assinatura === []) {
            return null;
        }

        $oc = self::normalizarCidade($origemCidade);
        $dc = self::normalizarCidade($destinoCidade);
        $hoje = Fmt::hoje();
        $desde = Fmt::hoje(-(int) config('frete.cotacao_repetida_dias'));
        $poda = Fmt::hoje(-((int) config('frete.limites.validade_dias_max') + (int) config('frete.cotacao_repetida_dias')));
        $com = ['itens.produto', 'negociacao.respostas.transportadora', 'negociacao.transportadora', 'negociacao.fechadoPor'];

        $candidatas = Cotacao::query()
            ->where('tipo_veiculo_codigo', $tipoVeiculo)
            ->where('origem_uf', mb_strtoupper($origemUf))
            ->where('destino_uf', mb_strtoupper($destinoUf))
            ->where('status', '<>', StatusCotacao::Cancelada->value)
            ->where('data_solicitacao', '>=', $poda)
            ->with(array_merge($com, ['origem' => fn ($q) => $q->with($com)]))
            ->orderByDesc('data_solicitacao')->orderByDesc('criado_em')->orderByDesc('codigo')
            ->get();

        foreach ($candidatas as $c) {
            if (self::normalizarCidade($c->origem_cidade) !== $oc || self::normalizarCidade($c->destino_cidade) !== $dc || ! self::granelLiquido($c)) {
                continue;
            }
            $original = $c->origem ?? $c;
            if (self::assinaturaProdutos($original->itens->map->produto->filter()) !== $assinatura) {
                continue;
            }
            $dentroDaJanela = Fmt::data($original->data_solicitacao) >= $desde;
            $vigente = $original->negociacao?->respostas->contains(fn ($r) => $r->status === StatusResposta::Respondida
                && ($r->validade ? Fmt::data($r->validade) >= $hoje : $dentroDaJanela));
            if ($original->status === StatusCotacao::Cancelada || ! $vigente) {
                continue;
            }

            return $original;
        }

        return null;
    }
}
