<?php

namespace App\Services\Erp\Fontes;

use App\Support\Normaliza;

class TransportadoraFonte extends ParteFonte
{
    public function entidade(): string
    {
        return 'TRANSPORTADORA';
    }

    public function rota(): string
    {
        return 'transportadoras';
    }

    public function tabela(): string
    {
        return 'transportadora';
    }


    public function cnpjUnico(): bool
    {
        return true;
    }

    protected function especificos(array $cru): array
    {
        return ['email' => Normaliza::email($cru['email'] ?? null), 'telefone' => Normaliza::fone($cru['telefone'] ?? null)];
    }
}
