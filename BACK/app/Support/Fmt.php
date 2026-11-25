<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use Throwable;

class Fmt
{
    /** ISO-8601 com milissegundos no fuso de exibição (America/Sao_Paulo). */
    public static function iso(?CarbonInterface $d): ?string
    {
        return $d?->copy()->setTimezone(config('frete.fuso_exibicao'))->format('Y-m-d\TH:i:s.vP');
    }

    /** Data de negócio (coluna DATE) como YYYY-MM-DD. */
    public static function data(mixed $d): ?string
    {
        if ($d === null || $d === '') {
            return null;
        }

        if ($d instanceof CarbonInterface) {
            return $d->format('Y-m-d');
        }

        return substr((string) $d, 0, 10);
    }

    /** Valor em reais para as variáveis dos templates do WhatsApp (o "R$" já está no texto): 180 → "180,00"; 1234.5 → "1.234,50". Não numérico passa intacto. */
    public static function dinheiroWhatsapp(mixed $v): string
    {
        $n = self::decimal($v);

        return $n === null ? (string) $v : self::ptBr($n->toScale(2, RoundingMode::HalfUp));
    }

    /** Peso em kg para os templates do WhatsApp: redondo sem casas ("12.000"), quebrado com duas ("15,34"). Não numérico passa intacto. */
    public static function pesoWhatsapp(mixed $v): string
    {
        $n = self::decimal($v);
        if ($n === null) {
            return (string) $v;
        }
        $n = $n->toScale(2, RoundingMode::HalfUp);

        return self::ptBr($n->isEqualTo($n->toScale(0, RoundingMode::Down)) ? $n->toScale(0) : $n);
    }

    private static function decimal(mixed $v): ?BigDecimal
    {
        if (! is_scalar($v) || ! is_numeric($v)) {
            return null;
        }
        try {
            return BigDecimal::of(is_float($v) ? sprintf('%.6F', $v) : (string) $v);
        } catch (Throwable) {
            return null;
        }
    }

    /** "1234567.89" → "1.234.567,89" (separador de milhar a cada 3 dígitos, vírgula decimal). */
    private static function ptBr(BigDecimal $n): string
    {
        [$int, $frac] = array_pad(explode('.', (string) $n->abs()), 2, null);
        $int = strrev(implode('.', str_split(strrev($int), 3)));

        return ($n->isNegative() ? '-' : '').$int.($frac !== null ? ','.$frac : '');
    }

    /** Hoje no fuso de negócio (data de pedido/solicitação). */
    /** Teto das datas previstas (coleta/entrega): evita 9999-12-31 e afins. */
    public static function tetoData(): string
    {
        return now(config('frete.fuso_exibicao'))->addYears(2)->toDateString();
    }

    public static function hoje(int $maisDias = 0): string
    {
        return now(config('frete.fuso_exibicao'))->addDays($maisDias)->toDateString();
    }

    /** Aplica `$f` só a texto (ou null, tratado como vazio); lista/objeto segue intacto para a regra `string` recusar com 422. */
    public static function seTexto(mixed $v, callable $f): mixed
    {
        return is_string($v) || $v === null ? $f((string) $v) : $v;
    }

    public static function cnpj(mixed $v): mixed
    {
        if ($v === null || ! is_scalar($v)) {
            return $v;
        }

        return preg_replace('/\D+/', '', (string) $v);
    }

    /** "12345678000190" → "12.345.678/0001-90"; fora do padrão de 14 dígitos passa intacto. */
    public static function cnpjMascara(mixed $v): string
    {
        $d = (string) self::cnpj($v);

        return strlen($d) === 14 ? preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $d) : (string) $v;
    }

    /** CNPJ com 14 dígitos e dígitos verificadores corretos (recusa sequências repetidas). */
    public static function cnpjValido(mixed $v): bool
    {
        if (! is_scalar($v)) {
            return false;
        }
        $d = self::cnpj($v);
        if ($d === null || strlen($d) !== 14 || preg_match('/^(\d)\1{13}$/', $d) === 1) {
            return false;
        }
        foreach ([12, 13] as $n) {
            $pesos = $n === 12 ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2] : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
            $soma = 0;
            foreach ($pesos as $i => $p) {
                $soma += (int) $d[$i] * $p;
            }
            $dv = $soma % 11 < 2 ? 0 : 11 - $soma % 11;
            if ((int) $d[$n] !== $dv) {
                return false;
            }
        }

        return true;
    }
}
