<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Filtros do Painel Operacional (query string de GET /api/painel). */
class PainelRequest extends FormRequest
{
    public const STATUS = ['EM_COTACAO', 'AGUARDANDO_CARREGAMENTO', 'EM_TRANSITO', 'ENTREGUE', 'DEVOLVIDO'];

    public function rules(): array
    {
        return [
            'ano' => ['nullable', 'integer', 'between:2000,2100'],
            'mes' => ['nullable', 'integer', 'between:1,12'],
            'produto_id' => ['nullable', 'string', 'max:40'],
            'embalagem' => ['nullable', 'string', 'max:30'],
            'frete' => ['nullable', Rule::in(['CIF', 'FOB'])],
            'transportadora_id' => ['nullable', 'string', 'max:40'],
            'origem' => ['nullable', 'string', 'max:150'],
            'destino' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::in(self::STATUS)],
            'busca' => ['nullable', 'string', 'max:100'],
            'pagina' => ['nullable', 'integer', 'min:1'],
            'por_pagina' => ['nullable', 'integer', 'between:1,500'],
        ];
    }
}
