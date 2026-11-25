<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** /admin: precisa estar logado (sessão) e ter ADMIN.ver. Escopo de dados não se aplica: o painel é somente leitura. */
class AdminOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $u = Auth::guard('admin')->user();
        if (! $u) {
            return redirect()->route('admin.login');
        }
        if (! $u->ativo() || ! $u->pode('ADMIN.ver')) {
            abort(403, 'Você não tem permissão para o painel administrativo.');
        }
        Auth::shouldUse('admin');

        return $next($request);
    }
}
