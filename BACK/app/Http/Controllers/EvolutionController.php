<?php

namespace App\Http\Controllers;

use App\Services\Notificacao\Whatsapp\EvolutionWhatsappGateway;
use App\Services\Notificacao\Whatsapp\WhatsappFalhaDefinitiva;
use App\Services\Notificacao\Whatsapp\WhatsappFalhaTemporaria;
use App\Support\Telefone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Segunda via do WhatsApp (Evolution API): estado da instância, QR para parear e envio de teste. Só vale com WHATSAPP_DRIVER=evolution. */
class EvolutionController extends Controller
{
    public function __construct(private EvolutionWhatsappGateway $evolution) {}

    public function status(): JsonResponse
    {
        if ($fora = $this->foraDoDriver()) {
            return $fora;
        }
        $r = $this->evolution->chamar('get', '/instance/connectionState/'.config('whatsapp.evolution.instance'));
        if (! $r->successful()) {
            return $this->falha($r);
        }

        return response()->json(['data' => ['instancia' => config('whatsapp.evolution.instance'), 'estado' => $r->json('instance.state') ?? 'desconhecido']]);
    }

    public function qr(): JsonResponse
    {
        if ($fora = $this->foraDoDriver()) {
            return $fora;
        }
        $r = $this->evolution->chamar('get', '/instance/connect/'.config('whatsapp.evolution.instance'));
        if (! $r->successful()) {
            return $this->falha($r);
        }

        return response()->json(['data' => ['qr' => $r->json('base64'), 'codigo_pareamento' => $r->json('pairingCode'), 'estado' => $r->json('instance.state')]]);
    }

    public function teste(Request $request): JsonResponse
    {
        if ($fora = $this->foraDoDriver()) {
            return $fora;
        }
        $d = $request->validate(['destino' => ['required', 'string', 'max:200']]);
        $numero = Telefone::e164($d['destino']);
        if (! $numero) {
            throw ValidationException::withMessages(['destino' => ['Informe um telefone com DDD, por exemplo (19) 99999-0001.']]);
        }
        try {
            $id = $this->evolution->enviar($numero, 'carga_status', ['TESTE', 'Teste de notificação', now()->format('d/m/Y'), config('notificacoes.front_url')]);
        } catch (WhatsappFalhaDefinitiva|WhatsappFalhaTemporaria $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json(['data' => ['id_externo' => $id, 'destino' => $numero]]);
    }

    private function foraDoDriver(): ?JsonResponse
    {
        return config('whatsapp.driver') === 'evolution' ? null
            : response()->json(['message' => 'O driver de WhatsApp ativo é '.config('whatsapp.driver').'. Defina WHATSAPP_DRIVER=evolution para usar a Evolution.'], 409);
    }

    private function falha($r): JsonResponse
    {
        return response()->json(['message' => $this->evolution->erro($r)], 502);
    }
}
