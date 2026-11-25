<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Valor em reais que, arredondado para 2 casas, ainda é pelo menos R$ 0,01 (0,004 viraria frete zero). */
class CentavoMinimo implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_numeric($value) && round((float) $value, 2) < 0.01) {
            $fail('O valor mínimo é R$ 0,01.');
        }
    }
}
