<?php

namespace App\Services\Notificacao\Whatsapp;

/** Driver que descarta tudo (WhatsApp desligado). */
class NullWhatsappGateway implements WhatsappGateway
{
    public function enviar(string $para, string $template, array $params): string
    {
        return 'null';
    }
}
