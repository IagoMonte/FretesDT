<?php

namespace App\Services\Erp\Fontes;

class ClienteFonte extends ParteFonte
{
    public function entidade(): string
    {
        return 'CLIENTE';
    }

    public function rota(): string
    {
        return 'clientes';
    }

    public function tabela(): string
    {
        return 'cliente';
    }

}
