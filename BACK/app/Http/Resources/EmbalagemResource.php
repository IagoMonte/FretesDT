<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmbalagemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'codigo' => $this->codigo,
            'descricao' => $this->descricao,
            'permite_paletizar' => (bool) $this->permite_paletizar,
            'permite_remontar' => (bool) $this->permite_remontar,
            'granel' => (bool) $this->granel,
            'fracionada' => (bool) $this->fracionada,
            'ativo' => (bool) $this->ativo,
            'codigo_erp' => $this->codigo_erp,
            'origem_cadastro' => $this->origem_cadastro,
            'revisao_pendente' => (bool) $this->revisao_pendente,
            'versao' => $this->versao,
        ];
    }
}
