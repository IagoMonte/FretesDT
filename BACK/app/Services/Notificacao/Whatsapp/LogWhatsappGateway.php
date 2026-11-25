<?php

namespace App\Services\Notificacao\Whatsapp;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Driver de desenvolvimento: só registra no log, não chama a rede. */
class LogWhatsappGateway implements WhatsappGateway
{
    public function enviar(string $para, string $template, array $params): string
    {
        $id = 'log-'.Str::uuid();
        Log::info('WhatsApp (driver log)', ['id' => $id, 'para' => $para, 'template' => $template, 'params' => $params]);

        return $id;
    }
}
