<?php

namespace Tests\Feature;

use App\Models\Cotacao;
use App\Models\LogOperacao;
use App\Models\Programacao;
use Tests\TestCase;

class CotacaoFluxoTest extends TestCase
{
    private function preparar(): array
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $ta = $this->transportadora('TRANSLOG');
        $tb = $this->transportadora('ROTA SUL');
        $ua = $this->usuario('translog', 'TRANSPORTADORA', $ta);
        $ub = $this->usuario('rotasul', 'TRANSPORTADORA', $tb);

        return [$admin, $vendedor, $ta, $tb, $ua, $ub, $this->cliente(), $this->produto()];
    }

    public function test_fluxo_completo_cotacao_encaminhar_responder_declinar_fechar_programar(): void
    {
        [$admin, $vendedor, $ta, $tb, $ua, $ub, $cliente, $produto] = $this->preparar();

        $c = $this->como($vendedor)->postJson('/api/cotacoes', $this->corpoCotacao($cliente, $produto))->assertCreated();
        $id = $c['data']['id'];
        $this->assertSame('ABERTA', $c['data']['status']);
        $this->assertSame('COT-000001', $c['data']['codigo']);
        $this->assertSame('ABERTA', $c['data']['negociacao']['status']);
        $this->assertSame([], $c['data']['negociacao']['respostas']);
        $this->assertSame('12000.000', $c['data']['quantidade_total_kg']);
        $this->assertSame([], $c['meta']['alertas']);

        $this->como($vendedor)->postJson("/api/cotacoes/{$id}/encaminhar", ['transportadora_ids' => [$ta->id]])->assertForbidden();

        $e = $this->como($admin)->postJson("/api/cotacoes/{$id}/encaminhar", ['transportadora_ids' => [$ta->id, $tb->id]])->assertOk();
        $this->assertSame('EM_NEGOCIACAO', $e['data']['status']);
        $this->assertCount(2, $e['data']['negociacao']['respostas']);

        $respA = collect($e['data']['negociacao']['respostas'])->firstWhere('transportadora.id', $ta->id)['id'];
        $respB = collect($e['data']['negociacao']['respostas'])->firstWhere('transportadora.id', $tb->id)['id'];

        $r = $this->como($ua)->postJson("/api/cotacoes/{$id}/respostas/{$respA}/responder", ['valor_tonelada' => '180.00', 'prazo_dias' => 3])->assertOk();
        $this->assertSame('RESPONDIDA', $r['data']['negociacao']['respostas'][0]['status']);
        $this->assertSame('2160.00', $r['data']['negociacao']['respostas'][0]['valor_frete']);

        $this->como($ub)->postJson("/api/cotacoes/{$id}/respostas/{$respB}/declinar", ['observacao' => 'sem frota'])->assertOk();

        $f = $this->como($admin)->postJson("/api/cotacoes/{$id}/fechar", [
            'transportadora_id' => $ta->id, 'valor_tonelada' => '175.00', 'prazo_dias' => 3, 'observacao' => 'negociado por telefone',
            'programacao' => ['deposito' => 'VIRTUAL', 'modalidade_frete' => 'CIF'],
        ])->assertOk();

        $this->assertSame('PROGRAMADA', $f['data']['status']);
        $this->assertSame('FECHADA', $f['data']['negociacao']['status']);
        $this->assertSame('2100.00', $f['data']['negociacao']['fechamento']['valor_frete']);
        $prog = $f['meta']['programacao'];
        $this->assertSame('CRG-000001', $prog['codigo']);
        $this->assertSame('AGUARDANDO_CARREGAMENTO', $prog['status']);
        $this->assertSame($f['data']['codigo'], $prog['cotacao']['codigo']);
        $this->assertSame($ta->id, $prog['transportadora']['id']);
        $this->assertSame('175.00', $prog['valor_tonelada']);
        $this->assertSame('2100.00', $prog['valor_total']);
        $this->assertSame($vendedor->id, $prog['representante']['id']);
        $this->assertNotNull($prog['itens'][0]['cotacao_item_id']);
        $this->assertSame($prog['codigo'], $f['data']['programacoes'][0]['codigo']);

        $this->assertSame('PROGRAMADA', Cotacao::first()->status->value);
        $this->assertSame(1, Programacao::count());
        $this->assertTrue(LogOperacao::where('evento', 'NEGOCIACAO_FECHADA')->exists());
        $this->assertTrue(LogOperacao::where('evento', 'COTACAO_STATUS')->where('status_para', 'PROGRAMADA')->exists());
    }

    public function test_transicoes_invalidas_dao_422(): void
    {
        [$admin, $vendedor, $ta, , , , $cliente, $produto] = $this->preparar();
        $id = $this->como($vendedor)->postJson('/api/cotacoes', $this->corpoCotacao($cliente, $produto))->json('data.id');
        $fechamento = ['transportadora_id' => $ta->id, 'valor_tonelada' => '175.00', 'prazo_dias' => 3, 'negociado_por_fora' => true];

        $this->como($admin)->postJson("/api/cotacoes/{$id}/fechar", $fechamento)->assertOk();
        $this->postJson("/api/cotacoes/{$id}/fechar", $fechamento)->assertStatus(422);
        $this->postJson("/api/cotacoes/{$id}/cancelar", ['motivo' => 'x'])->assertStatus(422);
        $this->postJson("/api/cotacoes/{$id}/encaminhar", ['transportadora_ids' => [$ta->id]])->assertStatus(422)
            ->assertJsonStructure(['message']);
    }

    public function test_cancelar_antes_de_programar(): void
    {
        [$admin, $vendedor, , , , , $cliente, $produto] = $this->preparar();
        $id = $this->como($vendedor)->postJson('/api/cotacoes', $this->corpoCotacao($cliente, $produto))->json('data.id');

        $r = $this->como($vendedor)->postJson("/api/cotacoes/{$id}/cancelar", ['motivo' => 'cliente desistiu'])->assertOk();
        $this->assertSame('CANCELADA', $r['data']['status']);
        $this->assertSame('CANCELADA', $r['data']['negociacao']['status']);
        $this->como($admin)->postJson("/api/cotacoes/{$id}/encaminhar", ['transportadora_ids' => []])->assertStatus(422);
    }

    public function test_encerrar_sem_fechar_mantem_respostas_e_trava_alteracoes(): void
    {
        [$admin, $vendedor, $ta, $tb, $ua, , $cliente, $produto] = $this->preparar();
        $corpo = $this->corpoCotacao($cliente, $produto);
        $id = $this->como($vendedor)->postJson('/api/cotacoes', $corpo)->json('data.id');
        $e = $this->como($admin)->postJson("/api/cotacoes/{$id}/encaminhar", ['transportadora_ids' => [$ta->id, $tb->id]])->assertOk();
        $respA = collect($e['data']['negociacao']['respostas'])->firstWhere('transportadora.id', $ta->id)['id'];
        $respB = collect($e['data']['negociacao']['respostas'])->firstWhere('transportadora.id', $tb->id)['id'];
        $this->como($ua)->postJson("/api/cotacoes/{$id}/respostas/{$respA}/responder", ['valor_tonelada' => '180.00', 'prazo_dias' => 3])->assertOk();

        $this->como($vendedor)->postJson("/api/cotacoes/{$id}/encerrar", [])->assertForbidden();

        $r = $this->como($admin)->postJson("/api/cotacoes/{$id}/encerrar", ['motivo' => 'cliente comprou de outro'])->assertOk();
        $this->assertSame('ENCERRADA', $r['data']['status']);
        $resp = collect($r['data']['negociacao']['respostas']);
        $this->assertSame('RESPONDIDA', $resp->firstWhere('id', $respA)['status']);
        $this->assertSame('2160.00', $resp->firstWhere('id', $respA)['valor_frete']);
        $this->assertSame('AGUARDANDO', $resp->firstWhere('id', $respB)['status']);
        $this->assertSame(0, Programacao::count());
        $log = LogOperacao::where('evento', 'COTACAO_ENCERRADA')->first();
        $this->assertNotNull($log);
        $this->assertSame('ENCERRADA', $log->status_para);

        $this->patchJson("/api/cotacoes/{$id}", $corpo + ['versao' => $r['data']['versao']])->assertStatus(422);
        $this->postJson("/api/cotacoes/{$id}/encaminhar", ['transportadora_ids' => [$ta->id]])->assertStatus(422);
        $this->postJson("/api/cotacoes/{$id}/fechar", ['transportadora_id' => $ta->id, 'valor_tonelada' => '175.00', 'prazo_dias' => 3])->assertStatus(422);
        $this->postJson("/api/cotacoes/{$id}/cancelar", ['motivo' => 'x'])->assertStatus(422);
        $this->postJson("/api/cotacoes/{$id}/encerrar", [])->assertStatus(422);
        $this->como($ua)->postJson("/api/cotacoes/{$id}/respostas/{$respA}/responder", ['valor_tonelada' => '150.00', 'prazo_dias' => 2])->assertStatus(422);
        $this->como($ua)->postJson("/api/cotacoes/{$id}/respostas/{$respA}/declinar", [])->assertStatus(422);
    }

    public function test_encerrar_aceita_cotacao_aberta_sem_motivo_e_recusa_fechada_ou_cancelada(): void
    {
        [$admin, $vendedor, $ta, , , , $cliente, $produto] = $this->preparar();
        $aberta = $this->como($vendedor)->postJson('/api/cotacoes', $this->corpoCotacao($cliente, $produto))->json('data.id');
        $this->como($admin)->postJson("/api/cotacoes/{$aberta}/encerrar", [])->assertOk()->assertJsonPath('data.status', 'ENCERRADA');

        $programada = $this->como($vendedor)->postJson('/api/cotacoes', $this->corpoCotacao($cliente, $produto))->json('data.id');
        $this->como($admin)->postJson("/api/cotacoes/{$programada}/fechar", ['transportadora_id' => $ta->id, 'valor_tonelada' => '175.00', 'prazo_dias' => 3, 'negociado_por_fora' => true])->assertOk();
        $this->postJson("/api/cotacoes/{$programada}/encerrar", [])->assertStatus(422);

        $cancelada = $this->como($vendedor)->postJson('/api/cotacoes', $this->corpoCotacao($cliente, $produto))->json('data.id');
        $this->postJson("/api/cotacoes/{$cancelada}/cancelar", ['motivo' => 'desistiu'])->assertOk();
        $this->como($admin)->postJson("/api/cotacoes/{$cancelada}/encerrar", [])->assertStatus(422);
    }

    public function test_versao_desatualizada_da_409(): void
    {
        [, $vendedor, , , , , $cliente, $produto] = $this->preparar();
        $corpo = $this->corpoCotacao($cliente, $produto);
        $c = $this->como($vendedor)->postJson('/api/cotacoes', $corpo)->assertCreated();
        $id = $c['data']['id'];
        $this->assertSame(0, $c['data']['versao']);

        $ok = $this->patchJson("/api/cotacoes/{$id}", $corpo + ['versao' => 0, 'observacao' => 'nova'])->assertOk();
        $this->assertSame(1, $ok['data']['versao']);
        $this->assertSame('nova', $ok['data']['observacao']);

        $this->patchJson("/api/cotacoes/{$id}", $corpo + ['versao' => 0])->assertStatus(409)->assertJsonStructure(['message']);
        $this->patchJson("/api/cotacoes/{$id}", $corpo)->assertStatus(422)->assertJsonValidationErrors('versao');
    }

    public function test_validacoes_de_embalagem_parte_e_formato_de_erro(): void
    {
        [, $vendedor, , , , , $cliente, $produto] = $this->preparar();
        $corpo = $this->corpoCotacao($cliente, $produto);

        $ruim = $corpo;
        $ruim['itens'][0]['embalagem_codigo'] = 'SACARIA';
        $this->como($vendedor)->postJson('/api/cotacoes', $ruim)->assertStatus(422)
            ->assertJsonValidationErrors('itens.0.embalagem_codigo')->assertJsonStructure(['message', 'errors']);

        $recebimento = $corpo;
        $recebimento['operacao'] = 'RECEBIMENTO';
        $this->postJson('/api/cotacoes', $recebimento)->assertStatus(422)->assertJsonValidationErrors('parte.tipo');
    }

    public function test_calculo_de_frete_usa_half_up(): void
    {
        [$admin, $vendedor, $ta, , , , $cliente, $produto] = $this->preparar();
        $corpo = $this->corpoCotacao($cliente, $produto, ['tipo_veiculo_codigo' => 'TRUCK']);
        $id = $this->como($vendedor)->postJson('/api/cotacoes', $corpo)->json('data.id');

        $r = $this->como($admin)->postJson("/api/cotacoes/{$id}/fechar", ['transportadora_id' => $ta->id, 'valor_tonelada' => '123.45', 'prazo_dias' => 2, 'negociado_por_fora' => true])->assertOk();
        $this->assertSame('1481.40', $r['data']['negociacao']['fechamento']['valor_frete']);
    }

    public function test_tipos_de_veiculo_novos_sao_aceitos_na_cotacao(): void
    {
        [, $vendedor, , , , , $cliente, $produto] = $this->preparar();

        foreach (['PICAPE', 'VUC_VAN', 'TOCO_34', 'CARRETA_LS', 'RODOTREM', 'PRANCHA'] as $codigo) {
            $c = $this->como($vendedor)->postJson('/api/cotacoes', $this->corpoCotacao($cliente, $produto, ['tipo_veiculo_codigo' => $codigo]))->assertCreated();
            $this->assertSame($codigo, $c['data']['tipo_veiculo_codigo'], "veículo {$codigo}");
        }

        $this->como($vendedor)->postJson('/api/cotacoes', $this->corpoCotacao($cliente, $produto, ['tipo_veiculo_codigo' => 'INEXISTENTE']))
            ->assertStatus(422)->assertJsonValidationErrors('tipo_veiculo_codigo');
    }
}