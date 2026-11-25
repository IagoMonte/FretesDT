<?php

use App\Support\Esquema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tipo de veículo deixa de ter capacidade (peso): nada no sistema usava esse valor. */
return new class extends Migration
{
    public function up(): void
    {
        Esquema::removerCheck('tipo_veiculo', 'ck_frete_tipo_veiculo_cap');
        Schema::table('tipo_veiculo', function (Blueprint $t) {
            $t->dropColumn('capacidade_kg');
        });
    }

    public function down(): void
    {
        Schema::table('tipo_veiculo', function (Blueprint $t) {
            $t->decimal('capacidade_kg', 15, 3)->nullable();
        });
        Esquema::check('tipo_veiculo', 'ck_frete_tipo_veiculo_cap', 'capacidade_kg IS NULL OR capacidade_kg > 0');
    }
};
