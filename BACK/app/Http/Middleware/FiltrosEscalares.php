<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Os filtros de GET são sempre texto: `?status[]=x` ou `?termo[a]=x` voltam 422 em vez de estourar nos casts das listagens. */
class FiltrosEscalares
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethod('GET')) {
            foreach ($request->query() as $chave => $valor) {
                if (! is_scalar($valor) && $valor !== null) {
                    throw ValidationException::withMessages([(string) $chave => ['Informe um valor simples (texto), não uma lista.']]);
                }
            }
        }

        return $next($request);
    }
}
