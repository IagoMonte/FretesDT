<?php

namespace App\Exceptions;

use RuntimeException;

/** Login correto, mas a conta não pode entrar: HTTP 403 com `code` estável para o front. */
class AcessoNegadoException extends RuntimeException
{
    public function __construct(string $message, public readonly string $codigo)
    {
        parent::__construct($message);
    }
}
