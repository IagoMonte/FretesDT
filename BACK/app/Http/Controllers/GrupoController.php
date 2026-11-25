<?php

namespace App\Http\Controllers;

use App\Exceptions\AcessoNegadoException;
use App\Exceptions\RegraNegocioException;
use App\Http\Resources\GrupoResource;
use App\Models\Grupo;
use App\Models\Recurso;
use App\Models\Usuario;
use App\Services\LogService;
use App\Services\PermissaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Grupos de permissão: catálogo de recursos/ações, criação, edição e inativação (recurso GRUPO). Grupos de sistema são imutáveis. */
class GrupoController extends Controller
{
    private const TIPOS = ['INTERNO', 'TRANSPORTADORA'];

    /** Usuário de transportadora só enxerga e mexe no que é dele: estes recursos e estas ações, sempre no escopo TRANSPORTADORA. */
    private const RECURSOS_TRANSPORTADORA = ['COTACAO', 'NEGOCIACAO', 'PROGRAMACAO'];

    private const ACOES_TRANSPORTADORA = ['NEGOCIACAO.RESPONDER', 'PROGRAMACAO.ALTERAR_STATUS', 'PROGRAMACAO.CONFIRMAR_ENTREGA'];

    public function __construct(private LogService $log, private PermissaoService $permissoes) {}

    public function catalogo(): JsonResponse
    {
        $acoes = DB::table('recurso_acao')->where('ativo', true)->orderBy('acao_codigo')->get()->groupBy('recurso_codigo');
        $recursos = Recurso::where('ativo', true)->orderBy('modulo')->orderBy('nome')->get()->map(fn ($r) => [
            'codigo' => $r->codigo, 'nome' => $r->nome, 'modulo' => $r->modulo, 'usa_escopo' => (bool) $r->usa_escopo,
            'acoes' => ($acoes[$r->codigo] ?? collect())->map(fn ($a) => ['codigo' => $a->acao_codigo, 'nome' => $a->nome])->values(),
        ])->values();

        return response()->json(['data' => [
            'recursos' => $recursos,
            'tipos_usuario' => self::TIPOS,
            'escopos' => ['INTERNO' => ['PROPRIOS', 'TODOS'], 'TRANSPORTADORA' => ['TRANSPORTADORA']],
            'recursos_transportadora' => self::RECURSOS_TRANSPORTADORA,
            'acoes_transportadora' => self::ACOES_TRANSPORTADORA,
        ], 'meta' => ['alertas' => []]]);
    }

    public function detalhes()
    {
        return $this->lista(GrupoResource::class, Grupo::withCount('usuarios')->orderByDesc('sistema')->orderBy('nome')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'codigo' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9_]{2,29}$/', 'unique:grupo,codigo'],
            'nome' => ['required', 'string', 'min:3', 'max:100'],
            'descricao' => ['nullable', 'string', 'max:500'],
            'tipo_usuario' => ['required', 'in:'.implode(',', self::TIPOS)],
            'permissoes' => ['nullable', 'array'],
            'acoes' => ['nullable', 'array'],
            'acoes.*' => ['string'],
        ], [
            'codigo.regex' => 'Use de 3 a 30 caracteres: letras maiúsculas, números e sublinhado, começando por letra.',
            'codigo.unique' => 'Já existe um grupo com este código.',
        ]);
        [$permissoes, $acoes] = $this->matriz($d['tipo_usuario'], $d['permissoes'] ?? [], $d['acoes'] ?? []);
        $this->exigirNaoExceder($permissoes, $acoes);

        $g = DB::transaction(function () use ($d, $permissoes, $acoes) {
            $g = Grupo::create([
                'codigo' => $d['codigo'], 'nome' => trim($d['nome']), 'descricao' => $d['descricao'] ?? null,
                'tipo_usuario' => $d['tipo_usuario'], 'sistema' => false, 'ativo' => true,
            ]);
            $this->gravarMatriz($g, $permissoes, $acoes);
            $this->log->operacao('GRUPO', $g, 'GRUPO_CRIADO', null, null, ['tipo' => $g->tipo_usuario, 'recursos' => array_keys($permissoes), 'acoes' => $acoes]);

            return $g;
        });

        return $this->um(GrupoResource::make($g->refresh()), [], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $g = Grupo::findOrFail($id);
        $this->exigirEditavel($g);
        $d = $request->validate([
            'nome' => ['sometimes', 'string', 'min:3', 'max:100'],
            'descricao' => ['nullable', 'string', 'max:500'],
            'permissoes' => ['sometimes', 'array'],
            'acoes' => ['sometimes', 'array'],
            'acoes.*' => ['string'],
            'versao' => ['required', 'integer'],
        ]);

        DB::transaction(function () use ($g, $d) {
            $g = Grupo::lockForUpdate()->findOrFail($g->id);
            $g->exigirVersao($d['versao']);
            if (isset($d['nome'])) {
                $g->nome = trim($d['nome']);
            }
            if (array_key_exists('descricao', $d)) {
                $g->descricao = $d['descricao'];
            }
            $mudouMatriz = array_key_exists('permissoes', $d) || array_key_exists('acoes', $d);
            if ($mudouMatriz) {
                $atual = GrupoResource::make($g)->resolve();
                [$permissoes, $acoes] = $this->matriz(
                    $g->tipo_usuario,
                    $d['permissoes'] ?? json_decode(json_encode($atual['permissoes']), true),
                    $d['acoes'] ?? $atual['acoes']->all(),
                );
                $this->exigirNaoExceder($permissoes, $acoes);
                $this->gravarMatriz($g, $permissoes, $acoes);
                Usuario::whereIn('id', DB::table('usuario_grupo')->where('grupo_id', $g->id)->pluck('usuario_id'))->increment('token_versao');
            }
            $g->tocar();
            $this->log->operacao('GRUPO', $g, 'GRUPO_EDITADO', null, null, ['matriz' => $mudouMatriz]);
        });

        return $this->um(GrupoResource::make($g->refresh()));
    }

    public function inativar(string $id): JsonResponse
    {
        $g = Grupo::findOrFail($id);
        $this->exigirEditavel($g);
        $n = DB::table('usuario_grupo')->where('grupo_id', $g->id)->count();
        if ($n > 0) {
            throw new RegraNegocioException("Há {$n} ".($n === 1 ? 'usuário' : 'usuários').' neste grupo. Mude o grupo deles antes de inativar.');
        }

        return $this->alterarAtivo($g, false);
    }

    public function ativar(string $id): JsonResponse
    {
        $g = Grupo::findOrFail($id);
        $this->exigirEditavel($g);

        return $this->alterarAtivo($g, true);
    }

    private function alterarAtivo(Grupo $g, bool $ativo): JsonResponse
    {
        DB::transaction(function () use ($g, $ativo) {
            $g->ativo = $ativo;
            $g->tocar();
            $this->log->operacao('GRUPO', $g, $ativo ? 'GRUPO_ATIVADO' : 'GRUPO_INATIVADO');
        });

        return $this->um(GrupoResource::make($g->refresh()));
    }

    /** Ninguém concede (nem a si, pelo próprio grupo) permissão que não tem. */
    private function exigirNaoExceder(array $permissoes, array $acoes): void
    {
        if (! $this->permissoes->contem($this->permissoes->efetivas($this->usuario()), ['permissoes' => $permissoes, 'acoes' => $acoes])) {
            throw new AcessoNegadoException('Você só pode conceder permissões que você mesmo possui.', 'SEM_AUTORIDADE');
        }
    }

    private function exigirEditavel(Grupo $g): void
    {
        if ($g->sistema) {
            throw new RegraNegocioException('Grupos do sistema não podem ser alterados nem inativados.');
        }
    }

    /**
     * Valida e normaliza a matriz. Interno: escopo PROPRIOS ou TODOS; transportadora: só recursos e ações dela, escopo TRANSPORTADORA.
     *
     * @return array{0: array<string, array>, 1: array<int, string>}
     */
    private function matriz(string $tipo, array $permissoes, array $acoes): array
    {
        $recursos = Recurso::where('ativo', true)->get()->keyBy('codigo');
        $limpas = [];
        foreach ($permissoes as $codigo => $p) {
            $r = $recursos[$codigo] ?? null;
            if (! $r || ! is_array($p)) {
                throw ValidationException::withMessages(['permissoes' => ["O recurso {$codigo} não existe."]]);
            }
            if ($tipo === 'TRANSPORTADORA' && ! in_array($codigo, self::RECURSOS_TRANSPORTADORA, true)) {
                throw ValidationException::withMessages(['permissoes' => ["Grupo de transportadora não pode ter acesso a {$r->nome}."]]);
            }
            $flags = [];
            foreach (['ver', 'inserir', 'alterar', 'cancelar'] as $op) {
                $flags[$op] = filter_var($p[$op] ?? false, FILTER_VALIDATE_BOOLEAN);
            }
            if (! in_array(true, $flags, true)) {
                continue;
            }
            if (($flags['inserir'] || $flags['alterar'] || $flags['cancelar']) && ! $flags['ver']) {
                throw ValidationException::withMessages(['permissoes' => ["Em {$r->nome}, inserir, alterar ou cancelar exigem também ver."]]);
            }
            $padrao = $tipo === 'TRANSPORTADORA' ? 'TRANSPORTADORA' : 'PROPRIOS';
            $escopo = $r->usa_escopo ? ($p['escopo'] ?? $padrao) : 'TODOS';
            if ($r->usa_escopo && ! in_array($escopo, $tipo === 'TRANSPORTADORA' ? ['TRANSPORTADORA'] : ['PROPRIOS', 'TODOS'], true)) {
                throw ValidationException::withMessages(['permissoes' => ["Escopo inválido em {$r->nome} para este tipo de grupo."]]);
            }
            $limpas[$codigo] = $flags + ['escopo' => $escopo];
        }

        $acoesOk = [];
        $existentes = DB::table('recurso_acao')->where('ativo', true)->get()->map(fn ($a) => "{$a->recurso_codigo}.{$a->acao_codigo}")->all();
        foreach (array_unique($acoes) as $a) {
            if (! in_array($a, $existentes, true)) {
                throw ValidationException::withMessages(['acoes' => ["A ação {$a} não existe."]]);
            }
            if ($tipo === 'TRANSPORTADORA' && ! in_array($a, self::ACOES_TRANSPORTADORA, true)) {
                throw ValidationException::withMessages(['acoes' => ["Grupo de transportadora não pode ter a ação {$a}."]]);
            }
            [$recurso] = explode('.', $a, 2);
            if (! ($limpas[$recurso]['ver'] ?? false)) {
                throw ValidationException::withMessages(['acoes' => ["A ação {$a} exige a permissão de ver {$recurso}."]]);
            }
            $acoesOk[] = $a;
        }
        if (! $limpas) {
            throw ValidationException::withMessages(['permissoes' => ['Conceda ao menos uma permissão ao grupo.']]);
        }

        return [$limpas, $acoesOk];
    }

    private function gravarMatriz(Grupo $g, array $permissoes, array $acoes): void
    {
        $agora = now()->format('Y-m-d H:i:s.v');
        DB::table('grupo_permissao')->where('grupo_id', $g->id)->delete();
        DB::table('grupo_acao')->where('grupo_id', $g->id)->delete();
        foreach ($permissoes as $recurso => $p) {
            DB::table('grupo_permissao')->insert([
                'grupo_id' => $g->id, 'recurso_codigo' => $recurso,
                'pode_ver' => $p['ver'], 'pode_inserir' => $p['inserir'], 'pode_alterar' => $p['alterar'], 'pode_cancelar' => $p['cancelar'],
                'escopo' => $p['escopo'], 'criado_em' => $agora, 'atualizado_em' => $agora, 'versao' => 0,
            ]);
        }
        foreach ($acoes as $a) {
            [$recurso, $acao] = explode('.', $a, 2);
            DB::table('grupo_acao')->insert(['grupo_id' => $g->id, 'recurso_codigo' => $recurso, 'acao_codigo' => $acao]);
        }
    }
}
