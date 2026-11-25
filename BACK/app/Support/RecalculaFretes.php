<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v1.1: recalcula valores gravados com a regra antiga (capacidade do veículo).
 * valor = valor_tonelada × Σ quantidade_kg ÷ 1000 (HALF_UP, 2 casas), em PHP: roda igual em SQLite e MySQL.
 * Idempotente: só grava quando o valor difere.
 */
class RecalculaFretes
{
    /** @return array{respostas: int, fechamentos: int, programacoes: int} linhas alteradas */
    public static function executar(): array
    {
        return DB::transaction(fn () => [
            'respostas' => self::respostas(),
            'fechamentos' => self::fechamentos(),
            'programacoes' => self::programacoes(),
        ]);
    }

    /** Cotação fracionada tem o frete cheio digitado pela transportadora: não se recalcula por tonelada. */
    private static function fracionadas(): array
    {
        if (! Schema::hasColumn('cotacao', 'fracionada')) {
            return [];
        }

        return array_flip(DB::table('cotacao')->where('fracionada', true)->pluck('id')->all());
    }

    /** @return array<string, string> cotacao_id => Σ quantidade_kg */
    private static function kgPorCotacao(): array
    {
        $kgs = [];
        foreach (DB::table('cotacao_item')->get(['cotacao_id', 'quantidade_kg']) as $i) {
            $kgs[$i->cotacao_id][] = $i->quantidade_kg;
        }

        return array_map(fn ($l) => Dinheiro::soma($l), $kgs);
    }

    private static function respostas(): int
    {
        $kg = self::kgPorCotacao();
        $cotacaoDe = DB::table('negociacao')->pluck('cotacao_id', 'id');
        $frac = self::fracionadas();
        $n = 0;
        foreach (DB::table('negociacao_resposta')->whereNotNull('valor_tonelada')->get(['id', 'negociacao_id', 'valor_tonelada', 'valor_frete']) as $r) {
            if (isset($frac[$cotacaoDe[$r->negociacao_id] ?? ''])) {
                continue;
            }
            $novo = Dinheiro::frete($r->valor_tonelada, $kg[$cotacaoDe[$r->negociacao_id] ?? ''] ?? '0');
            $n += self::gravar('negociacao_resposta', $r->id, 'valor_frete', $r->valor_frete, $novo);
        }

        return $n;
    }

    private static function fechamentos(): int
    {
        $kg = self::kgPorCotacao();
        $frac = self::fracionadas();
        $n = 0;
        foreach (DB::table('negociacao')->whereNotNull('valor_tonelada')->get(['id', 'cotacao_id', 'valor_tonelada', 'valor_frete']) as $r) {
            if (isset($frac[$r->cotacao_id])) {
                continue;
            }
            $novo = Dinheiro::frete($r->valor_tonelada, $kg[$r->cotacao_id] ?? '0');
            $n += self::gravar('negociacao', $r->id, 'valor_frete', $r->valor_frete, $novo);
        }

        return $n;
    }

    private static function programacoes(): int
    {
        $kgs = [];
        foreach (DB::table('programacao_item')->get(['programacao_id', 'quantidade_kg']) as $i) {
            $kgs[$i->programacao_id][] = $i->quantidade_kg;
        }
        $frac = self::fracionadas();
        $n = 0;
        foreach (DB::table('programacao')->whereNotNull('valor_tonelada')->get(['id', 'cotacao_id', 'valor_tonelada', 'valor_total']) as $p) {
            if (isset($frac[$p->cotacao_id ?? ''])) {
                continue;
            }
            $novo = Dinheiro::frete($p->valor_tonelada, Dinheiro::soma($kgs[$p->id] ?? []));
            $n += self::gravar('programacao', $p->id, 'valor_total', $p->valor_total, $novo);
        }

        return $n;
    }

    private static function gravar(string $tabela, string $id, string $coluna, mixed $atual, string $novo): int
    {
        if ($atual !== null && Dinheiro::dec($atual, 2) === $novo) {
            return 0;
        }
        DB::table($tabela)->where('id', $id)->update([$coluna => $novo]);

        return 1;
    }
}
