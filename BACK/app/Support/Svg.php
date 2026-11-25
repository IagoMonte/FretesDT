<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Gráficos SVG gerados no servidor (sem biblioteca): leves, imprimíveis e acessíveis
 * (cada marca tem <title> com o valor). Todo texto vindo de dados passa por e().
 */
class Svg
{
    public const COR = ['#00693C', '#D6001C', '#33463C', '#00A050', '#A50014', '#7F8D85', '#004D2C', '#F3B7BD'];

    /**
     * Linhas e/ou colunas por mês/categoria.
     *
     * @param  list<string>  $rotulos
     * @param  list<array{nome: string, valores: list<float|int>, cor?: string, tipo?: 'linha'|'coluna', formato?: callable}>  $series
     */
    public static function serie(array $rotulos, array $series, int $altura = 220): HtmlString
    {
        $n = count($rotulos);
        if ($n === 0) {
            return self::vazio();
        }
        $w = 640;
        $h = $altura;
        [$ml, $mr, $mt, $mb] = [64, 26, 14, 28];
        $iw = $w - $ml - $mr;
        $ih = $h - $mt - $mb;
        $max = 0.0;
        foreach ($series as $s) {
            $max = max($max, ...array_map('floatval', array_filter($s['valores'], fn ($v) => $v !== null) ?: [0]));
        }
        $max = self::bonito($max);
        $x = fn (int $i) => $ml + ($n === 1 ? $iw / 2 : $i * $iw / ($n - 1));
        $xc = fn (int $i) => $ml + ($i + .5) * $iw / $n;
        $y = fn (float $v) => $mt + $ih - ($max > 0 ? $v / $max * $ih : 0);

        $o = "<svg class=\"chart\" viewBox=\"0 0 {$w} {$h}\" role=\"img\" aria-label=\"".e(implode(', ', array_column($series, 'nome')))."\" preserveAspectRatio=\"xMidYMid meet\">";
        foreach ([0, .25, .5, .75, 1] as $f) {
            $yy = round($y($max * $f), 1);
            $o .= "<line x1=\"{$ml}\" x2=\"".($w - $mr)."\" y1=\"{$yy}\" y2=\"{$yy}\" class=\"grid\"/><text x=\"".($ml - 6)."\" y=\"".($yy + 3.5)."\" class=\"ax\" text-anchor=\"end\">".e(self::curto($max * $f)).'</text>';
        }
        $passo = max(1, (int) ceil($n / 8));
        foreach ($rotulos as $i => $r) {
            if ($i % $passo === 0) {
                $o .= '<text x="'.round($n === 1 || self::temColuna($series) ? $xc($i) : $x($i), 1).'" y="'.($h - 8)."\" class=\"ax\" text-anchor=\"middle\">".e($r).'</text>';
            }
        }
        $cores = self::COR;
        foreach ($series as $k => $s) {
            $cor = $s['cor'] ?? $cores[$k % count($cores)];
            $fmt = $s['formato'] ?? fn ($v) => self::curto($v);
            if (($s['tipo'] ?? 'linha') === 'coluna') {
                $bw = max(4, min(46, $iw / $n * .6));
                foreach ($s['valores'] as $i => $v) {
                    $yy = $y((float) $v);
                    $o .= '<rect x="'.round($xc($i) - $bw / 2, 1).'" y="'.round($yy, 1).'" width="'.round($bw, 1).'" height="'.round($mt + $ih - $yy, 1)."\" rx=\"3\" fill=\"{$cor}\" opacity=\".88\"><title>".e($rotulos[$i].' · '.$s['nome'].': '.$fmt($v)).'</title></rect>';
                }

                continue;
            }
            $segmento = [];
            $segmentos = [];
            foreach ($s['valores'] as $i => $v) {
                if ($v === null) {
                    $segmento && $segmentos[] = $segmento;
                    $segmento = [];

                    continue;
                }
                $segmento[] = [$i, round(self::temColuna($series) ? $xc($i) : $x($i), 1), round($y((float) $v), 1), $v];
            }
            $segmento && $segmentos[] = $segmento;
            foreach ($segmentos as $seg) {
                if (count($seg) > 1) {
                    $o .= '<polyline points="'.implode(' ', array_map(fn ($p) => $p[1].','.$p[2], $seg))."\" fill=\"none\" stroke=\"{$cor}\" stroke-width=\"2.4\" stroke-linejoin=\"round\" stroke-linecap=\"round\"/>";
                }
                foreach ($seg as [$i, $cx, $cy, $v]) {
                    $o .= "<circle cx=\"{$cx}\" cy=\"{$cy}\" r=\"3.6\" fill=\"#fff\" stroke=\"{$cor}\" stroke-width=\"2\"><title>".e($rotulos[$i].' · '.$s['nome'].': '.$fmt($v)).'</title></circle>';
                }
            }
        }

        return new HtmlString($o.'</svg>'.self::legenda($series));
    }

    /**
     * Barras horizontais (rótulo, valor, texto opcional).
     *
     * @param  list<array{rotulo: string, valor: float|int, texto?: string, cor?: string, link?: string}>  $itens
     */
    public static function barras(array $itens, ?float $max = null): HtmlString
    {
        if (! $itens) {
            return self::vazio();
        }
        $max ??= max(array_map(fn ($i) => (float) $i['valor'], $itens)) ?: 1;
        $o = '<div class="bars">';
        foreach ($itens as $k => $i) {
            $pct = $max > 0 ? max(0, min(100, (float) $i['valor'] / $max * 100)) : 0;
            $cor = $i['cor'] ?? self::COR[$k % 2];
            $rot = e($i['rotulo']);
            $rot = isset($i['link']) ? '<a href="'.e($i['link']).'">'.$rot.'</a>' : $rot;
            $o .= "<div class=\"bar-row\"><span class=\"bl\">{$rot}</span><span class=\"tr\"><span class=\"fl\" style=\"width:".round($pct, 1)."%;background:{$cor}\"></span></span><span class=\"bn\">".e($i['texto'] ?? self::curto((float) $i['valor'])).'</span></div>';
        }

        return new HtmlString($o.'</div>');
    }

    /** Barras empilhadas de uma linha (ex.: vitórias × derrotas). */
    public static function empilhada(array $partes, int $largura = 160): HtmlString
    {
        $total = array_sum(array_column($partes, 'valor'));
        if ($total <= 0) {
            return new HtmlString('<span class="muted">—</span>');
        }
        $o = '<svg class="stack" viewBox="0 0 '.$largura.' 12" width="'.$largura.'" height="12" role="img">';
        $x = 0.0;
        foreach ($partes as $p) {
            $wp = $p['valor'] / $total * $largura;
            if ($wp > 0) {
                $o .= '<rect x="'.round($x, 1).'" y="0" width="'.round($wp, 1).'" height="12" fill="'.$p['cor'].'"><title>'.e($p['rotulo'].': '.$p['valor']).'</title></rect>';
            }
            $x += $wp;
        }

        return new HtmlString($o.'</svg>');
    }

    /** @param list<array{rotulo: string, valor: float|int, cor?: string}> $partes */
    public static function donut(array $partes, string $centro = ''): HtmlString
    {
        $total = array_sum(array_map(fn ($p) => (float) $p['valor'], $partes));
        if ($total <= 0) {
            return self::vazio();
        }
        $r = 52;
        $c = 2 * M_PI * $r;
        $o = '<div class="donut"><svg viewBox="0 0 140 140" role="img" aria-label="'.e(implode(', ', array_map(fn ($p) => $p['rotulo'].' '.$p['valor'], $partes))).'"><g transform="rotate(-90 70 70)">';
        $off = 0.0;
        foreach ($partes as $k => $p) {
            $len = $p['valor'] / $total * $c;
            $cor = $p['cor'] ?? self::COR[$k % count(self::COR)];
            if ($len > 0) {
                $o .= '<circle cx="70" cy="70" r="'.$r.'" fill="none" stroke="'.$cor.'" stroke-width="20" stroke-dasharray="'.round($len, 2).' '.round($c - $len, 2).'" stroke-dashoffset="'.round(-$off, 2).'"><title>'.e($p['rotulo'].': '.$p['valor']).'</title></circle>';
            }
            $off += $len;
        }
        $o .= '</g><text x="70" y="76" text-anchor="middle" class="donut-c">'.e($centro).'</text></svg><ul class="legend">';
        foreach ($partes as $k => $p) {
            $o .= '<li><i style="background:'.($p['cor'] ?? self::COR[$k % count(self::COR)]).'"></i>'.e($p['rotulo']).' <b>'.e((string) $p['valor']).'</b></li>';
        }

        return new HtmlString($o.'</ul></div>');
    }

    /** Faixa mín–média–máx numa escala comum, com marcador opcional (ex.: preço da transportadora). */
    public static function faixa(float $min, float $media, float $max, float $escalaMin, float $escalaMax, ?float $marcador = null, string $unidade = 'R$/t'): HtmlString
    {
        $w = 220;
        $span = max($escalaMax - $escalaMin, .0001);
        $px = fn (float $v) => round(6 + ($v - $escalaMin) / $span * ($w - 12), 1);
        $o = "<svg class=\"faixa\" viewBox=\"0 0 {$w} 22\" width=\"{$w}\" height=\"22\" role=\"img\" aria-label=\"mínimo ".e(Formato::num($min, 2)).', média '.e(Formato::num($media, 2)).', máximo '.e(Formato::num($max, 2))."\">";
        $o .= '<line x1="6" x2="'.($w - 6).'" y1="11" y2="11" stroke="#E4E4DF" stroke-width="2"/>';
        $o .= '<rect x="'.$px($min).'" y="7" width="'.max(2, $px($max) - $px($min)).'" height="8" rx="4" fill="#BFDCCB"><title>'.e('mín '.Formato::num($min, 2).' · máx '.Formato::num($max, 2).' '.$unidade).'</title></rect>';
        $o .= '<line x1="'.$px($media).'" x2="'.$px($media).'" y1="3" y2="19" stroke="#00693C" stroke-width="2.5"><title>'.e('média '.Formato::num($media, 2).' '.$unidade).'</title></line>';
        if ($marcador !== null && $marcador > 0) {
            $o .= '<path d="M'.$px($marcador).' 13 l5 8 l-10 0 z" fill="#D6001C"><title>'.e('preço '.Formato::num($marcador, 2).' '.$unidade).'</title></path>';
        }

        return new HtmlString($o.'</svg>');
    }

    /**
     * Mapa de calor linha × coluna.
     *
     * @param  list<list<float|int|null>>  $m
     * @param  list<string>  $linhas
     * @param  list<string>  $colunas
     */
    public static function calor(array $m, array $linhas, array $colunas, ?callable $texto = null, int $celula = 22): HtmlString
    {
        if (! $m || ! $colunas) {
            return self::vazio();
        }
        $max = max(array_map(fn ($l) => max(array_map(fn ($v) => (float) $v, $l ?: [0])), $m)) ?: 1;
        $lw = max(40, min(170, 12 + 6 * max(array_map('mb_strlen', $linhas ?: ['']))));
        $w = $lw + count($colunas) * $celula;
        $h = 16 + count($linhas) * $celula;
        $o = "<svg class=\"calor\" viewBox=\"0 0 {$w} {$h}\" role=\"img\">";
        foreach ($colunas as $j => $c) {
            if (count($colunas) <= 12 || $j % 3 === 0) {
                $c = mb_strlen($c) * 6 > $celula + 4 ? mb_substr($c, 0, max(2, (int) (($celula + 4) / 6))).'…' : $c;
                $o .= '<text x="'.($lw + $j * $celula + $celula / 2).'" y="10" class="ax" text-anchor="middle">'.e($c).'</text>';
            }
        }
        foreach ($linhas as $i => $l) {
            $y = 16 + $i * $celula;
            $o .= '<text x="'.($lw - 6).'" y="'.($y + $celula / 2 + 3.5).'" class="ax" text-anchor="end">'.e($l).'</text>';
            foreach ($colunas as $j => $c) {
                $v = (float) ($m[$i][$j] ?? 0);
                $t = $texto ? $texto($v, $l, $c) : "{$l} · {$c}: ".self::curto($v);
                $o .= '<rect x="'.($lw + $j * $celula + 1).'" y="'.($y + 1).'" width="'.($celula - 2).'" height="'.($celula - 2).'" rx="3" fill="'.self::verde($v / $max).'"><title>'.e($t).'</title></rect>';
            }
        }

        return new HtmlString($o.'</svg>');
    }

    public static function spark(array $valores, string $cor = '#00693C'): HtmlString
    {
        $valores = array_values(array_map('floatval', $valores));
        if (count($valores) < 2) {
            return new HtmlString('');
        }
        $max = max($valores);
        $min = min($valores);
        $span = max($max - $min, .0001);
        $pts = [];
        foreach ($valores as $i => $v) {
            $pts[] = round($i * 100 / (count($valores) - 1), 1).','.round(22 - ($v - $min) / $span * 20, 1);
        }

        return new HtmlString('<svg class="spark" viewBox="0 0 100 24" width="100" height="24" aria-hidden="true"><polyline points="'.implode(' ', $pts)."\" fill=\"none\" stroke=\"{$cor}\" stroke-width=\"2\" stroke-linejoin=\"round\"/></svg>");
    }

    /** Verde sequencial: 0 → papel, 1 → verde escuro. */
    public static function verde(float $f): string
    {
        $f = max(0, min(1, $f));
        if ($f <= 0) {
            return '#F0F2EF';
        }
        $a = [230, 242, 236];
        $b = [0, 77, 44];
        $c = array_map(fn ($i) => (int) round($a[$i] + ($b[$i] - $a[$i]) * $f), [0, 1, 2]);

        return sprintf('#%02X%02X%02X', ...$c);
    }

    /** 1234 → "1,2 mil"; 1_500_000 → "1,5 mi"; valores pequenos com até 2 casas. */
    public static function curto(float $v): string
    {
        $a = abs($v);
        if ($a >= 1_000_000) {
            return Formato::num($v / 1_000_000, 1).' mi';
        }
        if ($a >= 10_000) {
            return Formato::num($v / 1000, 1).' mil';
        }

        return Formato::num($v, $v == floor($v) ? 0 : 2);
    }

    private static function bonito(float $max): float
    {
        if ($max <= 0) {
            return 1;
        }
        $p = 10 ** floor(log10($max));
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            if ($max <= $m * $p) {
                return $m * $p;
            }
        }

        return 10 * $p;
    }

    private static function temColuna(array $series): bool
    {
        return in_array('coluna', array_column($series, 'tipo'), true);
    }

    private static function legenda(array $series): string
    {
        if (count($series) < 2) {
            return '';
        }
        $o = '<ul class="legend">';
        foreach ($series as $k => $s) {
            $o .= '<li><i style="background:'.($s['cor'] ?? self::COR[$k % count(self::COR)]).'"></i>'.e($s['nome']).'</li>';
        }

        return $o.'</ul>';
    }

    private static function vazio(): HtmlString
    {
        return new HtmlString('<div class="empty">Sem dados no período.</div>');
    }
}
