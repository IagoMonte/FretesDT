<?php

namespace App\Mail\Transport;

class MailerChain
{
    /** "brevo, smtp,log" -> ['brevo','smtp','log']. Vazio cai no log: o sistema nunca fica sem destino para o e-mail. */
    public static function parse(?string $lista): array
    {
        $mailers = array_values(array_filter(array_map('trim', explode(',', (string) $lista))));

        return $mailers ?: ['log'];
    }
}
