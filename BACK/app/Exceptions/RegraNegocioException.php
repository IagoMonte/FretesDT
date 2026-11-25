<?php

namespace App\Exceptions;

use RuntimeException;

/** Transição de status inválida ou regra de negócio violada: HTTP 422. */
class RegraNegocioException extends RuntimeException
{
    public function __construct(string $message, public readonly array $erros = [])
    {
        parent::__construct($message);
    }
}
