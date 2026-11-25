<?php

namespace App\Services;

use App\Enums\Operacao;
use App\Models\Cliente;
use App\Models\Embalagem;
use App\Models\Fornecedor;
use App\Models\Produto;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Validações compartilhadas por cotação e programação: parte por operação e itens produto/embalagem. */
class ItensService
{
    /** Resolve {tipo,id} da API para o model, exigindo o tipo certo da operação (CARREGAMENTO=CLIENTE). */
    public function parte(Operacao $operacao, array $parte): Cliente|Fornecedor
    {
        if (($parte['tipo'] ?? null) !== $operacao->tipoParte()) {
            throw ValidationException::withMessages([
                'parte.tipo' => ["A operação {$operacao->value} exige uma parte do tipo {$operacao->tipoParte()}."],
            ]);
        }
        $modelo = $operacao === Operacao::Carregamento ? Cliente::find($parte['id']) : Fornecedor::find($parte['id']);
        if (! $modelo || ! $modelo->ativo) {
            throw ValidationException::withMessages(['parte.id' => ['Cliente/fornecedor não encontrado ou inativo.']]);
        }

        return $modelo;
    }

    /** @return array{cliente_id: ?string, fornecedor_id: ?string} */
    public function colunasParte(Operacao $operacao, Cliente|Fornecedor $parte): array
    {
        return $operacao === Operacao::Carregamento
            ? ['cliente_id' => $parte->id, 'fornecedor_id' => null]
            : ['cliente_id' => null, 'fornecedor_id' => $parte->id];
    }

    /**
     * Valida produto ativo e embalagem permitida para o produto.
     *
     * @param  array<int, array>  $itens
     * @return array<int, array>
     */
    public function itens(array $itens): array
    {
        $saida = [];
        $temGranel = false;
        foreach (array_values($itens) as $i => $item) {
            $produto = Produto::find($item['produto_id']);
            if (! $produto || ! $produto->ativo) {
                throw ValidationException::withMessages(["itens.{$i}.produto_id" => ['Produto não encontrado ou inativo.']]);
            }
            $codigo = $item['embalagem_codigo'];
            $embalagem = Embalagem::find($codigo);
            $permitida = DB::table('produto_embalagem')->where('produto_id', $produto->id)->where('embalagem_codigo', $codigo)->exists();
            if (! $embalagem || ! $embalagem->ativo || ! $permitida) {
                throw ValidationException::withMessages(["itens.{$i}.embalagem_codigo" => ["A embalagem {$codigo} não é permitida para o produto {$produto->nome}."]]);
            }
            $volumes = $item['volumes'] ?? null;
            $temGranel = $temGranel || $embalagem->granel;
            if ($embalagem->granel) {
                if ($volumes !== null && (int) $volumes > 0) {
                    throw ValidationException::withMessages(["itens.{$i}.volumes" => ["Embalagem a granel ({$codigo}) não tem volumes."]]);
                }
                $volumes = null;
            }
            $paletes = null;
            if ($embalagem->fracionada) {
                $porPalete = DB::table('produto_embalagem')->where('produto_id', $produto->id)->where('embalagem_codigo', $codigo)->value('kg_por_palete');
                if ($porPalete !== null && (float) $porPalete > 0) {
                    $paletes = (int) ceil((float) $item['quantidade_kg'] / (float) $porPalete);
                }
            }
            foreach (['paletizado' => 'permite_paletizar', 'remontado' => 'permite_remontar'] as $campo => $permite) {
                if (! empty($item[$campo]) && ! $embalagem->{$permite}) {
                    throw ValidationException::withMessages(["itens.{$i}.{$campo}" => ["A embalagem {$codigo} não permite essa opção."]]);
                }
            }
            $saida[] = [
                'sequencia' => $i + 1,
                'produto_id' => $produto->id,
                'embalagem_codigo' => $codigo,
                'quantidade_kg' => $item['quantidade_kg'],
                'paletes' => $paletes,
                'volumes' => $volumes,
                'paletizado' => (bool) ($item['paletizado'] ?? false),
                'remontado' => (bool) ($item['remontado'] ?? false),
                'cotacao_item_id' => $item['cotacao_item_id'] ?? null,
            ];
        }
        if ($temGranel && count($saida) > 1) {
            throw ValidationException::withMessages(['itens' => ['Carga a granel aceita um produto só por cotação.']]);
        }

        return $saida;
    }

    /** Cotação fracionada = algum item com embalagem fracionada (que não seja granel). */
    public function fracionada(array $itens): bool
    {
        return Embalagem::whereIn('codigo', array_column($itens, 'embalagem_codigo'))->where('fracionada', true)->where('granel', false)->exists();
    }
}