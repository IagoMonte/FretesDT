<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Cliente, fornecedor ou linha da view vw_parte: todos expõem `tipo`. */
class ParteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return self::resumo($this->resource) + [
            'cidade' => $this->cidade,
            'uf' => $this->uf,
            'codigo_erp' => $this->codigo_erp,
            'revisao_pendente' => (bool) $this->revisao_pendente,
            'ativo' => (bool) $this->ativo,
        ];
    }

    /** Versão resumida usada dentro de cotação/programação. */
    public static function resumo($parte): ?array
    {
        if (! $parte) {
            return null;
        }

        return [
            'tipo' => $parte->tipo,
            'id' => $parte->id,
            'razao_social' => $parte->razao_social,
            'nome_fantasia' => $parte->nome_fantasia,
            'cnpj' => $parte->cnpj,
        ];
    }
}
