<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** PERIGOSA_FRIGORIFICADA_AQUECIDA tem 31 caracteres: o MySQL (modo estrito) recusa em string(30); o SQLite não impõe o limite. */
return new class extends Migration
{
    private const COLUNAS = [
        'tipo_carga_antt' => ['codigo', false],
        'antt_coeficiente' => ['tipo_carga_codigo', false],
        'cotacao' => ['tipo_carga_antt_codigo', true],
        'negociacao' => ['tipo_carga_antt_codigo', true],
        'negociacao_resposta' => ['tipo_carga_antt_codigo', true],
    ];

    public function up(): void
    {
        $this->ajusta(40);
    }

    public function down(): void
    {
        $this->ajusta(30);
    }

    private function ajusta(int $tamanho): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (self::COLUNAS as $tabela => [$coluna, $nulavel]) {
            Schema::table($tabela, function (Blueprint $t) use ($coluna, $nulavel, $tamanho) {
                $t->string($coluna, $tamanho)->nullable($nulavel)->change();
            });
        }
        Schema::enableForeignKeyConstraints();
    }
};
