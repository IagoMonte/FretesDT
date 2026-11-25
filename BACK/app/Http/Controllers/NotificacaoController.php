<?php

namespace App\Http\Controllers;

use App\Http\Resources\NotificacaoResource;
use App\Models\Notificacao;
use App\Services\Notificacao\NotificacaoService;
use App\Services\Notificacao\PreferenciaService;
use App\Support\Telefone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Configuração (matriz evento x canal x grupo), histórico, reenvio e teste das notificações. Só POST para escrever. */
class NotificacaoController extends Controller
{
    public function __construct(private PreferenciaService $preferencias, private NotificacaoService $notificacoes) {}

    public function preferencias(): JsonResponse
    {
        return response()->json(['data' => $this->preferencias->matriz()]);
    }

    public function salvarPreferencias(Request $request): JsonResponse
    {
        $d = $request->validate([
            'preferencias' => ['required', 'array', 'min:1', 'max:500'],
            'preferencias.*.evento' => ['required', 'string', 'in:'.implode(',', array_keys(config('notificacoes.eventos')))],
            'preferencias.*.canal' => ['required', 'string', 'in:WHATSAPP,EMAIL'],
            'preferencias.*.grupo_codigo' => ['required', 'string', 'in:'.implode(',', config('notificacoes.grupos'))],
            'preferencias.*.ativo' => ['required', 'boolean'],
        ]);
        foreach ($d['preferencias'] as $i => $p) {
            if (! in_array($p['grupo_codigo'], $this->preferencias->gruposPermitidos($p['evento']), true)) {
                throw ValidationException::withMessages(["preferencias.{$i}.grupo_codigo" => ["O grupo {$p['grupo_codigo']} não recebe o evento {$p['evento']}."]]);
            }
            if (! $this->preferencias->canalAdmitido($p['canal'], $p['grupo_codigo'])) {
                throw ValidationException::withMessages(["preferencias.{$i}.canal" => ["O grupo {$p['grupo_codigo']} só recebe por WhatsApp."]]);
            }
        }
        $this->preferencias->salvar($d['preferencias']);

        return response()->json(['data' => $this->preferencias->matriz(), 'meta' => ['alertas' => []]]);
    }

    public function historico(Request $request)
    {
        $q = Notificacao::orderByDesc('criado_em')->orderByDesc('id')->limit(200);
        foreach (['evento', 'status', 'canal'] as $filtro) {
            if ($valor = $request->query($filtro)) {
                $q->where($filtro, $valor);
            }
        }

        return $this->lista(NotificacaoResource::class, $q->get());
    }

    public function reenviar(string $id): JsonResponse
    {
        $n = $this->notificacoes->reenviar(Notificacao::findOrFail($id));

        return $this->um(NotificacaoResource::make($n->refresh()));
    }

    public function teste(Request $request): JsonResponse
    {
        $d = $request->validate(['canal' => ['required', 'in:WHATSAPP,EMAIL'], 'destino' => ['required', 'string', 'max:200']]);
        if ($d['canal'] === 'EMAIL' && ! filter_var($d['destino'], FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['destino' => ['Informe um e-mail válido.']]);
        }
        if ($d['canal'] === 'WHATSAPP' && ! Telefone::e164($d['destino'])) {
            throw ValidationException::withMessages(['destino' => ['Informe um telefone com DDD, por exemplo (19) 99999-0001.']]);
        }
        $n = $this->notificacoes->teste($d['canal'], $d['destino'], $this->usuario()->nome);
        $alertas = $d['canal'] === 'WHATSAPP' && in_array(config('whatsapp.driver'), ['log', 'null'], true)
            ? ['WhatsApp em modo simulado (driver '.config('whatsapp.driver').'): nada foi enviado de verdade.'] : [];

        return $this->um(NotificacaoResource::make($n->refresh()), $alertas);
    }
}
