<?php

namespace App\Http\Controllers;

use App\Http\Requests\PainelRequest;
use App\Services\Erp\SincronizacaoPendencias;
use App\Services\Notificacao\NotificacaoService;
use App\Services\PainelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class PainelController extends Controller
{
    public function __invoke(PainelRequest $request, PainelService $painel, SincronizacaoPendencias $pendencias, NotificacaoService $notificacoes): JsonResponse
    {
        $dados = $painel->resumo($this->usuario(), $request->validated());
        if (Gate::allows('SINCRONIZACAO.ver')) {
            $dados['pendencias_erp'] = $pendencias->todas();
        }

        if (Gate::allows('NOTIFICACAO.ver')) {
            $dados['notificacoes'] = $notificacoes->resumo();
        }

        return response()->json(['data' => $dados]);
    }
}
