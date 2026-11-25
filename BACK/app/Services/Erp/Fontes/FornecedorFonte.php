<?php

namespace App\Services\Erp\Fontes;

class FornecedorFonte extends ClienteFonte
{
    public function entidade(): string
    {
        return 'FORNECEDOR';
    }

    public function rota(): string
    {
        return 'fornecedores';
    }

    public function tabela(): string
    {
        return 'fornecedor';
    }

}
