<?php

namespace App\Auth;

use App\Models\Usuario;
use App\Services\AuthService;
use App\Services\JwtService;
use Illuminate\Http\Request;

/** Resolve o usuário autenticado a partir do Bearer. A claim `tv` invalida tokens antigos na hora. */
class JwtGuardResolver
{
    public function __construct(private JwtService $jwt) {}

    public function usuario(Request $request): ?Usuario
    {
        $bearer = $request->bearerToken();
        if (! $bearer) {
            return null;
        }
        $claims = $this->jwt->validar($bearer);
        if (! $claims) {
            return null;
        }
        if ($claims['fam'] && AuthService::familiaRevogada($claims['fam'])) {
            return null;
        }
        $usuario = Usuario::find($claims['sub']);

        return $usuario && $usuario->ativo() && (int) $usuario->token_versao === $claims['tv'] ? $usuario : null;
    }
}