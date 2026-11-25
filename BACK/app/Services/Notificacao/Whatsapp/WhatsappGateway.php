<?php

namespace App\Services\Notificacao\Whatsapp;

/** Porta de saída do WhatsApp. Trocar o driver (`WHATSAPP_DRIVER`) não mexe em quem notifica. */
interface WhatsappGateway
{
    /**
     * @param  string  $para  número em E.164 sem "+" (5519999990001)
     * @param  array<int, string>  $params  parâmetros posicionais do template
     * @return string id da mensagem no provedor
     *
     * @throws WhatsappFalhaDefinitiva 4xx: não adianta tentar de novo
     * @throws WhatsappFalhaTemporaria 5xx / 429 / timeout: vale tentar de novo
     */
    public function enviar(string $para, string $template, array $params): string;
}
