<?php

namespace App\Http\Requests;

use App\Services\CotacaoAnteriorService;
use App\Support\Normaliza;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CotacaoRequest extends FormRequest
{
    public function after(): array
    {
        return [function (Validator $v) {
            $o = $this->input('origem');
            $d = $this->input('destino');
            if (is_array($o) && is_array($d) && is_string($o['cidade'] ?? null) && is_string($d['cidade'] ?? null)
                && is_string($o['uf'] ?? '') && is_string($d['uf'] ?? '')
                && mb_strtoupper($o['uf'] ?? '') === mb_strtoupper($d['uf'] ?? '')
                && CotacaoAnteriorService::normalizarCidade($o['cidade']) === CotacaoAnteriorService::normalizarCidade($d['cidade'])) {
                $v->errors()->add('destino', 'O destino não pode ser igual à origem.');
            }
        }];
    }

    public function rules(): array
    {
        $local = fn (string $campo) => [
            $campo => ['required', 'array'],
            "{$campo}.cidade" => ['required', 'string', 'max:100'],
            "{$campo}.uf" => ['bail', 'required', 'string', 'size:2', fn (string $a, mixed $v, Closure $falhar) => in_array(mb_strtoupper($v), Normaliza::UFS, true) || $falhar('UF inválida.')],
            "{$campo}.ibge" => ['nullable', 'string', 'size:7'],
        ];

        return array_merge([
            'operacao' => ['required', 'in:CARREGAMENTO,RECEBIMENTO'],
            'parte' => ['required', 'array'],
            'parte.tipo' => ['required', 'in:CLIENTE,FORNECEDOR'],
            'parte.id' => ['required', 'string'],
            'tipo_veiculo_codigo' => ['nullable', 'string', 'exists:tipo_veiculo,codigo'],
            'tipo_carga_antt_codigo' => ['nullable', 'string', 'exists:tipo_carga_antt,codigo'],
            'distancia_km' => ['nullable', 'numeric', 'min:0', 'max:'.config('frete.limites.distancia_km_max')],
            'valor_nf' => ['required', 'numeric', 'gt:0', 'max:'.config('frete.limites.valor_nf_max')],
            'observacao' => ['nullable', 'string', 'max:2000'],
            'itens' => ['required', 'array', 'min:1'],
            'itens.*.produto_id' => ['required', 'string'],
            'itens.*.embalagem_codigo' => ['required', 'string'],
            'itens.*.quantidade_kg' => ['required', 'numeric', 'min:0.001', 'max:'.config('frete.limites.quantidade_kg_max')],
            'itens.*.volumes' => ['nullable', 'integer', 'min:0'],
            'itens.*.paletizado' => ['nullable', 'boolean'],
            'itens.*.remontado' => ['nullable', 'boolean'],
            'usar_anterior' => ['nullable', 'boolean'],
            'versao' => [$this->isMethod('PATCH') ? 'required' : 'nullable', 'integer'],
        ], $local('origem'), $local('destino'));
    }
}