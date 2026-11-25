<?php

namespace Tests\Feature\Admin;

use App\Services\Admin\AnaliseCustoService;
use App\Services\Admin\AnaliseGeralService;
use App\Services\Admin\AnaliseTransportadoraService;
use App\Services\Admin\AnaliseVendedorService;
use App\Support\Periodo;
use Tests\Support\CenarioAdmin;
use Tests\TestCase;

/** Números do /admin conferidos à mão contra o cenário de CenarioAdmin. */
class AnaliseServicesTest extends TestCase
{
    use CenarioAdmin;

    private Periodo $p;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $this->p = Periodo::entre('2026-10-01', '2026-10-31');
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_periodo_anterior_tem_o_mesmo_tamanho_e_termina_na_vespera(): void
    {
        $a = $this->p->anterior();
        $this->assertSame('2026-08-31', $a->de->toDateString());
        $this->assertSame('2026-09-30', $a->ate->toDateString());
        $this->assertSame('2026-10-08', Periodo::dias(30)->ate->toDateString());
        $this->assertSame('2026-09-09', Periodo::dias(30)->de->toDateString());
    }

    public function test_periodo_a_partir_da_requisicao(): void
    {
        $this->assertSame('2026-10-01', Periodo::daRequisicao(['de' => '2026-10-01', 'ate' => '2026-10-31'])->de->toDateString());
        $this->assertSame(90, Periodo::daRequisicao(['periodo' => '90'])->dias);
        $this->assertSame(30, Periodo::daRequisicao([])->dias);
        $this->assertSame(30, Periodo::daRequisicao(['periodo' => 'lixo'])->dias);
    }

    public function test_kpis_gerais(): void
    {
        $k = app(AnaliseGeralService::class)->kpis($this->p);

        $this->assertSame(4, $k['cotacoes_total']);
        $this->assertSame(['ABERTA' => 1, 'EM_NEGOCIACAO' => 0, 'FECHADA' => 0, 'PROGRAMADA' => 2, 'CANCELADA' => 1, 'ENCERRADA' => 0], $k['por_status']);
        $this->assertEquals(50.0, $k['conversao_pct']);
        $this->assertEquals(0.0, $k['saving_total']);
        $this->assertEquals(2500.0, $k['frete_total']);
        $this->assertEquals(30.0, $k['toneladas']);
        $this->assertEquals(83.33, $k['frete_t_medio']);
        $this->assertEquals(50.0, $k['pct_no_prazo']);
        $this->assertEquals(2.0, $k['atraso_medio_dias']);
        $this->assertEquals(30.0, $k['tempo_fechamento_horas']);
        $this->assertEquals(0.0, $k['saving_pct']);
    }

    public function test_kpis_respeitam_o_periodo(): void
    {
        $k = app(AnaliseGeralService::class)->kpis(Periodo::entre('2026-10-03', '2026-10-31'));
        $this->assertSame(2, $k['cotacoes_total']);
        $this->assertEquals(0.0, $k['saving_total']);
        $this->assertEquals(0.0, $k['conversao_pct']);
    }

    public function test_alertas_usam_a_data_atual(): void
    {
        $a = app(AnaliseGeralService::class)->alertas();
        $this->assertSame(1, $a['cotacoes_paradas']);
        $this->assertSame(1, $a['respostas_vencidas']);
        $this->assertSame(1, $a['entregas_atrasadas']);
    }

    public function test_serie_mensal_e_top_rotas(): void
    {
        $serie = app(AnaliseGeralService::class)->serieMensal($this->p);
        $out = $this->linha($serie, 'mes', '2026-10');
        $this->assertSame(4, $out['cotacoes']);
        $this->assertEquals(2500.0, $out['frete_total']);
        $this->assertEquals(0.0, $out['saving']);

        $rotas = app(AnaliseGeralService::class)->topRotas($this->p, 5);
        $sp = $this->linha($rotas, 'rota', 'SP → SP');
        $this->assertSame(2, $sp['cotacoes']);
        $this->assertEquals(15.0, $sp['toneladas']);
        $this->assertSame('SP → MG', $rotas[1]['rota']);
    }

    public function test_ranking_de_transportadoras(): void
    {
        $r = app(AnaliseTransportadoraService::class)->ranking($this->p);

        $a = $this->linha($r, 'nome', 'TRANSLOG');
        $this->assertSame(2, $a['respondidas']);
        $this->assertSame(1, $a['vitorias']);
        $this->assertEquals(50.0, $a['taxa_vitoria']);
        $this->assertEquals(80.0, $a['min']);
        $this->assertEquals(100.0, $a['max']);
        $this->assertEquals(90.0, $a['media']);
        $this->assertEquals(0.0, $a['saving']);
        $this->assertEquals(10.0, $a['gap_medio']);
        $this->assertEquals(3.0, $a['prazo_medio']);
        $this->assertEquals(3.0, $a['tempo_resposta_horas']);
        $this->assertEquals(1600.0, $a['frete_contratado']);
        $this->assertEquals(0.0, $a['pct_no_prazo']);

        $b = $this->linha($r, 'nome', 'ROTASUL');
        $this->assertEquals(92.5, $b['media']);
        $this->assertEquals(15.0, $b['gap_medio']);
        $this->assertEquals(100.0, $b['pct_no_prazo']);

        $c = $this->linha($r, 'nome', 'SEMPRE');
        $this->assertSame(1, $c['respondidas']);
        $this->assertSame(1, $c['declinadas']);
        $this->assertSame(0, $c['vitorias']);
        $this->assertEquals(30.0, $c['gap_medio']);
        $this->assertEquals(10.0, $c['tempo_resposta_horas']);
        $this->assertEquals(50.0, $c['taxa_declinio']);
    }

    public function test_mercado_e_matriz_por_produto(): void
    {
        $svc = app(AnaliseTransportadoraService::class);

        $m = $this->linha($svc->mercadoPorProduto($this->p), 'produto', 'SODA');
        $this->assertEquals(80.0, $m['min']);
        $this->assertEquals(120.0, $m['max']);
        $this->assertEquals(97.0, $m['media']);
        $this->assertSame(5, $m['respostas']);

        $celulas = collect($svc->matrizProduto($this->p))->where('produto', 'SODA');
        $a = $celulas->firstWhere('transportadora', 'TRANSLOG');
        $this->assertEquals(80.0, $a['min']);
        $this->assertEquals(100.0, $a['max']);
        $this->assertEquals(90.0, $a['media']);
        $this->assertSame(3, $celulas->count());
    }

    public function test_onde_ganha_e_onde_perde(): void
    {
        $svc = app(AnaliseTransportadoraService::class);
        $linhas = collect($svc->ganhaPerde($this->p, 'rota'))->where('transportadora', 'TRANSLOG');

        $spsp = $linhas->firstWhere('chave', 'SP → SP');
        $this->assertSame(0, $spsp['vitorias']);
        $this->assertSame(1, $spsp['derrotas']);
        $this->assertEquals(10.0, $spsp['gap_medio']);
        $this->assertEquals(11.11, $spsp['gap_pct']);

        $spmg = $linhas->firstWhere('chave', 'SP → MG');
        $this->assertSame(1, $spmg['vitorias']);
        $this->assertSame(0, $spmg['derrotas']);
        $this->assertEquals(15.0, $spmg['margem_media']);

        $porProduto = collect($svc->ganhaPerde($this->p, 'produto'))->where('transportadora', 'ROTASUL')->firstWhere('chave', 'SODA');
        $this->assertSame(1, $porProduto['vitorias']);
        $this->assertSame(1, $porProduto['derrotas']);

        $this->assertNotEmpty($svc->ganhaPerde($this->p, 'veiculo'));
    }

    public function test_oportunidades_de_base_de_custo(): void
    {
        $o = app(AnaliseTransportadoraService::class)->oportunidades($this->p);
        $poucos = collect($o['poucos_concorrentes']);
        $this->assertTrue($poucos->contains(fn ($l) => $l['rota'] === 'SP → MG' && $l['respostas'] === 2));
        $this->assertFalse($poucos->contains(fn ($l) => $l['rota'] === 'SP → SP'));
        $this->assertSame([], $o['fora_do_menor']);
        $disp = collect($o['dispersao'])->firstWhere('produto', 'SODA');
        $this->assertEquals(40.0, $disp['amplitude']);
    }

    public function test_detalhe_da_transportadora(): void
    {
        $d = app(AnaliseTransportadoraService::class)->detalhe($this->tA->id, $this->p);
        $this->assertSame('TRANSLOG', $d['nome']);
        $this->assertSame(1, $d['vitorias']);
        $this->assertNotEmpty($d['ganha_perde_rota']);
        $this->assertNotEmpty($d['por_produto']);
        $this->assertCount(2, $d['ultimas_respostas']);
    }

    public function test_ranking_de_vendedores(): void
    {
        $r = app(AnaliseVendedorService::class)->ranking($this->p);

        $p = $this->linha($r, 'nome', 'Marcos');
        $this->assertSame(2, $p['cotacoes']);
        $this->assertEquals(100.0, $p['taxa_fechamento']);
        $this->assertEquals(0.0, $p['taxa_cancelamento']);
        $this->assertEquals(0.0, $p['saving']);
        $this->assertEquals(30.0, $p['toneladas']);
        $this->assertEquals(2500.0, $p['frete_total']);
        $this->assertEquals(83.33, $p['frete_t_medio']);
        $this->assertEquals(30.0, $p['tempo_fechamento_horas']);
        $this->assertSame(2, $p['cargas']);
        $this->assertEquals(30000.0, $p['valor_nf']);

        $c = $this->linha($r, 'nome', 'Lucia');
        $this->assertSame(2, $c['cotacoes']);
        $this->assertEquals(0.0, $c['taxa_fechamento']);
        $this->assertEquals(50.0, $c['taxa_cancelamento']);
        $this->assertEquals(0.0, $c['saving']);
    }

    public function test_detalhe_do_vendedor(): void
    {
        $d = app(AnaliseVendedorService::class)->detalhe($this->marcos->id, $this->p);
        $this->assertSame('Marcos', $d['nome']);
        $this->assertSame('Cliente Um', $d['top_clientes'][0]['cliente']);
        $this->assertSame(['CARREGAMENTO' => 2, 'RECEBIMENTO' => 0], $d['mix_operacao']);
        $this->assertCount(7, $d['por_dia_semana']);
    }

    public function test_custos_por_produto_rota_e_veiculo(): void
    {
        $svc = app(AnaliseCustoService::class);

        $s = $this->linha($svc->porProduto($this->p), 'produto', 'SODA');
        $this->assertSame(2, $s['fechamentos']);
        $this->assertEquals(80.0, $s['min']);
        $this->assertEquals(90.0, $s['max']);
        $this->assertEquals(85.0, $s['media']);
        $this->assertEquals(30.0, $s['toneladas']);
        $this->assertEquals(8.33, $s['frete_sobre_nf_pct']);

        $this->assertEquals(90.0, $this->linha($svc->porRota($this->p), 'rota', 'SP → SP')['media']);
        $this->assertEquals(80.0, $this->linha($svc->porRota($this->p), 'rota', 'SP → MG')['media']);
        $this->assertSame(2, $this->linha($svc->porVeiculo($this->p), 'veiculo', 'CARRETA')['fechamentos']);
    }

    public function test_cotacao_repetida_usa_as_respostas_da_original_sem_duplicar(): void
    {
        $this->cot[5] = \App\Models\Cotacao::create([
            'codigo' => 'COT-000005', 'data_solicitacao' => '2026-10-05', 'vendedor_id' => $this->marcos->id, 'operacao' => 'CARREGAMENTO',
            'cliente_id' => $this->cot[1]->cliente_id, 'tipo_veiculo_codigo' => 'CARRETA', 'origem_cidade' => 'Origem', 'origem_uf' => 'SP',
            'destino_cidade' => 'Destino', 'destino_uf' => 'SP', 'valor_nf' => '1.00', 'status' => 'FECHADA', 'cotacao_origem_id' => $this->cot[1]->id,
        ]);
        \App\Models\CotacaoItem::create(['cotacao_id' => $this->cot[5]->id, 'sequencia' => 1, 'produto_id' => $this->soda->id, 'embalagem_codigo' => 'GRANEL_LIQUIDO', 'quantidade_kg' => 10000, 'volumes' => 1]);
        \App\Models\Negociacao::create(['cotacao_id' => $this->cot[5]->id, 'status' => 'FECHADA', 'transportadora_id' => $this->tC->id, 'valor_tonelada' => '120.00', 'valor_frete' => '1200.00', 'prazo_dias' => 5, 'observacao_fechamento' => 'ok', 'fechado_em' => '2026-10-05 10:00:00', 'fechado_por_id' => $this->admin->id]);

        $r = app(AnaliseTransportadoraService::class)->ranking($this->p);
        $this->assertSame(1, $this->linha($r, 'nome', 'SEMPRE')['respondidas']);
        $this->assertSame(1, $this->linha($r, 'nome', 'SEMPRE')['vitorias']);
        $this->assertEquals(300.0, $this->linha($r, 'nome', 'SEMPRE')['saving']);
    }

    public function test_saving_e_fechado_menos_menor_resposta_e_pode_ser_negativo(): void
    {
        $c6 = \App\Models\Cotacao::create([
            'codigo' => 'COT-000006', 'data_solicitacao' => '2026-10-06', 'vendedor_id' => $this->marcos->id, 'operacao' => 'CARREGAMENTO',
            'cliente_id' => $this->cot[2]->cliente_id, 'tipo_veiculo_codigo' => 'CARRETA', 'origem_cidade' => 'Origem', 'origem_uf' => 'SP',
            'destino_cidade' => 'Destino', 'destino_uf' => 'MG', 'valor_nf' => '1.00', 'status' => 'FECHADA', 'cotacao_origem_id' => $this->cot[2]->id,
        ]);
        \App\Models\CotacaoItem::create(['cotacao_id' => $c6->id, 'sequencia' => 1, 'produto_id' => $this->soda->id, 'embalagem_codigo' => 'GRANEL_LIQUIDO', 'quantidade_kg' => 20000, 'volumes' => 1]);
        \App\Models\Negociacao::create(['cotacao_id' => $c6->id, 'status' => 'FECHADA', 'transportadora_id' => $this->tC->id, 'valor_tonelada' => '75.00', 'valor_frete' => '1500.00', 'prazo_dias' => 5, 'observacao_fechamento' => 'ok', 'fechado_em' => '2026-10-06 10:00:00', 'fechado_por_id' => $this->admin->id]);

        $k = app(AnaliseGeralService::class)->kpis($this->p);
        $this->assertEquals(-100.0, $k['saving_total']);
        $this->assertLessThan(0.0, $k['saving_pct']);
        $this->assertEquals(-100.0, $this->linha(app(AnaliseVendedorService::class)->ranking($this->p), 'nome', 'Marcos')['saving']);
        $this->assertEquals(-100.0, $this->linha(app(AnaliseTransportadoraService::class)->ranking($this->p), 'nome', 'SEMPRE')['saving']);
    }

    public function test_banco_vazio_nao_quebra(): void
    {
        \Illuminate\Support\Facades\DB::table('negociacao_resposta')->delete();
        \Illuminate\Support\Facades\DB::table('programacao_item')->delete();
        \Illuminate\Support\Facades\DB::table('programacao')->delete();
        \Illuminate\Support\Facades\DB::table('negociacao')->delete();
        \Illuminate\Support\Facades\DB::table('cotacao_item')->delete();
        \Illuminate\Support\Facades\DB::table('cotacao')->delete();

        $k = app(AnaliseGeralService::class)->kpis($this->p);
        $this->assertSame(0, $k['cotacoes_total']);
        $this->assertEquals(0.0, $k['conversao_pct']);
        $this->assertEquals(0.0, $k['frete_t_medio']);
        $this->assertSame(0, array_sum(array_column(app(AnaliseVendedorService::class)->ranking($this->p), 'cotacoes')));
        $this->assertSame(0, array_sum(array_column(app(AnaliseTransportadoraService::class)->ranking($this->p), 'respondidas')));
    }
}
