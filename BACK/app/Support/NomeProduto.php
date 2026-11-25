<?php

namespace App\Support;

use App\Services\AnttService;

/**
 * Nome-base do produto do ERP. O ERP cadastra um código por local/estoque ("HIDROXIDO DE SODIO SOLUCAO 49% PORTO ALFA",
 * "... PORTO BETA", "... ESTOQUE - T", "TB HIDROXIDO ...") e para o frete é um produto só: tira prefixo de estoque, local,
 * cliente entre parênteses e etiquetas, e deixa o que identifica a mercadoria (nome, forma, concentração e variantes
 * como AGRICOLA, FILTRADO, GRANULADO). As listas ficam em config/frete.php (`erp.produto`): produto que ficou separado
 * por causa de um local novo se resolve acrescentando o local lá.
 */
class NomeProduto
{
    public static function base(string $descricao): string
    {
        $original = Normaliza::texto($descricao, 200) ?? '';
        $c = config('frete.erp.produto');
        $n = AnttService::normalizar($original);

        $n = preg_replace('/^(?:'.self::alt($c['prefixos']).')\s*-?\s+/u', '', $n);
        $n = preg_replace('/\(\s*ESTOQUE\s*\)/u', ' ', $n);
        $n = preg_replace('/(?<![A-Z0-9\/])(?:'.self::alt($c['ruidos']).')(?![A-Z0-9\/])\.?/u', ' ', $n);
        $n = trim((string) preg_replace('/\s+/', ' ', $n));

        $locais = self::alt($c['locais']);
        $etiquetas = self::alt($c['etiquetas']);
        $marcas = self::alt($c['marcas']);
        do {
            $antes = $n;
            $n = trim((string) preg_replace('/[\s\-]+$/u', '', $n));
            $n = (string) preg_replace('/(?<=\s)(?:'.$locais.')\s*\([^)]*\)$/u', '', $n);
            $n = (string) preg_replace('/\s+-\s*(?:'.$etiquetas.')$/u', '', $n);
            $n = (string) preg_replace('/(?:\s+-)?\s+(?:'.$marcas.')$/u', '', $n);
            $n = (string) preg_replace('/(?<=\s)(?:'.$locais.')$/u', '', $n);
            $n = trim($n);
        } while ($n !== $antes);

        $base = $n !== '' ? $n : AnttService::normalizar($original);
        $base = $c['equivalencias'][$base] ?? $base;

        return $base === AnttService::normalizar($original) ? $original : $base;
    }

    /** Chave de agrupamento: mesma base = mesmo produto. */
    public static function chave(string $descricao): string
    {
        return Normaliza::chave(self::base($descricao));
    }

    /** Alternativa de regex com as palavras da lista, as mais longas primeiro (para "SAO JOAO DO SUL" ganhar de "SAO"). */
    private static function alt(array $palavras): string
    {
        usort($palavras, fn ($a, $b) => strlen($b) <=> strlen($a));

        return implode('|', array_map(fn ($p) => preg_quote(AnttService::normalizar($p), '/'), $palavras));
    }
}
