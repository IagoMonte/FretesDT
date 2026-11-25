<?php

use App\Support\Esquema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Piso mínimo de frete da ANTT (piso = km × CCD + CC): coeficientes por tipo de carga × eixos com vigência,
 * tabela de distâncias entre cidades e snapshot do piso na resposta/fechamento (R8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipo_carga_antt', function (Blueprint $t) {
            $t->string('codigo', 30);
            $t->string('descricao', 100);
            $t->boolean('ativo')->default(true);
            $t->auditoria();
            $t->primary('codigo', 'pk_frete_tipo_carga_antt');
        });

        Schema::create('antt_coeficiente', function (Blueprint $t) {
            $t->uuidCol('id');
            $t->string('tipo_carga_codigo', 30);
            $t->smallInteger('eixos');
            $t->decimal('ccd', 15, 4);
            $t->decimal('cc', 15, 2);
            $t->date('vigencia_inicio');
            $t->date('vigencia_fim')->nullable();
            $t->boolean('ativo')->default(true);
            $t->auditoria();
            $t->primary('id', 'pk_frete_antt_coeficiente');
            $t->unique(['tipo_carga_codigo', 'eixos', 'vigencia_inicio'], 'uk_frete_anttcoef_vig');
            $t->foreign('tipo_carga_codigo', 'fk_frete_anttcoef_tipo')->references('codigo')->on('tipo_carga_antt');
        });
        Esquema::check('antt_coeficiente', 'ck_frete_anttcoef_eixos', 'eixos BETWEEN 2 AND 9');
        Esquema::check('antt_coeficiente', 'ck_frete_anttcoef_vals', 'ccd >= 0 AND cc >= 0');
        Esquema::check('antt_coeficiente', 'ck_frete_anttcoef_vig', 'vigencia_fim IS NULL OR vigencia_fim >= vigencia_inicio');

        Schema::create('municipio', function (Blueprint $t) {
            $t->asciiChar('ibge', 7);
            $t->string('nome', 100);
            $t->string('nome_busca', 100);
            $t->asciiChar('uf', 2);
            $t->primary('ibge', 'pk_frete_municipio');
            $t->index(['uf', 'nome_busca'], 'ix_frete_municipio_busca');
        });

        Schema::create('distancia', function (Blueprint $t) {
            $t->asciiChar('origem_ibge', 7);
            $t->asciiChar('destino_ibge', 7);
            $t->decimal('km', 10, 1);
            $t->primary(['origem_ibge', 'destino_ibge'], 'pk_frete_distancia');
        });
        Esquema::check('distancia', 'ck_frete_distancia_km', 'km >= 0');
        Esquema::check('distancia', 'ck_frete_distancia_par', 'origem_ibge < destino_ibge');

        Schema::table('cotacao', function (Blueprint $t) {
            $t->string('tipo_carga_antt_codigo', 30)->nullable();
            $t->decimal('distancia_km', 10, 1)->nullable();
            $t->foreign('tipo_carga_antt_codigo', 'fk_frete_cotacao_tipocarga')->references('codigo')->on('tipo_carga_antt');
        });
        Esquema::check('cotacao', 'ck_frete_cotacao_km', 'distancia_km IS NULL OR distancia_km >= 0');

        foreach (['negociacao_resposta' => 'negresp', 'negociacao' => 'negociacao'] as $tabela => $abrev) {
            Schema::table($tabela, function (Blueprint $t) use ($abrev) {
                $t->smallInteger('eixos')->nullable();
                $t->decimal('piso_antt_valor', 15, 2)->nullable();
                $t->decimal('piso_antt_km', 10, 1)->nullable();
                $t->uuidCol('piso_antt_coef_id')->nullable();
                $t->foreign('piso_antt_coef_id', "fk_frete_{$abrev}_anttcoef")->references('id')->on('antt_coeficiente');
            });
            Esquema::check($tabela, "ck_frete_{$abrev}_eixos", 'eixos IS NULL OR eixos BETWEEN 2 AND 9');
        }
    }

    public function down(): void
    {
        foreach (['negociacao' => 'negociacao', 'negociacao_resposta' => 'negresp'] as $tabela => $abrev) {
            Esquema::removerCheck($tabela, "ck_frete_{$abrev}_eixos");
            Schema::table($tabela, function (Blueprint $t) use ($abrev) {
                $t->dropForeign("fk_frete_{$abrev}_anttcoef");
                $t->dropColumn(['eixos', 'piso_antt_valor', 'piso_antt_km', 'piso_antt_coef_id']);
            });
        }
        Esquema::removerCheck('cotacao', 'ck_frete_cotacao_km');
        Schema::table('cotacao', function (Blueprint $t) {
            $t->dropForeign('fk_frete_cotacao_tipocarga');
            $t->dropColumn(['tipo_carga_antt_codigo', 'distancia_km']);
        });
        Schema::dropIfExists('distancia');
        Schema::dropIfExists('municipio');
        Schema::dropIfExists('antt_coeficiente');
        Schema::dropIfExists('tipo_carga_antt');
    }
};
