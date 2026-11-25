<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Cálculos monetários e de peso sempre com brick/math, nunca float. */
class Dinheiro
{
    /** valor_tonelada × kg ÷ 1000, 2 casas, HALF_UP. */
    public static function frete(string|int|float $valorTonelada, string|int|float $kg): string
    {
        return (string) BigDecimal::of((string) $valorTonelada)
            ->multipliedBy(BigDecimal::of((string) $kg))
            ->dividedBy('1000', 2, RoundingMode::HalfUp);
    }

    /** Valor total ÷ kg × 1000, 2 casas, HALF_UP: o preço por tonelada equivalente a um frete cheio (só para exibir). */
    public static function tonelada(string|int|float $frete, string|int|float $kg): string
    {
        $kg = BigDecimal::of((string) $kg);

        return $kg->isPositive()
            ? (string) BigDecimal::of((string) $frete)->multipliedBy('1000')->dividedBy($kg, 2, RoundingMode::HalfUp)
            : '0.00';
    }

    public static function soma(iterable $valores, int $escala = 3): string
    {
        $total = BigDecimal::zero();
        foreach ($valores as $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $total = $total->plus(BigDecimal::of((string) $v));
        }

        return (string) $total->toScale($escala, RoundingMode::HalfUp);
    }

    public static function dec(string|int|float|null $v, int $escala): ?string
    {
        return $v === null || $v === '' ? null : (string) BigDecimal::of((string) $v)->toScale($escala, RoundingMode::HalfUp);
    }

    public static function maior(string|int|float $a, string|int|float $b): bool
    {
        return BigDecimal::of((string) $a)->isGreaterThan(BigDecimal::of((string) $b));
    }
}
