<?php

namespace App\Http\Controllers;

use App\Http\Resources\MeResource;
use App\Services\AuthService;
use App\Services\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function __construct(private AuthService $auth, private JwtService $jwt) {}

    public function login(Request $request): JsonResponse
    {
        $d = $request->validate(['login' => ['required', 'string'], 'senha' => ['required', 'string']]);

        return $this->tokens($this->auth->login($d['login'], $d['senha'], $request), $request);
    }

    public function refresh(Request $request): JsonResponse
    {
        $d = $request->validate(['refresh_token' => ['required', 'string']]);

        return $this->tokens($this->auth->refresh($d['refresh_token'], $request), $request);
    }

    public function logout(Request $request): Response
    {
        $d = $request->validate(['refresh_token' => ['nullable', 'string']]);
        $this->auth->logout($d['refresh_token'] ?? null, $this->jwt->validar((string) $request->bearerToken())['fam'] ?? null);

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        return $this->um(MeResource::make($request->user()));
    }

    private function tokens(array $t, Request $request): JsonResponse
    {
        $t['usuario'] = MeResource::make($t['usuario'])->resolve($request);

        return response()->json($t);
    }
}