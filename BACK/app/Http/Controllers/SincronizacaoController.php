<?php

namespace App\Http\Controllers;

use App\Services\Erp\SincronizacaoPendencias;
use App\Services\Erp\SincronizaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SincronizacaoController extends Controller
{
    public function __construct(private SincronizaService $sync) {}

    /** Situação de cada entidade: ligada, intervalo, última execução, resumo e se está rodando agora. */
    public function index(): JsonResponse
    {
        $linhas = DB::table('sincronizacao')->whereIn('entidade', array_keys($this->sync->fontes()))->orderBy('entidade')->get()
            ->map(fn ($s) => [
                'entidade' => $s->entidade,
                'ativo' => (bool) $s->ativo,
                'intervalo_minutos' => (int) $s->intervalo_minutos,
                'executando' => $s->executando_desde !== null,
                'executando_origem' => $s->executando_origem,
                'ultima_execucao_em' => $s->ultima_execucao_em,
                'ultima_execucao_status' => $s->ultima_execucao_status,
                'ultimo_sucesso_em' => $s->ultimo_sucesso_em,
                'resumo' => $s->ultima_execucao_resumo,
            ]);

        return response()->json(['data' => $linhas, 'meta' => ['total' => $linhas->count()]]);
    }

    /** Entidade inteira: responde 202 e executa depois da resposta (a leitura completa leva dezenas de segundos). */
    public function executar(Request $request, string $entidade): JsonResponse
    {
        $fonte = $this->sync->fonte($entidade) ?? abort(404);
        $completa = $request->boolean('completa');
        $entidade = $fonte->entidade();

        if (! $this->sync->reservar($entidade, 'SOB_DEMANDA', $this->usuario()->id)) {
            return response()->json(['message' => 'Sincronização em andamento.'], 409);
        }
        dispatch(fn () => $this->sync->processar($entidade, $completa))->afterResponse();

        return response()->json(['data' => ['entidade' => $entidade, 'situacao' => 'EM_EXECUCAO']], 202);
    }

    /** Um registro só (código do ERP ou CNPJ), na hora: o vendedor precisa de um cliente que acabou de ser cadastrado no ERP. */
    public function registro(Request $request, string $entidade): JsonResponse
    {
        $fonte = $this->sync->fonte($entidade) ?? abort(404);
        $d = $request->validate([
            'codigo' => ['required_without:cnpj', 'prohibits:cnpj', 'nullable', 'string', 'max:50'],
            'cnpj' => ['required_without:codigo', 'nullable', 'string', 'max:20'],
        ]);
        $filtro = isset($d['codigo']) ? ['codigo' => $d['codigo']] : ['cnpj' => preg_replace('/\D+/', '', $d['cnpj'])];
        if (isset($filtro['cnpj']) && ($filtro['cnpj'] === '' || $fonte->entidade() === 'PRODUTO' || $fonte->entidade() === 'EMBALAGEM')) {
            return response()->json(['message' => 'CNPJ não se aplica a esta entidade.'], 422);
        }

        $r = $this->sync->executar($fonte->entidade(), 'SOB_DEMANDA', $this->usuario()->id, false, $filtro);

        return $r === null
            ? response()->json(['message' => 'Sincronização em andamento.'], 409)
            : response()->json(['data' => $r]);
    }

    public function pendencias(SincronizacaoPendencias $pendencias): JsonResponse
    {
        return response()->json(['data' => $pendencias->todas()]);
    }
}
