<?php

namespace App\Services;

use App\Exceptions\AcessoNegadoException;
use App\Models\RefreshToken;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Login local, refresh rotativo com família e revogação em caso de reuso. */
class AuthService
{
    public function __construct(private JwtService $jwt, private LogService $log) {}

    /** Login não diferencia maiúsculas/minúsculas nem espaços nas pontas. */
    public static function normalizarLogin(string $login): string
    {
        return mb_strtolower(trim($login));
    }

    /** @return array<string, mixed> */
    public function login(string $login, string $senha, Request $request): array
    {
        $usuario = Usuario::where('login', self::normalizarLogin($login))->first();

        if (! $usuario || $usuario->senha_hash === null || ! Hash::check($senha, $usuario->senha_hash)) {
            $this->log->sistema('WARNING', 'AUTENTICACAO', 'LOGIN_FALHA', 'Usuário ou senha inválidos.', [
                'usuario_login' => $login,
                'usuario_id' => $usuario?->id,
            ]);
            throw new HttpException(401, 'Usuário ou senha inválidos.');
        }
        $this->exigirAtivo($usuario);

        $this->revogarUsuario($usuario->id, 'NOVO_LOGIN');
        $usuario->forceFill(['ultimo_acesso_em' => now()])->saveQuietly();
        $this->log->acesso($usuario->id, $request->ip());
        $this->log->sistema('INFO', 'AUTENTICACAO', 'LOGIN', null, ['usuario_id' => $usuario->id, 'usuario_login' => $login]);

        return $this->emitir($usuario, (string) Str::uuid(), $request);
    }

    /** @return array<string, mixed> */
    public function refresh(string $token, Request $request): array
    {
        $registro = RefreshToken::where('token_hash', $this->hash($token))->first();
        if (! $registro) {
            throw new HttpException(401, 'Sessão inválida. Faça login novamente.');
        }
        if ($registro->revogado_em && $registro->motivo_revogacao === 'NOVO_LOGIN') {
            throw new HttpException(401, 'Sua sessão foi encerrada porque este usuário entrou em outro lugar.');
        }
        $naJanela = $registro->usado_em && ! $registro->revogado_em
            && $registro->usado_em->diffInSeconds(now(), true) <= (int) config('frete.jwt.refresh_graca_segundos');
        if (($registro->usado_em && ! $naJanela) || $registro->revogado_em) {
            $this->revogarFamilia($registro->familia_id, 'REUSO_DETECTADO');
            $this->log->sistema('WARNING', 'AUTENTICACAO', 'REFRESH_REUSO', 'Reuso de refresh token detectado.', ['usuario_id' => $registro->usuario_id]);
            throw new HttpException(401, 'Sessão inválida. Faça login novamente.');
        }
        if ($registro->expira_em->isPast()) {
            throw new HttpException(401, 'Sessão expirada. Faça login novamente.');
        }
        $usuario = Usuario::find($registro->usuario_id);
        if (! $usuario || ! $usuario->ativo()) {
            $this->revogarFamilia($registro->familia_id, 'TOKEN_VERSAO');
            throw new HttpException(401, 'Sessão inválida. Faça login novamente.');
        }

        if (! $registro->usado_em) {
            $registro->forceFill(['usado_em' => now()])->save();
        }

        return $this->emitir($usuario, $registro->familia_id, $request);
    }

    /** Encerra a sessão: pelo refresh token e/ou pela família do access token usado na chamada (outras sessões do usuário seguem). */
    public function logout(?string $token, ?string $familiaDoAccess = null): void
    {
        $registro = $token ? RefreshToken::where('token_hash', $this->hash($token))->first() : null;
        if ($registro) {
            $this->revogarFamilia($registro->familia_id, 'LOGOUT');
        }
        if ($familiaDoAccess && $familiaDoAccess !== $registro?->familia_id) {
            $this->revogarFamilia($familiaDoAccess, 'LOGOUT');
        }
    }

    /** Access tokens da família revogada deixam de valer na hora (não esperam a validade natural). */
    public static function familiaRevogada(string $familiaId): bool
    {
        return Cache::has("jwt-familia-revogada:{$familiaId}");
    }

    /** Encerra todas as sessões do usuário: refresh tokens revogados e access tokens das famílias derrubados na hora. */
    public function revogarUsuario(string $usuarioId, string $motivo): void
    {
        $familias = RefreshToken::where('usuario_id', $usuarioId)->whereNull('revogado_em')->distinct()->pluck('familia_id');
        foreach ($familias as $familia) {
            $this->revogarFamilia($familia, $motivo);
        }
    }

    private function exigirAtivo(Usuario $usuario): void
    {
        match ($usuario->situacao) {
            'ATIVO' => null,
            'PENDENTE' => throw new AcessoNegadoException('Seu cadastro está em análise.', 'CADASTRO_PENDENTE'),
            'RECUSADO' => throw new AcessoNegadoException(
                'Cadastro recusado: '.($usuario->motivo_recusa ?: 'sem motivo informado.'), 'CADASTRO_RECUSADO'),
            default => throw new AcessoNegadoException('Seu acesso está bloqueado. Procure o gestor.', 'ACESSO_BLOQUEADO'),
        };
    }

    private function revogarFamilia(string $familiaId, string $motivo): void
    {
        Cache::put("jwt-familia-revogada:{$familiaId}", true, now()->addSeconds((int) config('frete.jwt.access_segundos') + 60));
        RefreshToken::where('familia_id', $familiaId)->whereNull('revogado_em')
            ->update(['revogado_em' => now()->format('Y-m-d H:i:s.v'), 'motivo_revogacao' => $motivo]);
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @return array<string, mixed> */
    private function emitir(Usuario $usuario, string $familiaId, Request $request): array
    {
        $opaco = bin2hex(random_bytes(32));
        RefreshToken::create([
            'usuario_id' => $usuario->id,
            'familia_id' => $familiaId,
            'token_hash' => $this->hash($opaco),
            'emitido_em' => now(),
            'expira_em' => now()->addDays(config('frete.jwt.refresh_dias')),
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);

        return [
            'access_token' => $this->jwt->emitir($usuario, $familiaId),
            'token_type' => 'Bearer',
            'expires_in' => (int) config('frete.jwt.access_segundos'),
            'refresh_token' => $opaco,
            'usuario' => $usuario,
        ];
    }
}