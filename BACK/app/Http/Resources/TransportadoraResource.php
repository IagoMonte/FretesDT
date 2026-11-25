<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransportadoraResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'razao_social' => $this->razao_social,
            'nome_fantasia' => $this->nome_fantasia,
            'cnpj' => $this->cnpj,
            'revisao_pendente' => (bool) $this->revisao_pendente,
            'email' => $this->email,
            'telefone' => $this->telefone,
            'whatsapp_grupo_id' => $this->whatsapp_grupo_id,
            'cidade' => $this->cidade,
            'uf' => $this->uf,
            'codigo_erp' => $this->codigo_erp,
            'origem_cadastro' => $this->origem_cadastro,
            'ativo' => (bool) $this->ativo,
            'versao' => $this->versao,
        ];
    }

    public static function resumo($t): ?array
    {
        return $t ? ['id' => $t->id, 'nome_fantasia' => $t->nome_fantasia] : null;
    }
}
