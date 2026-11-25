<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Gera (ou reaproveita X-Request-Id) o correlacao_id que liga os logs da requisição. */
class Correlacao
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->header('X-Request-Id');
        $id = $id && Str::isUuid($id) ? $id : (string) Str::uuid();
        Context::add('correlacao_id', $id);

        $resposta = $next($request);
        $resposta->headers->set('X-Request-Id', $id);

        return $resposta;
    }
}