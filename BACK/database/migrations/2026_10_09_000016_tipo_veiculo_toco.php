<?php

use App\Models\TipoVeiculo;
use Illuminate\Database\Migrations\Migration;

/** Novo tipo de veículo: Toco. */
return new class extends Migration
{
    public function up(): void
    {
        TipoVeiculo::updateOrCreate(['codigo' => 'TOCO'], ['descricao' => 'Toco', 'ativo' => true]);
    }

    public function down(): void
    {
        TipoVeiculo::where('codigo', 'TOCO')->delete();
    }
};
