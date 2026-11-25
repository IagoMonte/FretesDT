<?php

namespace App\Services\Notificacao\Whatsapp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Evolution API (Baileys, WhatsApp Web não oficial): POST {url}/message/sendText/{instância} com texto livre, header apikey. */
class EvolutionWhatsappGateway implements WhatsappGateway
{
    public function enviar(string $para, string $template, array $params): string
    {
        $r = $this->chamar('post', '/message/sendText/'.config('whatsapp.evolution.instance'), [
            'number' => $para,
            'text' => TextoTemplate::renderizar($template, $params),
        ]);

        if ($r->successful()) {
            return (string) ($r->json('key.id') ?? '');
        }
        $msg = $this->erro($r);
        if ($r->status() === 429 || $r->serverError()) {
            throw new WhatsappFalhaTemporaria($msg);
        }
        throw new WhatsappFalhaDefinitiva($msg);
    }

    /**
     * O grupo existe e a instância participa dele (findGroupInfos responde 200 só nesse caso).
     *
     * @throws WhatsappFalhaTemporaria sem resposta ou erro 5xx/429 da Evolution
     */
    public function grupoExiste(string $jid): bool
    {
        $r = $this->chamar('get', '/group/findGroupInfos/'.config('whatsapp.evolution.instance').'?groupJid='.urlencode($jid));
        if ($r->serverError() || $r->status() === 429) {
            throw new WhatsappFalhaTemporaria($this->erro($r));
        }

        return $r->successful();
    }

    /** @throws WhatsappFalhaTemporaria sem resposta da Evolution */
    public function chamar(string $metodo, string $caminho, array $corpo = []): Response
    {
        try {
            return $this->http()->{$metodo}(rtrim((string) config('whatsapp.evolution.url'), '/').$caminho, $corpo ?: null);
        } catch (ConnectionException $e) {
            throw new WhatsappFalhaTemporaria('Sem resposta da Evolution: '.$e->getMessage(), 0, $e);
        }
    }

    public function erro(Response $r): string
    {
        $m = $r->json('response.message') ?? $r->json('message') ?? $r->json('error');
        $m = is_array($m) ? json_encode($m, JSON_UNESCAPED_UNICODE) : $m;

        return mb_substr((string) ($m ?: "HTTP {$r->status()}"), 0, 300);
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders(['apikey' => (string) config('whatsapp.evolution.apikey')])
            ->acceptJson()
            ->timeout((int) config('whatsapp.evolution.timeout'))
            ->retry(2, 200, fn ($e) => $e instanceof ConnectionException, throw: false);
    }
}
