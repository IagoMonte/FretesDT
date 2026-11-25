<?php

namespace App\Http\Requests;

use App\Rules\CentavoMinimo;
use App\Support\Fmt;
use Illuminate\Foundation\Http\FormRequest;

class ProgramacaoRequest extends FormRequest
{
    public function rules(): array
    {
        $novo = $this->isMethod('POST');
        $obrigatorio = $novo ? 'required' : 'sometimes';
        $local = fn (string $campo) => [
            $campo => ['nullable', 'array'],
            "{$campo}.cidade" => ["required_with:{$campo}", 'string', 'max:100'],
            "{$campo}.uf" => ["required_with:{$campo}", 'string', 'size:2'],
            "{$campo}.ibge" => ['nullable', 'string', 'size:7'],
        ];
        $data = ['nullable', 'date_format:Y-m-d'];
        $prevista = [...$data, 'after_or_equal:2000-01-01', 'before_or_equal:'.Fmt::tetoData()];
        $realizada = [...$data, 'after_or_equal:2000-01-01', 'before_or_equal:today'];

        return array_merge([
            'operacao' => [$novo ? 'required' : 'sometimes', 'in:CARREGAMENTO,RECEBIMENTO'],
            'parte' => [$novo ? 'required' : 'sometimes', 'array'],
            'parte.tipo' => ['required_with:parte', 'in:CLIENTE,FORNECEDOR'],
            'parte.id' => ['required_with:parte', 'string'],
            'status' => ['nullable', 'in:EM_COTACAO,AGUARDANDO_CARREGAMENTO'],
            'data_pedido' => $data,
            'pedido_venda' => [$novo ? 'required' : 'nullable', 'string', 'max:30'],
            'nfe_numero' => ['nullable', 'string', 'max:20'],
            'nfe_serie' => ['nullable', 'string', 'max:5'],
            'nfe_chave' => ['nullable', 'digits:44'],
            'representante_id' => ['nullable', 'string', 'exists:usuario,id'],
            'deposito' => ['sometimes', 'in:VIRTUAL,FISICO'],
            'modalidade_frete' => ['sometimes', 'in:CIF,FOB'],
            'transportadora_id' => ['nullable', 'string'],
            'tipo_veiculo_codigo' => ['nullable', 'string', 'exists:tipo_veiculo,codigo'],
            'valor_tonelada' => ['nullable', 'numeric', 'min:0', new CentavoMinimo, 'max:'.config('frete.limites.valor_tonelada_max')],
            'prazo_dias' => ['nullable', 'integer', 'min:1', 'max:'.config('frete.limites.prazo_dias_max')],
            'prazo_coleta_dias' => ['nullable', 'integer', 'min:0', 'max:'.config('frete.limites.prazo_coleta_dias_max')],
            'data_coleta_prevista' => $novo ? [...$prevista, 'after_or_equal:today'] : $prevista,
            'data_coleta_realizada' => $realizada,
            'data_entrega_prevista' => [...$prevista, 'after_or_equal:data_coleta_prevista'],
            'data_entrega_realizada' => [...$realizada, 'after_or_equal:data_coleta_realizada'],
            'observacao' => ['nullable', 'string', 'max:2000'],
            'itens' => [$obrigatorio, 'array', 'min:1'],
            'itens.*.produto_id' => ['required', 'string'],
            'itens.*.embalagem_codigo' => ['required', 'string'],
            'itens.*.quantidade_kg' => ['required', 'numeric', 'gt:0', 'max:'.config('frete.limites.quantidade_kg_max')],
            'itens.*.volumes' => ['nullable', 'integer', 'min:0'],
            'itens.*.cotacao_item_id' => ['nullable', 'string'],
            'versao' => [$novo ? 'nullable' : 'required', 'integer'],
        ], $local('origem'), $local('destino'));
    }
}