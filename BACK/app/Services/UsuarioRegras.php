<?php

namespace App\Services;

use App\Exceptions\AcessoNegadoException;
use App\Models\Grupo;
use App\Models\Transportadora;
use App\Models\Usuario;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Regras de usuário compartilhadas entre o CRUD do admin e o auto-cadastro. */
class UsuarioRegras
{
    /** Login: letras minúsculas, números, ponto, hífen, sublinhado, @ e + (sem espaços nem outros símbolos). */
    public const LOGIN_REGEX = '/^[a-z0-9._@+-]+$/';

    /** Unicidade de login sem diferenciar caixa, independente da collation do banco. */
    public static function loginUnico(?string $ignorar = null): Closure
    {
        return function (string $atributo, mixed $valor, Closure $falhar) use ($ignorar) {
            if (! is_string($valor)) {
                return;
            }
            $existe = Usuario::whereRaw('LOWER(login) = ?', [AuthService::normalizarLogin((string) $valor)])
                ->when($ignorar, fn ($q) => $q->where('id', '<>', $ignorar))->exists();
            if ($existe) {
                $falhar('Login indisponível.');
            }
        };
    }

    /**
     * Todos os grupos têm o mesmo tipo; TRANSPORTADORA exige transportadora_id, INTERNO exige nulo.
     *
     * @param  array<int, string>  $codigos
     * @return Collection<int, Grupo>
     */
    public function resolverGrupos(array $codigos, ?string $transportadoraId): Collection
    {
        $grupos = Grupo::whereIn('codigo', $codigos)->where('ativo', true)->get();
        if ($grupos->count() !== count(array_unique($codigos))) {
            throw ValidationException::withMessages(['grupos' => ['Há grupos inexistentes ou inativos.']]);
        }
        $tipos = $grupos->pluck('tipo_usuario')->unique();
        if ($tipos->count() > 1) {
            throw ValidationException::withMessages(['grupos' => ['Não é possível misturar grupos internos e de transportadora.']]);
        }
        if ($tipos->first() === 'TRANSPORTADORA') {
            if (! $transportadoraId || ! Transportadora::where('id', $transportadoraId)->where('ativo', true)->exists()) {
                throw ValidationException::withMessages(['transportadora_id' => ['Informe uma transportadora ativa para usuários de transportadora.']]);
            }
        } elseif ($transportadoraId) {
            throw ValidationException::withMessages(['transportadora_id' => ['Usuários internos não podem ter transportadora.']]);
        }

        return $grupos;
    }

    /**
     * O ator só atribui (ou mexe em quem tem) grupo cujas permissões ele mesmo possui; ADMINISTRADOR só por administrador.
     * Evita que "gerenciar usuários" vire administrador total.
     *
     * @param  Collection<int, Grupo>  $grupos
     */
    public function exigirAutoridade(Usuario $ator, Collection $grupos): void
    {
        $permissoes = app(PermissaoService::class);
        $souAdmin = $ator->grupos()->where('grupo.ativo', true)->where('grupo.codigo', 'ADMINISTRADOR')->exists();
        if (($grupos->contains('codigo', 'ADMINISTRADOR') && ! $souAdmin)
            || ! $permissoes->contem($permissoes->efetivas($ator), $permissoes->dosGrupos($grupos->pluck('id')->all()))) {
            throw new AcessoNegadoException('Você só pode atribuir grupos cujas permissões você mesmo possui.', 'SEM_AUTORIDADE');
        }
    }}
