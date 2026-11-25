<?php

namespace Database\Seeders;

use App\Models\Embalagem;
use App\Models\TipoCargaAntt;
use App\Models\TipoVeiculo;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Domínios: embalagens, tipos de veículo (capacidade = TONS do front x 1000) e sequências COT-/CRG-. */
class DominiosSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'BOMBONA' => ['Bombona', true, true, false],
            'CONTAINER' => ['Contêiner', false, false, false],
            'GRANEL_LIQUIDO' => ['Granel líquido', false, false, true],
            'GRANEL_SOLIDO' => ['Granel sólido', false, false, true],
            'SACARIA' => ['Sacaria', true, true, false],
        ] as $codigo => [$descricao, $permite_paletizar, $permite_remontar, $granel]) {
            Embalagem::updateOrCreate(['codigo' => $codigo], [
                'descricao' => $descricao,
                'permite_paletizar' => $permite_paletizar,
                'permite_remontar' => $permite_remontar,
                'granel' => $granel,
                'ativo' => true,
            ]);
        }

        foreach ([
            'PICAPE' => 'Picape',
            'VUC_VAN' => 'Vuc/Van',
            'TOCO' => 'Toco',
            'TOCO_34' => 'Toco 3/4',
            'TRUCK' => 'Truck',
            'BITRUCK' => 'Bitruck',
            'CARRETA' => 'Carreta',
            'CARRETA_LS' => 'Carreta LS',
            'BITREM' => 'Bitrem',
            'RODOTREM' => 'Rodotrem',
            'PRANCHA' => 'Prancha',
        ] as $codigo => $descricao) {
            TipoVeiculo::updateOrCreate(['codigo' => $codigo], ['descricao' => $descricao, 'ativo' => true]);
        }

        foreach (['CARGA_GERAL' => 'Carga geral', 'GRANEL_LIQUIDO' => 'Granel líquido', 'PERIGOSA' => 'Carga perigosa'] as $codigo => $descricao) {
            TipoCargaAntt::updateOrCreate(['codigo' => $codigo], ['descricao' => $descricao, 'ativo' => true]);
        }

        foreach (['COTACAO', 'PROGRAMACAO'] as $nome) {
            DB::table('sequencia')->insertOrIgnore(['nome' => $nome, 'ultimo_valor' => 0, 'atualizado_em' => now()->format('Y-m-d H:i:s.v')]);
        }
    }
}