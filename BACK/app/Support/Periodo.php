<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/** Intervalo de datas (inclusivo, no fuso de exibição) usado por todos os filtros do /admin. */
class Periodo
{
    public const OPCOES = [7, 30, 90, 365];

    public function __construct(public readonly CarbonImmutable $de, public readonly CarbonImmutable $ate, public readonly bool $personalizado = false)
    {
        $this->dias = (int) $de->diffInDays($ate) + 1;
    }

    public readonly int $dias;

    public static function entre(string $de, string $ate): self
    {
        $tz = config('frete.fuso_exibicao');
        $a = CarbonImmutable::parse($de, $tz)->startOfDay();
        $b = CarbonImmutable::parse($ate, $tz)->startOfDay();

        return $a->greaterThan($b) ? new self($b, $a, true) : new self($a, $b, true);
    }

    /** Os últimos N dias, terminando hoje. */
    public static function dias(int $n): self
    {
        $hoje = CarbonImmutable::now(config('frete.fuso_exibicao'))->startOfDay();

        return new self($hoje->subDays($n - 1), $hoje);
    }

    /** `de`/`ate` (YYYY-MM-DD) ou `periodo` (7, 30, 90, 365); qualquer valor inválido cai nos últimos 30 dias. */
    public static function daRequisicao(array $q): self
    {
        $de = (string) ($q['de'] ?? '');
        $ate = (string) ($q['ate'] ?? '');
        if (self::dataValida($de) && self::dataValida($ate)) {
            return self::entre($de, $ate);
        }
        $n = (int) ($q['periodo'] ?? 30);

        return self::dias(in_array($n, self::OPCOES, true) ? $n : 30);
    }

    public function anterior(): self
    {
        $ate = $this->de->subDay();

        return new self($ate->subDays($this->dias - 1), $ate, $this->personalizado);
    }

    /** Limites em UTC (como as colunas DATETIME estão gravadas). */
    public function inicioUtc(): string
    {
        return $this->de->startOfDay()->utc()->format('Y-m-d H:i:s.v');
    }

    public function fimUtc(): string
    {
        return $this->ate->endOfDay()->utc()->format('Y-m-d H:i:s.v');
    }

    public function rotulo(): string
    {
        return $this->de->format('d/m/Y').' a '.$this->ate->format('d/m/Y');
    }

    /** @return array<string, string> parâmetros para manter o período nos links */
    public function query(): array
    {
        return ['de' => $this->de->toDateString(), 'ate' => $this->ate->toDateString()];
    }

    private static function dataValida(string $v): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) !== 1) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $v));

        return checkdate($m, $d, $y);
    }
}
