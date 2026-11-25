<?php

namespace App\Enums;

enum Operacao: string
{
    case Carregamento = 'CARREGAMENTO';
    case Recebimento = 'RECEBIMENTO';

    /** Tipo de parte exigido pela operação (CARREGAMENTO = cliente, RECEBIMENTO = fornecedor). */
    public function tipoParte(): string
    {
        return $this === self::Carregamento ? 'CLIENTE' : 'FORNECEDOR';
    }
}