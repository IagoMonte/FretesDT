<?php

namespace App\Services\Notificacao\Whatsapp;

/** Monta o texto de um template para drivers que enviam texto livre (Evolution). Parâmetro faltando vira "-". */
class TextoTemplate
{
    /** @param  array<int, string>  $params */
    public static function renderizar(string $template, array $params): string
    {
        $params = array_values($params);
        $corpo = config("whatsapp_textos.{$template}") ?? implode(' | ', $params);

        return preg_replace_callback('/\{\{(\d+)\}\}/', fn ($m) => (string) ($params[(int) $m[1] - 1] ?? '-'), $corpo);
    }
}
