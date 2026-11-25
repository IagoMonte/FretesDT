<?php

namespace Tests\Feature;

use App\Models\AnttCoeficiente;
use App\Models\Cotacao;
use App\Models\Embalagem;
use Tests\TestCase;

/** Fracionada: sem carro na criação; a transportadora responde com o carro e o valor TOTAL. Granel: um produto só. */
class CotacaoFracionadaTest extends TestCase
{
    private function corpoFracionada($cliente, $produto): array
    {
        Embalagem::where('codigo', 'BOMBONA')->update(['fracionada' => true]);

        return $this->corpoCotacao($cliente, $produto, ['tipo_veiculo_codigo' => null]);
    }

    public function test_fracionada_nasce_sem_carro_e_a_transportadora_responde_com_carro_e_valor_total(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $ta = $this->transportadora('TRANSLOG');
        $ua = $this->usuario('translog', 'TRANSPORTADORA', $ta);
        $cliente = $this->cliente();
        $produto = $this->produto();

        $c = $this->como($vendedor)->postJson('/api/cotacoes', $this->corpoFracionada($cliente, $produto))->assertCreated();
        $id = $c['data']['id'];
        $this->assertTrue($c['data']['fracionada']);
        $this->assertNull($c['data']['tipo_veiculo_codigo']);

        $e = $this->como($admin)->postJson("/api/cotacoes/{$id}/encaminhar", ['transportadora_ids' => [$ta->id]])->assertOk();
        $resp = $e['data']['negociacao']['respostas'][0]['id'];

        $this->como($ua)->postJson("/api/cotacoes/{$id}/respostas/{$resp}/responder", ['valor_tonelada' => '180', 'prazo_dias' => 3, 'prazo_coleta_dias' => 1])->assertStatus(422)->assertJsonValidationErrors('valor_frete');
        $this->postJson("/api/cotacoes/{$id}/respostas/{$resp}/responder", ['valor_frete' => '2500', 'prazo_dias' => 3, 'prazo_coleta_dias' => 1])->assertStatus(422)->assertJsonValidationErrors('tipo_veiculo_codigo');

        $r = $this->postJson("/api/cotacoes/{$id}/respostas/{$resp}/responder", ['valor_frete' => '2500.00', 'tipo_veiculo_codigo' => 'TRUCK', 'prazo_dias' => 3, 'prazo_coleta_dias' => 1])->assertOk();
        $resposta = $r['data']['negociacao']['respostas'][0];
        $this->assertSame('2500.00', $resposta['valor_frete']);
        $this->assertSame('TRUCK', $resposta['tipo_veiculo_codigo']);
        $this->assertSame('208.33', $resposta['valor_tonelada']);

        $f = $this->como($admin)->postJson("/api/cotacoes/{$id}/fechar", [
            'transportadora_id' => $ta->id, 'valor_frete' => '2400.00', 'prazo_dias' => 3, 'prazo_coleta_dias' => 1, 'observacao' => 'negociado',
        ])->assertOk();
        $this->assertSame('2400.00', $f['data']['negociacao']['fechamento']['valor_frete']);
        $this->assertSame('TRUCK', $f['data']['negociacao']['fechamento']['tipo_veiculo_codigo']);
        $prog = $f['meta']['programacao'];
        $this->assertSame('2400.00', $prog['valor_total']);
        $this->assertSame('TRUCK', $prog['tipo_veiculo_codigo']);
        $this->assertTrue(Cotacao::first()->fracionada);
    }

    /** Cria a cotação, encaminha e devolve [id, id da resposta, usuário da transportadora]. */
    private function cotacaoParaResponder(array $extra, bool $fracionada): array
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $ta = $this->transportadora('TRANSLOG');
        $ua = $this->usuario('translog', 'TRANSPORTADORA', $ta);
        AnttCoeficiente::create([
            'tipo_carga_codigo' => 'CARGA_GERAL', 'eixos' => 5, 'ccd' => '5.0000', 'cc' => '500.00', 'vigencia_inicio' => '2026-01-01', 'ativo' => true,
        ]);
        AnttCoeficiente::create([
            'tipo_carga_codigo' => 'PERIGOSA', 'eixos' => 5, 'ccd' => '6.0000', 'cc' => '700.00', 'vigencia_inicio' => '2026-01-01', 'ativo' => true,
        ]);
        $corpo = $fracionada
            ? $this->corpoFracionada($this->cliente(), $this->produto())
            : $this->corpoCotacao($this->cliente(), $this->produto());
        $c = $this->como($vendedor)->postJson('/api/cotacoes', $extra + $corpo + ['tipo_carga_antt_codigo' => 'CARGA_GERAL', 'distancia_km' => 100])->assertCreated();
        $e = $this->como($admin)->postJson("/api/cotacoes/{$c['data']['id']}/encaminhar", ['transportadora_ids' => [$ta->id]])->assertOk();

        return [$c, $e['data']['negociacao']['respostas'][0]['id'], $ua, $admin];
    }

    public function test_dedicado_nao_e_campo_da_cotacao_e_so_vale_para_fracionada(): void
    {
        [$c, $resp, $ua] = $this->cotacaoParaResponder(['dedicado' => true], false);
        $this->assertArrayNotHasKey('dedicado', $c['data']);
        $this->assertFalse(Cotacao::first()->dedicado);

        $r = $this->como($ua)->postJson("/api/cotacoes/{$c['data']['id']}/respostas/{$resp}/responder", [
            'valor_tonelada' => '180.00', 'prazo_dias' => 3, 'eixos' => 5, 'dedicado' => true,
        ])->assertOk();
        $resposta = $r['data']['negociacao']['respostas'][0];
        $this->assertFalse($resposta['dedicado']);
        $this->assertTrue($resposta['piso_valido']);
        $this->assertSame(5, $resposta['eixos']);
    }

    public function test_fracionada_nao_dedicada_nao_tem_frete_minimo_nem_eixos_nem_tipo_de_carga(): void
    {
        [$c, $resp, $ua, $admin] = $this->cotacaoParaResponder([], true);
        $id = $c['data']['id'];
        $this->assertArrayNotHasKey('dedicado', $c['data']);

        $r = $this->como($ua)->postJson("/api/cotacoes/{$id}/respostas/{$resp}/responder", [
            'valor_frete' => '800.00', 'tipo_veiculo_codigo' => 'TRUCK', 'prazo_dias' => 3, 'prazo_coleta_dias' => 1, 'eixos' => 5, 'tipo_carga_antt_codigo' => 'PERIGOSA',
        ])->assertOk();
        $resposta = $r['data']['negociacao']['respostas'][0];
        $this->assertFalse($resposta['dedicado']);
        $this->assertFalse($resposta['piso_valido']);
        $this->assertNull($resposta['eixos']);
        $this->assertNull($resposta['tipo_carga_antt_codigo']);

        $v = $this->como($admin)->getJson("/api/cotacoes/{$id}")->assertOk();
        $this->assertNull($v['data']['negociacao']['respostas'][0]['antt']);
    }

    public function test_fracionada_dedicada_tem_frete_minimo_com_o_tipo_de_carga_escolhido(): void
    {
        [$c, $resp, $ua, $admin] = $this->cotacaoParaResponder([], true);
        $id = $c['data']['id'];

        $r = $this->como($ua)->postJson("/api/cotacoes/{$id}/respostas/{$resp}/responder", [
            'valor_frete' => '1000.00', 'tipo_veiculo_codigo' => 'TRUCK', 'prazo_dias' => 3, 'prazo_coleta_dias' => 1, 'eixos' => 5, 'tipo_carga_antt_codigo' => 'PERIGOSA',
            'dedicado' => true,
        ])->assertOk();
        $resposta = $r['data']['negociacao']['respostas'][0];
        $this->assertTrue($resposta['dedicado']);
        $this->assertTrue($resposta['piso_valido']);
        $this->assertSame('PERIGOSA', $resposta['tipo_carga_antt_codigo']);

        $v = $this->como($admin)->getJson("/api/cotacoes/{$id}")->assertOk();
        $this->assertSame('1300.00', $v['data']['negociacao']['respostas'][0]['antt']['piso']);
    }

    public function test_so_a_transportadora_enxerga_o_campo_dedicado(): void
    {
        [$c, $resp, $ua, $admin] = $this->cotacaoParaResponder([], true);
        $id = $c['data']['id'];
        $this->como($ua)->postJson("/api/cotacoes/{$id}/respostas/{$resp}/responder", [
            'valor_frete' => '1000.00', 'tipo_veiculo_codigo' => 'TRUCK', 'prazo_dias' => 3, 'prazo_coleta_dias' => 1, 'eixos' => 5, 'tipo_carga_antt_codigo' => 'PERIGOSA',
            'dedicado' => true,
        ])->assertOk();

        $minha = $this->como($ua)->getJson("/api/cotacoes/{$id}")->assertOk()['data']['negociacao']['respostas'][0];
        $this->assertTrue($minha['dedicado']);

        $interna = $this->como($admin)->getJson("/api/cotacoes/{$id}")->assertOk()['data']['negociacao']['respostas'][0];
        $this->assertArrayNotHasKey('dedicado', $interna);
        $this->assertTrue($interna['piso_valido']);
        $this->assertNotNull($interna['antt']);
    }

    public function test_fechamento_guarda_o_dedicado_da_resposta_vencedora(): void
    {
        [$c, $resp, $ua, $admin] = $this->cotacaoParaResponder([], true);
        $id = $c['data']['id'];
        $this->como($ua)->postJson("/api/cotacoes/{$id}/respostas/{$resp}/responder", [
            'valor_frete' => '1000.00', 'tipo_veiculo_codigo' => 'TRUCK', 'prazo_dias' => 3, 'prazo_coleta_dias' => 1, 'eixos' => 5, 'tipo_carga_antt_codigo' => 'PERIGOSA',
            'dedicado' => true,
        ])->assertOk();

        $ta = $ua->transportadora_id;
        $f = $this->como($admin)->postJson("/api/cotacoes/{$id}/fechar", ['transportadora_id' => $ta, 'valor_frete' => '1400.00', 'prazo_dias' => 3, 'prazo_coleta_dias' => 1])->assertOk();
        $fech = $f['data']['negociacao']['fechamento'];
        $this->assertTrue($fech['piso_valido']);
        $this->assertSame('1300.00', $fech['antt']['piso']);
        $this->assertArrayNotHasKey('dedicado', $fech);
    }

    public function test_tipo_de_carga_inexistente_na_resposta_da_422(): void
    {
        [$c, $resp, $ua] = $this->cotacaoParaResponder([], true);

        $this->como($ua)->postJson("/api/cotacoes/{$c['data']['id']}/respostas/{$resp}/responder", [
            'valor_frete' => '1000.00', 'tipo_veiculo_codigo' => 'TRUCK', 'prazo_dias' => 3, 'prazo_coleta_dias' => 1, 'eixos' => 5, 'tipo_carga_antt_codigo' => 'XXX',
            'dedicado' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('tipo_carga_antt_codigo');
    }

    public function test_cotacao_comum_continua_exigindo_o_carro_e_o_valor_por_tonelada(): void
    {
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $cliente = $this->cliente();
        $produto = $this->produto();

        $this->como($vendedor)->postJson('/api/cotacoes', $this->corpoCotacao($cliente, $produto, ['tipo_veiculo_codigo' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('tipo_veiculo_codigo');
    }

    public function test_granel_aceita_um_produto_so_por_cotacao(): void
    {
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $cliente = $this->cliente();
        $a = $this->produto('SODA');
        $b = $this->produto('ACIDO');
        $itens = [
            ['produto_id' => $a->id, 'embalagem_codigo' => 'GRANEL_LIQUIDO', 'quantidade_kg' => '10000'],
            ['produto_id' => $b->id, 'embalagem_codigo' => 'GRANEL_LIQUIDO', 'quantidade_kg' => '5000'],
        ];

        $this->como($vendedor)->postJson('/api/cotacoes', $this->corpoCotacao($cliente, $a, ['itens' => $itens]))
            ->assertStatus(422)->assertJsonValidationErrors('itens');
        $this->postJson('/api/cotacoes', $this->corpoCotacao($cliente, $a, ['itens' => [$itens[0]]]))->assertCreated();
        $this->postJson('/api/cotacoes', $this->corpoCotacao($cliente, $a, ['itens' => [$itens[0], ['produto_id' => $b->id, 'embalagem_codigo' => 'BOMBONA', 'quantidade_kg' => '500']]]))
            ->assertStatus(422);
    }
}
