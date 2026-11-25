<?php

use App\Exceptions\AcessoNegadoException;
use App\Exceptions\ConflitoException;
use App\Exceptions\RegraNegocioException;
use App\Http\Middleware\AdminOnly;
use App\Http\Middleware\Correlacao;
use App\Http\Middleware\FiltrosEscalares;
use App\Http\Middleware\PodeAlgum;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [Correlacao::class]);
        $middleware->api(append: [FiltrosEscalares::class]);
        $middleware->web(prepend: [Correlacao::class]);
        $middleware->alias(['pode.algum' => PodeAlgum::class, 'admin' => AdminOnly::class]);
        $middleware->redirectGuestsTo(fn (Request $r) => $r->routeIs('admin.*') ? route('admin.login') : null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $api = fn (Request $r) => $r->is('api/*') || $r->expectsJson();
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $api($request));

        $exceptions->render(function (ValidationException $e, Request $r) use ($api) {
            return $api($r) ? response()->json(['message' => $e->validator->errors()->first() ?: 'Dados inválidos.', 'errors' => $e->errors()], 422) : null;
        });
        $exceptions->render(fn (RegraNegocioException $e, Request $r) => $api($r) ? response()->json(
            array_filter(['message' => $e->getMessage(), 'errors' => $e->erros ?: null]), 422) : null);
        $exceptions->render(fn (AcessoNegadoException $e, Request $r) => $api($r) ? response()->json(
            ['message' => $e->getMessage(), 'code' => $e->codigo], 403) : null);
        $exceptions->render(fn (ConflitoException $e, Request $r) => $api($r) ? response()->json(['message' => $e->getMessage()], 409) : null);
        $exceptions->render(fn (AuthenticationException $e, Request $r) => $api($r) ? response()->json(['message' => 'Não autenticado ou sessão expirada.'], 401) : null);
        $exceptions->render(fn (AuthorizationException $e, Request $r) => $api($r) ? response()->json(['message' => 'Você não tem permissão para esta ação.'], 403) : null);
        $exceptions->render(fn (ModelNotFoundException $e, Request $r) => $api($r) ? response()->json(['message' => 'Registro não encontrado.'], 404) : null);
        $exceptions->render(fn (NotFoundHttpException $e, Request $r) => $api($r) ? response()->json(['message' => 'Registro não encontrado.'], 404) : null);
        $exceptions->render(fn (ThrottleRequestsException $e, Request $r) => $api($r) ? response()->json(
            ['message' => 'Muitas tentativas. Aguarde um instante e tente novamente.'], 429, $e->getHeaders()) : null);
        $exceptions->render(fn (UniqueConstraintViolationException $e, Request $r) => $api($r) ? response()->json(
            ['message' => 'Já existe um registro com estes dados.'], 422) : null);
        $exceptions->render(function (\Throwable $e, Request $r) use ($api) {
            if (! $api($r) || config('app.debug') || $e instanceof HttpExceptionInterface) {
                return null;
            }

            return response()->json(['message' => 'Erro interno. Tente novamente em instantes.'], 500);
        });
        $exceptions->render(function (HttpExceptionInterface $e, Request $r) use ($api) {
            if (! $api($r)) {
                return null;
            }
            $msg = match ($e->getStatusCode()) {
                403 => 'Você não tem permissão para esta ação.',
                404 => 'Registro não encontrado.',
                405 => 'Método não permitido.',
                default => $e->getMessage() ?: 'Erro na requisição.',
            };

            return response()->json(['message' => $msg], $e->getStatusCode(), $e->getHeaders());
        });
    })->create();