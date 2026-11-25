<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** pode.algum:A,B : libera se o usuário tem qualquer uma das habilidades (listas de apoio, como produtos para a cotação). */
class PodeAlgum
{
    public function handle(Request $request, Closure $next, string ...$habilidades): Response
    {
        abort_unless($request->user()?->podeAlgum(...$habilidades), 403);

        return $next($request);
    }
}