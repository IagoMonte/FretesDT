<?php

namespace App\Services\Notificacao\Whatsapp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** WhatsApp Business Cloud API (Meta): POST {graph}/v22.0/{PHONE_NUMBER_ID}/messages com template aprovado. */
class CloudWhatsappGateway implements WhatsappGateway
{
    private const GRAPH = 'https://graph.facebook.com/v22.0';

    public function enviar(string $para, string $template, array $params): string
    {
        if (str_ends_with($para, '@g.us')) {
            throw new WhatsappFalhaDefinitiva('Grupo de WhatsApp só é suportado pelo driver Evolution.');
        }
        $corpo = [
            'messaging_product' => 'whatsapp',
            'to' => $para,
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => config('whatsapp.template_lang')],
                'components' => [[
                    'type' => 'body',
                    'parameters' => array_map(fn ($p) => ['type' => 'text', 'text' => (string) $p], array_values($params)),
                ]],
            ],
        ];

        $token = (string) config('whatsapp.token');
        $segredo = (string) config('whatsapp.app_secret');
        $consulta = $segredo !== '' ? ['appsecret_proof' => hash_hmac('sha256', $token, $segredo)] : [];

        try {
            $r = Http::withToken($token)
                ->timeout((int) config('whatsapp.timeout'))
                ->retry(2, 200, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->post(self::GRAPH.'/'.config('whatsapp.phone_number_id').'/messages?'.http_build_query($consulta), $corpo);
        } catch (ConnectionException $e) {
            throw new WhatsappFalhaTemporaria('Sem resposta da Meta: '.$e->getMessage(), 0, $e);
        }

        if ($r->successful()) {
            return (string) ($r->json('messages.0.id') ?? '');
        }
        $msg = (string) ($r->json('error.message') ?? "HTTP {$r->status()}");
        if ($r->status() === 429 || $r->serverError()) {
            throw new WhatsappFalhaTemporaria($msg);
        }
        throw new WhatsappFalhaDefinitiva($msg);
    }
}
