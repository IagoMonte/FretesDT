<?php

use App\Models\TipoVeiculo;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Novos tipos de veículo: Picape, Vuc/Van, Toco 3/4, Carreta LS, Rodotrem e Prancha. Idempotente (updateOrCreate). */
return new class extends Migration
{
    private const NOVOS = [
        'PICAPE' => 'Picape',
        'VUC_VAN' => 'Vuc/Van',
        'TOCO_34' => 'Toco 3/4',
        'CARRETA_LS' => 'Carreta LS',
        'RODOTREM' => 'Rodotrem',
        'PRANCHA' => 'Prancha',
    ];

    /** Tabelas que referenciam frete_tipo_veiculo (FK). */
    private const REFERENCIAS = [
        ['cotacao', 'tipo_veiculo_codigo'],
        ['negociacao', 'tipo_veiculo_codigo'],
        ['negociacao_resposta', 'tipo_veiculo_codigo'],
        ['programacao', 'tipo_veiculo_codigo'],
    ];

    public function up(): void
    {
        foreach (self::NOVOS as $codigo => $descricao) {
            TipoVeiculo::updateOrCreate(['codigo' => $codigo], ['descricao' => $descricao, 'ativo' => true]);
        }
    }

    /** Apaga os não usados; os que já têm cotação/negociação/programação só são desativados. */
    public function down(): void
    {
        foreach (array_keys(self::NOVOS) as $codigo) {
            $usado = collect(self::REFERENCIAS)->contains(
                fn (array $ref) => DB::table($ref[0])->where($ref[1], $codigo)->exists(),
            );

            if ($usado) {
                TipoVeiculo::where('codigo', $codigo)->update(['ativo' => false]);
            } else {
                TipoVeiculo::where('codigo', $codigo)->delete();
            }
        }
    }
};
