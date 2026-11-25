<?php

namespace App\Http\Controllers;

use App\Exceptions\RegraNegocioException;
use App\Http\Resources\UsuarioResource;
use App\Models\Usuario;
use App\Services\AuthService;
use App\Services\LogService;
use App\Services\UsuarioRegras;
use App\Support\Telefone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UsuarioController extends Controller
{
    public function __construct(private LogService $log, private AuthService $auth, private UsuarioRegras $regras) {}

    public function index(Request $request)
    {
        $q = Usuario::with('grupos')->orderBy('nome');
        match ($request->query('tipo_usuario')) {
            'INTERNO' => $q->whereNull('transportadora_id'),
            'TRANSPORTADORA' => $q->whereNotNull('transportadora_id'),
            default => null,
        };
        if ($t = $request->query('transportadora_id')) {
            $q->where('transportadora_id', $t);
        }

        return $this->lista(UsuarioResource::class, $q->get());
    }

    public function show(string $id): JsonResponse
    {
        return $this->um(UsuarioResource::make(Usuario::with('grupos')->findOrFail($id)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->normalizarLogin($request);
        $d = $request->validate([
            'nome' => ['required', 'string', 'max:150'],
            'login' => ['required', 'string', 'min:3', 'max:200', 'regex:'.UsuarioRegras::LOGIN_REGEX, UsuarioRegras::loginUnico()],
            'email' => ['nullable', 'email', 'max:200', 'unique:usuario,email'],
            'telefone' => ['nullable', 'string', 'max:20', fn ($a, $v, $fail) => $v && ! Telefone::e164($v) ? $fail('Informe um telefone com DDD, por exemplo (19) 99999-0001.') : null],
            'senha' => ['required', 'string', 'min:8', 'max:100'],
            'grupos' => ['required', 'array', 'min:1'],
            'grupos.*' => ['string'],
            'transportadora_id' => ['nullable', 'string'],
        ]);
        $grupos = $this->regras->resolverGrupos($d['grupos'], $d['transportadora_id'] ?? null);
        $this->regras->exigirAutoridade($this->usuario(), $grupos);

        $u = DB::transaction(function () use ($d, $grupos) {
            $u = Usuario::create([
                'nome' => $d['nome'],
                'login' => $d['login'],
                'email' => $d['email'] ?? null,
                'telefone' => self::soDigitos($d['telefone'] ?? null),
                'senha_hash' => Hash::make($d['senha']),
                'transportadora_id' => $d['transportadora_id'] ?? null,
                'situacao' => 'ATIVO',
                'liberado_em' => now(),
                'liberado_por' => auth()->id(),
                'token_versao' => 0,
            ]);
            $u->grupos()->sync($grupos->pluck('id')->all());
            $this->log->operacao('USUARIO', $u, 'USUARIO_CRIADO', null, 'ATIVO', ['login' => $u->login, 'grupos' => $grupos->pluck('codigo')]);

            return $u;
        });

        return $this->um(UsuarioResource::make($u->load('grupos')), [], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $u = Usuario::with('grupos')->findOrFail($id);
        $this->regras->exigirAutoridade($this->usuario(), $u->grupos);
        $this->normalizarLogin($request);
        $d = $request->validate([
            'nome' => ['sometimes', 'string', 'max:150'],
            'login' => ['sometimes', 'string', 'min:3', 'max:200', 'regex:'.UsuarioRegras::LOGIN_REGEX, UsuarioRegras::loginUnico($u->id)],
            'email' => ['nullable', 'email', 'max:200', Rule::unique('usuario', 'email')->ignore($u->id)],
            'telefone' => ['nullable', 'string', 'max:20', fn ($a, $v, $fail) => $v && ! Telefone::e164($v) ? $fail('Informe um telefone com DDD, por exemplo (19) 99999-0001.') : null],
            'senha' => ['nullable', 'string', 'min:8', 'max:100'],
            'grupos' => ['sometimes', 'array', 'min:1'],
            'grupos.*' => ['string'],
            'transportadora_id' => ['nullable', 'string'],
            'versao' => ['required', 'integer'],
        ]);

        DB::transaction(function () use ($u, $d, $request) {
            $u->exigirVersao($d['versao']);
            $u->fill(Arr::only($d, ['nome', 'login', 'email']));
            if (array_key_exists('telefone', $d)) {
                $u->telefone = self::soDigitos($d['telefone']);
            }
            $evento = 'USUARIO_EDITADO';

            $transp = array_key_exists('transportadora_id', $d) ? $d['transportadora_id'] : $u->transportadora_id;
            if (isset($d['grupos']) || array_key_exists('transportadora_id', $d)) {
                $codigos = $d['grupos'] ?? $u->grupos->pluck('codigo')->all();
                $grupos = $this->regras->resolverGrupos($codigos, $transp);
                $this->regras->exigirAutoridade($this->usuario(), $grupos);
                $this->protegerUltimoAdmin($u, $grupos->pluck('codigo')->all());
                $u->transportadora_id = $transp;
                $u->grupos()->sync($grupos->pluck('id')->all());
                $u->token_versao++;
                $evento = 'USUARIO_GRUPOS_ALTERADOS';
            }
            if (! empty($d['senha'])) {
                $u->senha_hash = Hash::make($d['senha']);
                $u->token_versao++;
                $this->auth->revogarUsuario($u->id, 'ADMIN');
                $evento = 'USUARIO_SENHA_REDEFINIDA';
            }
            $u->tocar();
            $this->log->operacao('USUARIO', $u, $evento, null, null, ['login' => $u->login]);
        });

        return $this->um(UsuarioResource::make($u->refresh()->load('grupos')));
    }

    private static function soDigitos(?string $telefone): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $telefone);

        return $d === '' ? null : $d;
    }

    /** Login sempre gravado em minúsculas e sem espaços nas pontas. */
    private function normalizarLogin(Request $request): void
    {
        if (is_string($request->input('login'))) {
            $request->merge(['login' => AuthService::normalizarLogin($request->input('login'))]);
        }
    }

    public function bloquear(string $id): JsonResponse
    {
        $u = DB::transaction(function () use ($id) {
            $u = Usuario::with('grupos')->findOrFail($id);
            $this->regras->exigirAutoridade($this->usuario(), $u->grupos);
            $this->protegerUltimoAdmin($u, []);
            $de = $u->situacao;
            $u->situacao = 'BLOQUEADO';
            $u->token_versao++;
            $u->save();
            $this->auth->revogarUsuario($u->id, 'ADMIN');
            $this->log->operacao('USUARIO', $u, 'USUARIO_BLOQUEADO', $de, 'BLOQUEADO', ['motivo' => 'bloqueio pelo administrador']);

            return $u;
        });

        return $this->um(UsuarioResource::make($u->refresh()->load('grupos')));
    }

    public function ativar(string $id): JsonResponse
    {
        $u = DB::transaction(function () use ($id) {
            $u = Usuario::with('grupos')->findOrFail($id);
            $this->regras->exigirAutoridade($this->usuario(), $u->grupos);
            if ($u->grupos->isEmpty()) {
                throw new RegraNegocioException('O usuário precisa de pelo menos um grupo para ser ativado.');
            }
            $de = $u->situacao;
            $u->fill(['situacao' => 'ATIVO', 'liberado_em' => now(), 'liberado_por' => auth()->id()]);
            $u->save();
            $this->log->operacao('USUARIO', $u, 'USUARIO_LIBERADO', $de, 'ATIVO', ['grupos' => $u->grupos->pluck('codigo')]);

            return $u;
        });

        return $this->um(UsuarioResource::make($u->refresh()->load('grupos')));
    }

    /** Não deixa o sistema sem nenhum administrador ativo. */
    private function protegerUltimoAdmin(Usuario $u, array $novosCodigos): void
    {
        $ehAdmin = $u->ativo() && $u->grupos->contains('codigo', 'ADMINISTRADOR');
        if (! $ehAdmin || in_array('ADMINISTRADOR', $novosCodigos, true)) {
            return;
        }
        $outros = Usuario::where('situacao', 'ATIVO')->where('id', '<>', $u->id)
            ->whereHas('grupos', fn ($g) => $g->where('codigo', 'ADMINISTRADOR'))->count();
        if ($outros === 0) {
            throw new RegraNegocioException('Não é possível remover ou bloquear o último administrador ativo.');
        }
    }
}