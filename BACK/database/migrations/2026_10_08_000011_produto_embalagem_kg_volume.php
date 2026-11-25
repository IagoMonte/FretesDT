<?php

use App\Support\Esquema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Fator de conversão kg por volume, cadastrado por produto + embalagem. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produto_embalagem', function (Blueprint $t) {
            $t->decimal('kg_por_volume', 15, 3)->nullable();
        });
        Esquema::check('produto_embalagem', 'ck_frete_prodemb_kgvol', 'kg_por_volume IS NULL OR kg_por_volume > 0');
    }

    public function down(): void
    {
        Esquema::removerCheck('produto_embalagem', 'ck_frete_prodemb_kgvol');
        Schema::table('produto_embalagem', function (Blueprint $t) {
            $t->dropColumn('kg_por_volume');
        });
    }
};
