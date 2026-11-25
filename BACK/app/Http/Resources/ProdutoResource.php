<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProdutoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'descricao' => $this->descricao,
            'onu' => $this->onu,
            'risco' => $this->risco,
            'densidade' => $this->densidade,
            'embalagens' => $this->embalagens->pluck('codigo')->sort()->values(),
            'fatores' => $this->embalagens->filter(fn ($e) => $e->pivot->kg_por_volume !== null)
                ->mapWithKeys(fn ($e) => [$e->codigo => number_format((float) $e->pivot->kg_por_volume, 3, '.', '')])
                ->sortKeys()->all() ?: (object) [],
            'fatores_palete' => $this->embalagens->filter(fn ($e) => $e->pivot->kg_por_palete !== null)
                ->mapWithKeys(fn ($e) => [$e->codigo => number_format((float) $e->pivot->kg_por_palete, 3, '.', '')])
                ->sortKeys()->all() ?: (object) [],
            'codigo_erp' => $this->codigo_erp,
            'codigos_erp' => (int) ($this->codigos_erp_count ?? 0),
            'origem_cadastro' => $this->origem_cadastro,
            'ativo' => (bool) $this->ativo,
            'versao' => $this->versao,
        ];
    }
}
