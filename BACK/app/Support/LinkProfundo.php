<?php

namespace App\Support;

use App\Models\Notificacao;

/** Link da notificação para o front: abre a lista certa e o registro do código (`?abrir=`). Único para e-mail e WhatsApp. */
class LinkProfundo
{
    private const CAMINHOS = ['quotes' => '/quotes', 'loads' => '/loads'];

    public static function para(Notificacao $n): string
    {
        $base = rtrim((string) config('notificacoes.front_url'), '/');
        $caminho = self::CAMINHOS[config("notificacoes.eventos.{$n->evento}.rota")] ?? null;
        $codigo = (string) $n->registro_codigo;
        if ($caminho === null || $codigo === '') {
            return $base;
        }

        return $base.$caminho.'?abrir='.rawurlencode($codigo);
    }
}
