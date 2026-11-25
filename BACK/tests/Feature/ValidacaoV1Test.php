<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Correções da validação v1. */
class ValidacaoV1Test extends TestCase
{
    private function corpoCotacaoKg(string $kg): array
    {
        $c = $this->corpoCotacao($this->cliente(), $this->produto());
        $c['itens'][0]['quantidade_kg'] = $kg;

        return $c;
    }


    public function test_c2_cotacao_aceita_ate_999_milhoes_de_kg_e_recusa_acima(): void
    {
        $vendedor = $this->usuario('marcos', 'VENDEDOR');

        $this->como($vendedor)->postJson('/api/cotacoes', $this->corpoCotacaoKg('1000000000'))
            ->assertStatus(422)->assertJsonValidationErrors('itens.0.quantidade_kg');
        $this->postJson('/api/cotacoes', $this->corpoCotacaoKg('999999999999'))
            ->assertStatus(422)->assertJsonValidationErrors('itens.0.quantidade_kg');

        $r = $this->postJson('/api/cotacoes', $this->corpoCotacaoKg('999999999'))->assertCreated();
        $this->assertSame('999999999.000', $r['data']['quantidade_total_kg']);
    }

    public function test_c2_programacao_avulsa_tem_o_mesmo_teto(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $corpo = fn (string $kg) => [
            'operacao' => 'CARREGAMENTO',
            'parte' => ['tipo' => 'CLIENTE', 'id' => $this->cliente()->id],
            'deposito' => 'FISICO', 'modalidade_frete' => 'FOB',
            'tipo_veiculo_codigo' => 'TRUCK',
            'origem' => ['cidade' => 'Santos', 'uf' => 'SP'],
            'destino' => ['cidade' => 'Campinas', 'uf' => 'SP'],
            'valor_tonelada' => '1.00',
            'data_entrega_prevista' => now()->addDays(5)->toDateString(),
            'itens' => [['produto_id' => $this->produto()->id, 'embalagem_codigo' => 'BOMBONA', 'quantidade_kg' => $kg]],
        ];

        $this->como($admin)->postJson('/api/programacoes', $corpo('1000000000'))
            ->assertStatus(422)->assertJsonValidationErrors('itens.0.quantidade_kg');
        $this->postJson('/api/programacoes', $corpo('999999999'))->assertCreated();
    }


    protected function setUp(): void
    {
        parent::setUp();
        config(['frete.idempotencia_segundos' => 10]);
    }

    public function test_c6_envios_identicos_em_sequencia_criam_uma_cotacao_so(): void
    {
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $corpo = $this->corpoCotacao($this->cliente(), $this->produto());

        $ids = [];
        foreach (range(1, 3) as $_) {
            $ids[] = $this->como($vendedor)->postJson('/api/cotacoes', $corpo)->assertSuccessful()['data']['id'];
        }

        $this->assertCount(1, array_unique($ids));
        $this->assertDatabaseCount('cotacao', 1);
    }

    public function test_c6_corpo_diferente_ou_outro_vendedor_ou_depois_da_janela_cria_nova(): void
    {
        $marcos = $this->usuario('marcos', 'VENDEDOR');
        $ana = $this->usuario('ana', 'VENDEDOR');
        $corpo = $this->corpoCotacao($this->cliente(), $this->produto());

        $this->como($marcos)->postJson('/api/cotacoes', $corpo)->assertCreated();
        $this->postJson('/api/cotacoes', ['valor_nf' => '1234.56'] + $corpo)->assertCreated();
        $this->como($ana)->postJson('/api/cotacoes', $corpo)->assertCreated();
        $this->assertDatabaseCount('cotacao', 3);

        $this->travel(30)->seconds();
        $this->como($marcos)->postJson('/api/cotacoes', $corpo)->assertCreated();
        $this->assertDatabaseCount('cotacao', 4);
    }

    public function test_c6_chave_de_idempotencia_do_cliente_vale_mesmo_com_corpo_alterado(): void
    {
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $corpo = $this->corpoCotacao($this->cliente(), $this->produto());
        $h = ['Idempotency-Key' => 'abc-123'];

        $a = $this->como($vendedor)->postJson('/api/cotacoes', $corpo, $h)->assertCreated()['data']['id'];
        $b = $this->postJson('/api/cotacoes', ['valor_nf' => '999.00'] + $corpo, $h)->assertSuccessful()['data']['id'];

        $this->assertSame($a, $b);
        $this->assertDatabaseCount('cotacao', 1);
    }


    public function test_c7_status_invalido_dos_filtros_de_cotacao_e_carga_da_422(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $t = $this->transportadora('TRANSLOG');
        $ut = $this->usuario('translog', 'TRANSPORTADORA', $t);

        foreach (['XXX', 'ABERTA,XXX', 'aberta', 'DROP TABLE'] as $s) {
            $this->como($admin)->getJson('/api/cotacoes?status='.urlencode($s))->assertStatus(422)->assertJsonValidationErrors('status');
            $this->getJson('/api/programacoes?status='.urlencode($s))->assertStatus(422)->assertJsonValidationErrors('status');
            $this->como($ut)->getJson('/api/cotacoes?status='.urlencode($s))->assertStatus(422)->assertJsonValidationErrors('status');
        }

        $this->como($admin)->getJson('/api/cotacoes?status=ABERTA,ENCERRADA')->assertOk();
        $this->getJson('/api/cotacoes?status=')->assertOk();
        $this->getJson('/api/programacoes?status=AGUARDANDO_CARREGAMENTO,DEVOLVIDO')->assertOk();
        $this->como($ut)->getJson('/api/cotacoes?status=RESPONDIDA,PROGRAMADA')->assertOk();
    }


    public function test_c8_busca_trata_percentual_e_sublinhado_como_texto(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $this->como($admin)->postJson('/api/cotacoes', $this->corpoCotacao($this->cliente(), $this->produto()))->assertCreated();

        foreach (['%', '_', '%%', 'a%b', '100%'] as $termo) {
            $this->getJson('/api/cotacoes?termo='.urlencode($termo))->assertOk()->assertJsonPath('meta.total', 0);
            $this->getJson('/api/programacoes?termo='.urlencode($termo))->assertOk()->assertJsonPath('meta.total', 0);
        }
        $this->getJson('/api/cotacoes?termo='.urlencode('   '))->assertOk()->assertJsonPath('meta.total', 1);
    }


    public function test_c9_mensagens_de_validacao_usam_rotulos_em_portugues(): void
    {
        $msg = fn (string $campo, string $regra, $valor) => validator([$campo => $valor], [$campo => $regra])->errors()->first($campo);
        $aninhado = fn (array $dados, array $regras) => validator($dados, $regras)->errors()->first();

        $casos = [
            $aninhado(['fatores' => ['BB200L' => -5]], ['fatores.*' => 'numeric|gt:0']) => 'kg por volume',
            $aninhado(['fatores_palete' => ['BB200L' => -5]], ['fatores_palete.*' => 'numeric|gt:0']) => 'kg por palete',
            $aninhado(['programacao' => []], ['programacao.pedido_venda' => 'required']) => 'número do pedido',
            $aninhado(['programacao' => ['pedido_venda' => str_repeat('x', 31)]], ['programacao.pedido_venda' => 'max:30']) => 'número do pedido',
            $aninhado(['programacao' => ['modalidade_frete' => 'ZZZ']], ['programacao.modalidade_frete' => 'in:CIF,FOB']) => 'modalidade de frete',
            $aninhado(['itens' => [['volumes' => -1]]], ['itens.*.volumes' => 'integer|min:0']) => 'volumes',
            $aninhado(['parte' => []], ['parte.tipo' => 'required']) => 'tipo da parte',
            $aninhado(['parte' => []], ['parte.id' => 'required']) => 'cliente ou fornecedor',
            $msg('codigo', 'max:3', 'abcdef') => 'código',
            $msg('ccd', 'numeric|min:5', '1') => 'CCD',
            $msg('cc', 'numeric|min:5', '1') => 'CC',
            $msg('tipo_carga_codigo', 'in:A', 'B') => 'tipo de carga',
            $msg('representante_id', 'in:A', 'B') => 'representante',
            $msg('pedido_venda', 'required', null) => 'número do pedido',
        ];

        foreach ($casos as $mensagem => $esperado) {
            $this->assertStringContainsString($esperado, $mensagem);
            $this->assertDoesNotMatchRegularExpression('/\w+\.\w+|(itens|fatores|programacao|parte)\.|[a-z]+_[a-z]+/i', $mensagem, $mensagem);
        }
    }


    private function corpoAvulsa(array $extra = []): array
    {
        return $extra + [
            'operacao' => 'CARREGAMENTO',
            'parte' => ['tipo' => 'CLIENTE', 'id' => $this->cliente()->id],
            'pedido_venda' => 'PV-1',
            'deposito' => 'FISICO', 'modalidade_frete' => 'FOB',
            'tipo_veiculo_codigo' => 'TRUCK',
            'origem' => ['cidade' => 'Santos', 'uf' => 'SP'],
            'destino' => ['cidade' => 'Campinas', 'uf' => 'SP'],
            'valor_tonelada' => '10.00',
            'data_entrega_prevista' => now()->addDays(9)->toDateString(),
            'itens' => [['produto_id' => $this->produto()->id, 'embalagem_codigo' => 'BOMBONA', 'quantidade_kg' => '1000']],
        ];
    }

    public function test_c12_carga_avulsa_recusa_prazo_zero_e_coleta_no_passado(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');

        $this->como($admin)->postJson('/api/programacoes', $this->corpoAvulsa(['prazo_dias' => 0]))
            ->assertStatus(422)->assertJsonValidationErrors('prazo_dias');
        $this->postJson('/api/programacoes', $this->corpoAvulsa(['data_coleta_prevista' => '2020-01-01']))
            ->assertStatus(422)->assertJsonValidationErrors('data_coleta_prevista');
        $this->postJson('/api/programacoes', $this->corpoAvulsa(['data_coleta_prevista' => now()->subDay()->toDateString()]))
            ->assertStatus(422)->assertJsonValidationErrors('data_coleta_prevista');
        $this->assertDatabaseCount('programacao', 0);

        $this->postJson('/api/programacoes', $this->corpoAvulsa(['prazo_dias' => 1, 'data_coleta_prevista' => now()->toDateString()]))->assertCreated();
        $this->postJson('/api/programacoes', $this->corpoAvulsa(['prazo_dias' => 5, 'data_coleta_prevista' => now()->addDays(3)->toDateString()]))->assertCreated();
    }

    public function test_c12_editar_carga_antiga_com_coleta_passada_continua_permitido(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $c = $this->como($admin)->postJson('/api/programacoes', $this->corpoAvulsa(['data_coleta_prevista' => now()->toDateString()]))->assertCreated()['data'];

        $this->travel(5)->days();
        $this->patchJson("/api/programacoes/{$c['id']}", ['observacao' => 'ajuste', 'versao' => $c['versao']])->assertOk();
    }
}
