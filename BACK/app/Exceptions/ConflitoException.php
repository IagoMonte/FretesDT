<?php

namespace App\Exceptions;

use RuntimeException;

/** Lock otimista (versao divergente): HTTP 409. */
class ConflitoException extends RuntimeException
{
    public function __construct(string $message = 'Registro alterado por outro usuário. Recarregue e tente novamente.')
    {
        parent::__construct($message);
    }
}
