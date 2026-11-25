<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Helpers de migration para rodar igual em diferentes bancos.
 * CHECK e índice filtrado só existem nesses drivers; em SQLite (testes) são ignorados.
 */
class Esquema
{
    /** CHECK via ALTER TABLE. SQL cru não recebe o prefixo, então ele é aplicado aqui. */
    public static function check(string $tabela, string $nome, string $expressao): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'sqlsrv'], true)) {
            return;
        }
        $t = DB::getTablePrefix().$tabela;
        DB::statement("ALTER TABLE {$t} ADD CONSTRAINT {$nome} CHECK ({$expressao})");
    }

    /** Remove um CHECK criado por {@see check()}. */
    public static function removerCheck(string $tabela, string $nome): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['mysql', 'sqlsrv'], true)) {
            return;
        }
        $t = DB::getTablePrefix().$tabela;
        DB::statement($driver === 'mysql' ? "ALTER TABLE {$t} DROP CHECK {$nome}" : "ALTER TABLE {$t} DROP CONSTRAINT {$nome}");
    }

    /** UNIQUE em coluna nula (R16): no SQL Server vira índice filtrado. */
    public static function unicoNulavel(string $tabela, string $coluna, string $nome): void
    {
        if (DB::getDriverName() === 'sqlsrv') {
            $nomeTabela = DB::getTablePrefix().$tabela;
            DB::statement("CREATE UNIQUE INDEX {$nome} ON {$nomeTabela} ({$coluna}) WHERE {$coluna} IS NOT NULL");

            return;
        }
        Schema::table($tabela, fn (Blueprint $t) => $t->unique($coluna, $nome));
    }

    public static function lista(array $codigos): string
    {
        return implode(',', array_map(fn ($c) => "'{$c}'", $codigos));
    }
}
