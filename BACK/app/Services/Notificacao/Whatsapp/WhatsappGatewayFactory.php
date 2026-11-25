<?php

namespace App\Services\Notificacao\Whatsapp;

class WhatsappGatewayFactory
{
    public static function criar(): WhatsappGateway
    {
        return match (config('whatsapp.driver')) {
            'cloud' => new CloudWhatsappGateway,
            'evolution' => new EvolutionWhatsappGateway,
            'null' => new NullWhatsappGateway,
            default => new LogWhatsappGateway,
        };
    }
}
