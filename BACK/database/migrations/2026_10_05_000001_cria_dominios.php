<?php

use App\Support\Esquema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('embalagem', function (Blueprint $t) {
            $t->string('codigo', 30);
            $t->string('descricao', 100);
            $t->boolean('ativo')->default(true);
            $t->auditoria();
            $t->primary('codigo', 'pk_frete_embalagem');
        });

        Schema::create('tipo_veiculo', function (Blueprint $t) {
            $t->string('codigo', 30);
            $t->string('descricao', 100);
            $t->decimal('capacidade_kg', 15, 3);
            $t->boolean('ativo')->default(true);
            $t->auditoria();
            $t->primary('codigo', 'pk_frete_tipo_veiculo');
        });
        Esquema::check('tipo_veiculo', 'ck_frete_tipo_veiculo_cap', 'capacidade_kg > 0');

        Schema::create('sequencia', function (Blueprint $t) {
            $t->string('nome', 30);
            $t->bigInteger('ultimo_valor');
            $t->dateTime('atualizado_em', 3);
            $t->primary('nome', 'pk_frete_sequencia');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sequencia');
        Schema::dropIfExists('tipo_veiculo');
        Schema::dropIfExists('embalagem');
    }
};
