<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $efetivas = $this->efetivas();

        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'login' => $this->login,
            'email' => $this->email,
            'tipo_usuario' => $this->transportadora_id ? 'TRANSPORTADORA' : 'INTERNO',
            'transportadora' => TransportadoraResource::resumo($this->transportadora),
            'grupos' => $this->grupos()->orderBy('codigo')->pluck('codigo'),
            'permissoes' => $efetivas['permissoes'],
            'acoes' => $efetivas['acoes'],
        ];
    }
}