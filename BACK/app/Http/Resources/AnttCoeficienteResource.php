<?php

namespace App\Http\Resources;

use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnttCoeficienteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tipo_carga_codigo' => $this->tipo_carga_codigo,
            'eixos' => $this->eixos,
            'ccd' => $this->ccd,
            'cc' => $this->cc,
            'vigencia_inicio' => Fmt::data($this->vigencia_inicio),
            'vigencia_fim' => Fmt::data($this->vigencia_fim),
            'ativo' => (bool) $this->ativo,
            'versao' => $this->versao,
        ];
    }
}
