<?php

namespace App\Http\Resources;

use App\Support\Fmt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Solicitação de cadastro (usuário que se registrou sozinho) vista pelo administrador. */
class CadastroResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'login' => $this->login,
            'email' => $this->email,
            'situacao' => $this->situacao,
            'tipo_solicitado' => $this->tipo_solicitado,
            'transportadora_dados' => $this->tipo_solicitado === 'TRANSPORTADORA' ? [
                'razao_social' => $this->cad_razao_social,
                'nome_fantasia' => $this->cad_nome_fantasia,
                'cnpj' => $this->cad_cnpj,
            ] : null,
            'transportadora_sugerida' => $this->situacao === 'PENDENTE' && $this->relationLoaded('sugerida')
                ? TransportadoraResource::resumo($this->sugerida) : null,
            'transportadora' => $this->situacao === 'ATIVO' ? TransportadoraResource::resumo($this->transportadora) : null,
            'grupos' => $this->situacao === 'ATIVO' ? $this->grupos->pluck('codigo')->sort()->values() : [],
            'motivo_recusa' => $this->motivo_recusa,
            'solicitado_em' => Fmt::iso($this->criado_em),
            'decidido_em' => Fmt::iso($this->decidido_em),
            'decidido_por' => $this->decisor ? ['id' => $this->decisor->id, 'nome' => $this->decisor->nome] : null,
        ];
    }
}
