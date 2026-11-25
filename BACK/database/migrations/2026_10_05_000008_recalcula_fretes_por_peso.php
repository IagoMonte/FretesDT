<?php

use App\Support\RecalculaFretes;
use Illuminate\Database\Migrations\Migration;

/** v1.1: valores gravados pela capacidade do veículo passam a valor_tonelada × Σkg ÷ 1000 (migration de dados, idempotente). */
return new class extends Migration
{
    public function up(): void
    {
        RecalculaFretes::executar();
    }

    public function down(): void
    {
    }
};
