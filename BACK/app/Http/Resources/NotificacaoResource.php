<?php

namespace App\Http\Resources;

use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Notificacao */
class NotificacaoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'evento' => $this->evento,
            'entidade' => $this->entidade,
            'registro_id' => $this->registro_id,
            'registro_codigo' => $this->registro_codigo,
            'canal' => $this->canal,
            'grupo_codigo' => $this->grupo_codigo,
            'destino_nome' => $this->destino_nome,
            'endereco' => $this->endereco,
            'template' => $this->template,
            'status' => $this->status,
            'tentativas' => $this->tentativas,
            'erro' => $this->erro,
            'enviado_em' => Fmt::iso($this->enviado_em),
            'criado_em' => Fmt::iso($this->criado_em),
        ];
    }
}
