<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProgramacaoTest extends TestCase
{
    private function corpo(array $extra = []): array
    {
        $cliente = $this->cliente();
        $produto = $this->produto();

        return $extra + [
            'operacao' => 'CARREGAMENTO',
            'parte' => ['tipo' => 'CLIENTE', 'id' => $cliente->id],
            'deposito' => 'FISICO', 'modalidade_frete' => 'FOB',
            'tipo_veiculo_codigo' => 'TRUCK',
            'origem' => ['cidade' => 'Santos', 'uf' => 'SP'],
            'destino' => ['cidade' => 'Campinas', 'uf' => 'SP'],
            'valor_tonelada' => '240.00',
            'data_entrega_prevista' => now()->addDays(5)->toDateString(),
            'itens' => [['produto_id' => $produto->id, 'embalagem_codigo' => 'BOMBONA', 'quantidade_kg' => '27000']],
        ];
    }

    public function test_carga_avulsa_calcula_valor_total_e_percorre_o_ciclo_de_status(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $t = $this->transportadora('TRANSLOG');
        $ut = $this->usuario('translog', 'TRANSPORTADORA', $t);

        $r = $this->como($admin)->postJson('/api/programacoes', $this->corpo(['transportadora_id' => $t->id]))->assertCreated();
        $id = $r['data']['id'];
        $this->assertSame('AGUARDANDO_CARREGAMENTO', $r['data']['status']);
        $this->assertSame('6480.00', $r['data']['valor_total']);
        $this->assertNull($r['data']['cotacao']);
        $this->assertSame([], $r['meta']['alertas']);

        $this->postJson("/api/programacoes/{$id}/status", ['status' => 'ENTREGUE'])->assertStatus(422);
        $this->postJson("/api/programacoes/{$id}/entregar", [])->assertStatus(422);

        $transito = $this->como($ut)->postJson("/api/programacoes/{$id}/status", ['status' => 'EM_TRANSITO', 'data' => '2026-10-06'])->assertOk();
        $this->assertSame('2026-10-06', $transito['data']['data_coleta_realizada']);
        $e = $this->postJson("/api/programacoes/{$id}/entregar")->assertOk();
        $this->assertSame('ENTREGUE', $e['data']['status']);
        $this->assertTrue($e['data']['entregue_no_prazo']);

        $this->como($admin)->postJson("/api/programacoes/{$id}/cancelar", ['motivo' => 'x'])->assertStatus(422);
        $this->postJson("/api/programacoes/{$id}/status", ['status' => 'DEVOLVIDO'])->assertOk();
    }

    public function test_devolver_carga_entregue_e_so_de_quem_altera_programacao(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $t = $this->transportadora('TRANSLOG');
        $ut = $this->usuario('translog', 'TRANSPORTADORA', $t);

        $id = $this->como($admin)->postJson('/api/programacoes', $this->corpo(['transportadora_id' => $t->id]))->assertCreated()['data']['id'];
        $this->como($ut)->postJson("/api/programacoes/{$id}/status", ['status' => 'EM_TRANSITO'])->assertOk();
        $this->postJson("/api/programacoes/{$id}/entregar")->assertOk();

        $this->postJson("/api/programacoes/{$id}/status", ['status' => 'DEVOLVIDO'])->assertForbidden();
        $this->como($admin)->getJson("/api/programacoes/{$id}")->assertOk()->assertJsonPath('data.status', 'ENTREGUE');

        $this->postJson("/api/programacoes/{$id}/status", ['status' => 'DEVOLVIDO'])->assertOk()->assertJsonPath('data.status', 'DEVOLVIDO');
    }

    public function test_programacao_ignora_paletizado_e_remontado_dos_itens(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $corpo = $this->corpo();
        $corpo['itens'][0]['paletizado'] = true;
        $corpo['itens'][0]['remontado'] = true;

        $r = $this->como($admin)->postJson('/api/programacoes', $corpo)->assertCreated();
        $item = $r['data']['itens'][0];
        $this->assertArrayNotHasKey('paletizado', $item);
        $this->assertArrayNotHasKey('remontado', $item);
        $this->assertSame(1, \DB::table('programacao_item')->count());
    }

    public function test_carga_com_representante_id_null_usa_o_usuario_logado(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $r = $this->como($admin)->postJson('/api/programacoes', $this->corpo(['representante_id' => null]))->assertCreated();
        $this->assertDatabaseHas('programacao', ['id' => $r['data']['id'], 'representante_id' => $admin->id]);
    }

    public function test_valor_total_arredonda_half_up(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $corpo = $this->corpo(['valor_tonelada' => '0.05']);
        $corpo['itens'][0]['quantidade_kg'] = '100';

        $this->como($admin)->postJson('/api/programacoes', $corpo)->assertCreated()->assertJsonPath('data.valor_total', '0.01');
    }

    public function test_alerta_quando_a_quantidade_programada_passa_da_cotada(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $t = $this->transportadora('TRANSLOG');
        $cliente = $this->cliente();
        $produto = $this->produto();
        $cot = $this->como($vendedor)->postJson('/api/cotacoes', $this->corpoCotacao($cliente, $produto))->json('data');
        $this->como($admin)->postJson("/api/cotacoes/{$cot['id']}/fechar", ['transportadora_id' => $t->id, 'valor_tonelada' => '100', 'prazo_dias' => 2, 'negociado_por_fora' => true])->assertOk();

        $corpo = $this->corpo();
        $corpo['parte'] = ['tipo' => 'CLIENTE', 'id' => $cliente->id];
        $corpo['itens'] = [['produto_id' => $produto->id, 'embalagem_codigo' => 'BOMBONA', 'quantidade_kg' => '5000', 'cotacao_item_id' => $cot['itens'][0]['id']]];

        $r = $this->como($admin)->postJson('/api/programacoes', $corpo)->assertCreated();
        $this->assertCount(1, $r['meta']['alertas']);
        $this->assertStringContainsString('17000.000', $r['meta']['alertas'][0]);
        $this->assertStringContainsString($cot['codigo'], $r['meta']['alertas'][0]);
        $this->assertSame($cot['codigo'], $r['data']['cotacao']['codigo']);
        $this->assertDatabaseHas('log_operacao', ['evento' => 'PROGRAMACAO_QTD_EXCEDIDA']);

        $this->postJson("/api/programacoes/{$r['data']['id']}/cancelar", ['motivo' => 'erro'])->assertOk()->assertJsonPath('data.status', 'CANCELADO');
    }

    public function test_patch_com_versao_e_escopo_do_vendedor(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $marcos = $this->usuario('marcos', 'VENDEDOR');
        $lucia = $this->usuario('lucia', 'VENDEDOR');

        $r = $this->como($admin)->postJson('/api/programacoes', $this->corpo(['representante_id' => $marcos->id]))->assertCreated();
        $id = $r['data']['id'];

        $p = $this->patchJson("/api/programacoes/{$id}", ['versao' => 0, 'pedido_venda' => 'PV-9', 'valor_tonelada' => '100.00'])->assertOk();
        $this->assertSame('PV-9', $p['data']['pedido_venda']);
        $this->assertSame('2700.00', $p['data']['valor_total']);
        $this->assertSame(1, $p['data']['versao']);
        $this->patchJson("/api/programacoes/{$id}", ['versao' => 0, 'pedido_venda' => 'PV-10'])->assertStatus(409);

        $this->como($marcos)->getJson("/api/programacoes/{$id}")->assertOk();
        $this->como($lucia)->getJson("/api/programacoes/{$id}")->assertNotFound();
        $this->getJson('/api/programacoes')->assertJsonPath('meta.total', 0);
    }

    public function test_painel_conta_por_status(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $id = $this->como($admin)->postJson('/api/programacoes', $this->corpo())->json('data.id');
        $this->postJson("/api/programacoes/{$id}/status", ['status' => 'EM_TRANSITO'])->assertOk();

        $d = $this->getJson('/api/painel')->assertOk()['data'];
        $this->assertSame(1, $d['kpis']['cargas']);
        $this->assertSame(1, $d['kpis']['em_transito']);
        $this->assertSame(1, $d['por_status']['EM_TRANSITO']);
        $this->assertSame('6480.00', $d['kpis']['total_frete']);
        $this->assertSame(1, $d['cargas']['total']);
    }
}