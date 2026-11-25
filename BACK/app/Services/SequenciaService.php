<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Códigos de negócio COT-000001 / CRG-000001 (R3). Lock de linha dentro da transação do chamador. */
class SequenciaService
{
    public function proximo(string $nome, string $prefixo): string
    {
        return DB::transaction(function () use ($nome, $prefixo) {
            $linha = DB::table('sequencia')->where('nome', $nome)->lockForUpdate()->first();
            if (! $linha) {
                DB::table('sequencia')->insert(['nome' => $nome, 'ultimo_valor' => 0, 'atualizado_em' => now()->format('Y-m-d H:i:s.v')]);
                $ultimo = 0;
            } else {
                $ultimo = (int) $linha->ultimo_valor;
            }
            $novo = $ultimo + 1;
            DB::table('sequencia')->where('nome', $nome)->update(['ultimo_valor' => $novo, 'atualizado_em' => now()->format('Y-m-d H:i:s.v')]);

            return $prefixo.str_pad((string) $novo, 6, '0', STR_PAD_LEFT);
        });
    }
}