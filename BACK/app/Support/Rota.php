<?php

namespace App\Support;

/** origem/destino do contrato ({ibge,cidade,uf}) <-> colunas origem_* / destino_*. */
class Rota
{
    public static function colunas(string $prefixo, ?array $local): array
    {
        if ($local === null) {
            return [];
        }

        return [
            "{$prefixo}_ibge" => $local['ibge'] ?? null,
            "{$prefixo}_cidade" => isset($local['cidade']) ? mb_strtoupper($local['cidade']) : null,
            "{$prefixo}_uf" => isset($local['uf']) ? strtoupper($local['uf']) : null,
        ];
    }

    public static function resumo($modelo, string $prefixo): array
    {
        return [
            'ibge' => $modelo->{"{$prefixo}_ibge"},
            'cidade' => $modelo->{"{$prefixo}_cidade"},
            'uf' => $modelo->{"{$prefixo}_uf"},
        ];
    }
}