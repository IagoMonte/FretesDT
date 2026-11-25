<?php

namespace App\Support;

use Carbon\CarbonInterface;

/** Formatação pt-BR dos números e datas do /admin. */
class Formato
{
    public static function brl(float|int|null $v): string
    {
        return 'R$ '.number_format((float) $v, 2, ',', '.');
    }

    public static function num(float|int|null $v, int $casas = 0): string
    {
        return number_format((float) $v, $casas, ',', '.');
    }

    /** 50 → "50%"; 33.333 → "33,3%". */
    public static function pct(float|int|null $v): string
    {
        $v = round((float) $v, 1);

        return ($v == floor($v) ? number_format($v, 0, ',', '.') : number_format($v, 1, ',', '.')).'%';
    }

    public static function toneladas(float|int|null $v): string
    {
        return number_format((float) $v, (float) $v == floor((float) $v) ? 0 : 1, ',', '.').' t';
    }

    /** 30 → "30 h"; 50 → "2d 2h". */
    public static function horas(float|int|null $v): string
    {
        $h = (float) $v;
        if ($h <= 0) {
            return '—';
        }
        if ($h < 48) {
            return number_format($h, $h == floor($h) ? 0 : 1, ',', '.').' h';
        }

        return floor($h / 24).'d '.round(fmod($h, 24)).'h';
    }

    /** Variação percentual com seta ("▲ 12,5%"); null quando não há base de comparação. */
    public static function delta(?float $d): string
    {
        if ($d === null) {
            return '';
        }

        return ($d > 0 ? '▲ ' : ($d < 0 ? '▼ ' : '• ')).self::pct(abs($d));
    }

    public static function dh(?CarbonInterface $d): string
    {
        return $d ? $d->format('d/m/Y H:i:s') : '—';
    }

    public static function mes(string $aaaamm): string
    {
        $meses = ['01' => 'jan', '02' => 'fev', '03' => 'mar', '04' => 'abr', '05' => 'mai', '06' => 'jun', '07' => 'jul', '08' => 'ago', '09' => 'set', '10' => 'out', '11' => 'nov', '12' => 'dez'];

        return ($meses[substr($aaaamm, 5, 2)] ?? '?').'/'.substr($aaaamm, 2, 2);
    }
}
