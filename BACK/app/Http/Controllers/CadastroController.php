<?php

namespace App\Http\Controllers;

use App\Exceptions\ConflitoException;
use App\Http\Resources\CadastroResource;
use App\Models\Transportadora;
use App\Models\Usuario;
use App\Services\AuthService;
use App\Services\LogService;
use App\Services\UsuarioRegras;
use App\Support\Fmt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Auto-cadastro público (`cadastrar`) e fila de aprovação do administrador. */
class CadastroController extends Controller
{
    private const SITUACOES = ['PENDENTE', 'ATIVO', 'RECUSADO', 'BLOQUEADO'];

    public function __construct(private LogService $log, private UsuarioRegras $regras) {}

    public function cadastrar(Request $request): JsonResponse
    {
        $login = $request->input('login');
        $email = $request->input('email');
        $request->merge([
            'login' => is_string($login) || $login === null ? AuthService::normalizarLogin((string) $login) : $login,
            'email' => is_string($email) || $email === null ? mb_strtolower(trim((string) $email)) : $email,
            'cnpj' => Fmt::cnpj($request->input('cnpj')),
        ]);
        $transp = fn () => $request->input('tipo') === 'TRANSPORTADORA';

        $d = $request->validate([
            'nome' => ['required', 'string', 'max:150'],
            'login' => ['required', 'string', 'min:3', 'max:200', 'regex:'.UsuarioRegras::LOGIN_REGEX, UsuarioRegras::loginUnico()],
            'email' => ['required', 'email', 'max:200', Rule::unique('usuario', 'email')],
            'senha' => ['required', 'string', 'min:8', 'max:100', 'confirmed'],
            'tipo' => ['required', Rule::in(['VENDEDOR', 'TRANSPORTADORA'])],
            'razao_social' => [Rule::requiredIf($transp), 'nullable', 'string', 'max:200'],
            'nome_fantasia' => ['nullable', 'string', 'max:150'],
            'cnpj' => [Rule::requiredIf($transp), 'nullable', function (string $a, mixed $v, $falhar) use ($transp) {
                if (! Fmt::cnpjValido($v)) {
                    $falhar('CNPJ inválido.');
                } elseif ($transp() && Usuario::where('situacao', 'PENDENTE')->where('cad_cnpj', Fmt::cnpj($v))->exists()) {
                    $falhar('Já existe um cadastro pendente com este CNPJ.');
                }
            }],
        ], [
            'login.regex' => 'Use apenas letras, números, ponto, hífen, sublinhado ou @ no login.',
            'email.unique' => 'E-mail já cadastrado.',
            'senha.confirmed' => 'A confirmação da senha não confere.',
            'senha.min' => 'A senha deve ter pelo menos 8 caracteres.',
        ]);
        $ehTransp = $d['tipo'] === 'TRANSPORTADORA';

        $u = DB::transaction(function () use ($d, $ehTransp) {
            $u = Usuario::create([
                'nome' => trim($d['nome']),
                'login' => $d['login'],
                'email' => $d['email'],
                'senha_hash' => Hash::make($d['senha']),
                'situacao' => 'PENDENTE',
                'tipo_solicitado' => $d['tipo'],
                'cad_razao_social' => $ehTransp ? trim($d['razao_social']) : null,
                'cad_nome_fantasia' => $ehTransp ? (trim((string) ($d['nome_fantasia'] ?? '')) ?: null) : null,
                'cad_cnpj' => $ehTransp ? $d['cnpj'] : null,
                'token_versao' => 0,
            ]);
            $this->log->operacao('USUARIO', $u, 'CADASTRO_SOLICITADO', null, 'PENDENTE', ['login' => $u->login, 'tipo' => $d['tipo']]);

            return $u;
        });

        return response()->json([
            'message' => 'Cadastro enviado. Aguarde a aprovação do administrador.',
            'data' => ['id' => $u->id, 'situacao' => 'PENDENTE'],
        ], 201);
    }

    public function index(Request $request)
    {
        $status = $this->csv($request->query('status')) ?: ['PENDENTE'];
        if (array_diff($status, self::SITUACOES)) {
            throw ValidationException::withMessages(['status' => ['Situação inválida.']]);
        }

        $q = Usuario::with(['decisor', 'transportadora', 'grupos'])
            ->whereNotNull('tipo_solicitado')->whereIn('situacao', $status);
        $status === ['PENDENTE'] ? $q->orderBy('criado_em') : $q->orderByDesc('decidido_em')->orderByDesc('criado_em');
        $itens = $q->get();

        $cnpjs = $itens->where('situacao', 'PENDENTE')->pluck('cad_cnpj')->filter()->unique()->all();
        $existentes = $cnpjs ? Transportadora::whereIn('cnpj', $cnpjs)->where('ativo', true)->get()->keyBy('cnpj') : collect();
        foreach ($itens as $u) {
            $u->setRelation('sugerida', $existentes->get($u->cad_cnpj));
        }

        return $this->lista(CadastroResource::class, $itens);
    }

    public function aprovar(Request $request, string $id): JsonResponse
    {
        $d = $request->validate([
            'grupo' => ['required', Rule::in(['VENDEDOR', 'TRANSPORTADORA'])],
            'transportadora_id' => ['nullable', 'string'],
            'criar_transportadora' => ['nullable', 'boolean'],
        ]);
        $u = $this->pendente($id);

        DB::transaction(function () use (&$u, $d) {
            $u = $this->pendente($u->id, true);
            $transportadoraId = null;
            if ($d['grupo'] === 'TRANSPORTADORA') {
                $transportadoraId = $d['transportadora_id'] ?? null;
                if (! $transportadoraId && ! empty($d['criar_transportadora'])) {
                    $transportadoraId = $this->criarTransportadora($u)->id;
                }
            } elseif (! empty($d['transportadora_id']) || ! empty($d['criar_transportadora'])) {
                throw ValidationException::withMessages(['transportadora_id' => ['Usuários internos não podem ter transportadora.']]);
            }
            $grupos = $this->regras->resolverGrupos([$d['grupo']], $transportadoraId);

            $u->forceFill([
                'situacao' => 'ATIVO',
                'transportadora_id' => $transportadoraId,
                'liberado_em' => now(),
                'liberado_por' => auth()->id(),
                'decidido_em' => now(),
                'decidido_por' => auth()->id(),
                'motivo_recusa' => null,
                'token_versao' => $u->token_versao + 1,
            ])->save();
            $u->grupos()->sync($grupos->pluck('id')->all());
            $this->log->operacao('USUARIO', $u, 'CADASTRO_APROVADO', 'PENDENTE', 'ATIVO', [
                'login' => $u->login, 'grupo' => $d['grupo'], 'transportadora_id' => $transportadoraId,
            ]);
        });

        return $this->um(CadastroResource::make($u->refresh()->load(['decisor', 'transportadora', 'grupos'])));
    }

    public function recusar(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'min:3', 'max:500']]);
        $u = $this->pendente($id);

        DB::transaction(function () use (&$u, $d) {
            $u = $this->pendente($u->id, true);
            $u->forceFill([
                'situacao' => 'RECUSADO',
                'motivo_recusa' => trim($d['motivo']),
                'decidido_em' => now(),
                'decidido_por' => auth()->id(),
            ])->save();
            $this->log->operacao('USUARIO', $u, 'CADASTRO_RECUSADO', 'PENDENTE', 'RECUSADO', ['login' => $u->login, 'motivo' => $u->motivo_recusa]);
        });

        return $this->um(CadastroResource::make($u->refresh()->load(['decisor', 'transportadora', 'grupos'])));
    }

    /** Cadastro (auto-solicitado) ainda em análise; 404 se não existe, 409 se alguém já decidiu. */
    private function pendente(string $id, bool $travar = false): Usuario
    {
        $q = Usuario::whereNotNull('tipo_solicitado');
        $u = ($travar ? $q->lockForUpdate() : $q)->findOrFail($id);
        if ($u->situacao !== 'PENDENTE') {
            throw new ConflitoException('Este cadastro já foi decidido.');
        }

        return $u;
    }

    private function criarTransportadora(Usuario $u): Transportadora
    {
        if (! $u->cad_cnpj) {
            throw ValidationException::withMessages(['transportadora_id' => ['O cadastro não trouxe dados de transportadora; vincule a uma existente.']]);
        }
        if (Transportadora::where('cnpj', $u->cad_cnpj)->exists()) {
            throw ValidationException::withMessages(['transportadora_id' => ['Já existe uma transportadora com este CNPJ. Vincule a ela.']]);
        }
        $t = Transportadora::create([
            'razao_social' => $u->cad_razao_social,
            'nome_fantasia' => $u->cad_nome_fantasia ?: $u->cad_razao_social,
            'cnpj' => $u->cad_cnpj,
            'email' => $u->email,
            'ativo' => true,
        ]);
        $this->log->operacao('TRANSPORTADORA', $t, 'CADASTRO_CRIADO', null, null, ['nome' => $t->nome_fantasia, 'origem' => 'CADASTRO_USUARIO']);

        return $t;
    }
}
