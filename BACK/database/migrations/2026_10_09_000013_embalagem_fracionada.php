<?php

use App\Support\Esquema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Embalagem fracionada: além do volume (kg ÷ kg_por_volume, ex.: bombonas), o item gera o nº de paletes
 * = ceil(kg ÷ kg_por_palete), com kg_por_palete cadastrado por produto + embalagem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('embalagem', function (Blueprint $t) {
            $t->boolean('fracionada')->default(false);
        });
        Schema::table('produto_embalagem', function (Blueprint $t) {
            $t->decimal('kg_por_palete', 15, 3)->nullable();
        });
        Schema::table('cotacao_item', function (Blueprint $t) {
            $t->unsignedInteger('paletes')->nullable();
        });
        Esquema::check('produto_embalagem', 'ck_frete_prodemb_qtdpal', 'kg_por_palete IS NULL OR kg_por_palete > 0');
    }

    public function down(): void
    {
        Esquema::removerCheck('produto_embalagem', 'ck_frete_prodemb_qtdpal');
        Schema::table('cotacao_item', function (Blueprint $t) {
            $t->dropColumn('paletes');
        });
        Schema::table('produto_embalagem', function (Blueprint $t) {
            $t->dropColumn('kg_por_palete');
        });
        Schema::table('embalagem', function (Blueprint $t) {
            $t->dropColumn('fracionada');
        });
    }
};
