<?php

namespace App\Rules;

use App\Support\Fmt;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** CNPJ com 14 dígitos e dígitos verificadores corretos (cadastro manual; o ERP segue pela sincronização). */
class CnpjValido implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! Fmt::cnpjValido($value)) {
            $fail('CNPJ inválido.');
        }
    }
}
