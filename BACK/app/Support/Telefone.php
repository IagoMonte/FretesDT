<?php

namespace App\Support;

/** Telefones brasileiros no formato E.164 sem o "+" (55 + DDD + número), que é o que a API do WhatsApp espera. */
class Telefone
{
    public static function e164(?string $telefone): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $telefone);
        if ($d === '') {
            return null;
        }
        $d = ltrim($d, '0');
        if (str_starts_with($d, '55') && in_array(strlen($d), [12, 13], true)) {
            $d = substr($d, 2);
        }
        if (! preg_match('/^[1-9][1-9](\d{8}|9\d{8})$/', $d)) {
            return null;
        }

        return '55'.$d;
    }
}
