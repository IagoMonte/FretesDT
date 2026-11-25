<?php

namespace Tests\Feature;

use App\Models\Programacao;
use App\Models\ProgramacaoItem;
use App\Models\Transportadora;
use App\Models\Usuario;
use Tests\TestCase;

/** Painel Operacional: GET /api/painel com filtros, KPIs, pizza de status, rankings e tabela de cargas. */
class PainelTest extends TestCase
{
    private static int $seq = 0;

    private Usuario $admin;

    private Transportadora $trdlog;

    private Transportadora $videira;

    private Usuario $utrd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->usuario('admin', 'ADMINISTRADOR');
        $this->trdlog = $this->transportadora('TRDLOG');
        $this->videira = $this->transportadora('VIDEIRA');
        $this->utrd = $this->usuario('trdlog', 'TRANSPORTADORA', $this->trdlog);
    }

    /** Cria a carga direto no banco (as datas passadas não passam pela validação da API). */
    private function carga(array $o = []): Programacao
    {
        $o += [
            'status' => 'AGUARDANDO_CARREGAMENTO', 'modalidade' => 'CIF', 'transp' => $this->trdlog,
            'kg' => '10000', 'vt' => '300.00', 'coleta' => now()->addDays(3)->toDateString(),
            'entrega_prevista' => null, 'entrega_realizada' => null, 'destino' => 'Campinas', 'uf' => 'SP',
            'produto' => null, 'emb' => 'BOMBONA', 'deposito' => 'FISICO', 'rep' => $this->admin,
        ];
        $produto = $o['produto'] ?? $this->produto('SODA 32%');
        $p = Programacao::create([
            'codigo' => 'PRG-'.str_pad((string) ++self::$seq, 5, '0', STR_PAD_LEFT), 'operacao' => 'CARREGAMENTO',
            'status' => $o['status'], 'data_pedido' => now()->subDays(2)->toDateString(),
            'representante_id' => $o['rep']->id, 'cliente_id' => $this->cliente('CAMBUI')->id,
            'deposito' => $o['deposito'], 'modalidade_frete' => $o['modalidade'], 'transportadora_id' => $o['transp']?->id,
            'origem_cidade' => 'Dumont', 'origem_uf' => 'SP', 'destino_cidade' => $o['destino'], 'destino_uf' => $o['uf'],
            'valor_tonelada' => $o['vt'], 'valor_total' => bcmul($o['vt'], bcdiv($o['kg'], '1000', 6), 2),
            'data_coleta_prevista' => $o['coleta'], 'data_entrega_prevista' => $o['entrega_prevista'],
            'data_entrega_realizada' => $o['entrega_realizada'],
        ]);
        ProgramacaoItem::create(['programacao_id' => $p->id, 'sequencia' => 1, 'produto_id' => $produto->id, 'embalagem_codigo' => $o['emb'], 'quantidade_kg' => $o['kg']]);

        return $p;
    }

    public function test_kpis_pizza_e_prazo(): void
    {
        $hoje = now()->toDateString();
        $ontem = now()->subDay()->toDateString();
        $this->carga(['status' => 'ENTREGUE', 'coleta' => now()->subDays(9)->toDateString(), 'entrega_prevista' => now()->subDays(5)->toDateString(), 'entrega_realizada' => now()->subDays(6)->toDateString()]);
        $this->carga(['status' => 'ENTREGUE', 'coleta' => now()->subDays(9)->toDateString(), 'entrega_prevista' => now()->subDays(5)->toDateString(), 'entrega_realizada' => now()->subDays(4)->toDateString()]);
        $this->carga(['status' => 'EM_TRANSITO', 'coleta' => $ontem, 'entrega_prevista' => $ontem]);
        $this->carga(['status' => 'AGUARDANDO_CARREGAMENTO', 'coleta' => $hoje, 'modalidade' => 'FOB']);
        $this->carga(['status' => 'DEVOLVIDO', 'coleta' => now()->subDays(20)->toDateString()]);
        $this->carga(['status' => 'CANCELADO']);

        $d = $this->como($this->admin)->getJson('/api/painel')->assertOk()['data'];

        $this->assertSame(5, $d['kpis']['cargas']);
        $this->assertSame(1, $d['kpis']['aguardando_carregamento']);
        $this->assertSame(1, $d['kpis']['em_transito']);
        $this->assertSame(2, $d['kpis']['entregues']);
        $this->assertSame(1, $d['kpis']['devolvidas']);
        $this->assertSame(2, $d['kpis']['no_prazo']);
        $this->assertSame(2, $d['kpis']['atrasadas']);
        $this->assertSame(50, $d['kpis']['pct_no_prazo']);
        $this->assertSame(4, $d['kpis']['cif']);
        $this->assertSame(1, $d['kpis']['fob']);
        $this->assertSame('15000.00', $d['kpis']['total_frete']);
        $this->assertSame('3000.00', $d['kpis']['ticket_medio']);
        $this->assertSame('50000.000', $d['kpis']['volume_kg']);
        $this->assertSame(['EM_COTACAO' => 0, 'AGUARDANDO_CARREGAMENTO' => 1, 'EM_TRANSITO' => 1, 'ENTREGUE' => 2, 'DEVOLVIDO' => 1], $d['por_status']);
        $this->assertSame(5, $d['cargas']['total']);
    }

    public function test_filtros_reduzem_kpis_mas_a_pizza_ignora_o_filtro_de_status(): void
    {
        $this->carga(['status' => 'EM_TRANSITO', 'modalidade' => 'CIF', 'destino' => 'Limeira', 'uf' => 'SP']);
        $this->carga(['status' => 'AGUARDANDO_CARREGAMENTO', 'modalidade' => 'FOB', 'destino' => 'Itumbiara', 'uf' => 'GO']);
        $this->carga(['status' => 'AGUARDANDO_CARREGAMENTO', 'modalidade' => 'CIF', 'destino' => 'Itumbiara', 'uf' => 'GO']);

        $api = $this->como($this->admin);
        $this->assertSame(1, $api->getJson('/api/painel?frete=FOB')['data']['kpis']['cargas']);
        $this->assertSame(2, $api->getJson('/api/painel?destino='.urlencode('Itumbiara - GO'))['data']['kpis']['cargas']);

        $d = $api->getJson('/api/painel?status=EM_TRANSITO')['data'];
        $this->assertSame(1, $d['cargas']['total']);
        $this->assertSame(1, $d['kpis']['cargas']);
        $this->assertSame(2, $d['por_status']['AGUARDANDO_CARREGAMENTO']);
    }

    public function test_filtro_de_ano_e_mes_usa_a_data_de_coleta(): void
    {
        $this->carga(['coleta' => '2025-03-10', 'status' => 'ENTREGUE', 'entrega_prevista' => '2025-03-12', 'entrega_realizada' => '2025-03-12']);
        $this->carga(['coleta' => '2026-03-10', 'status' => 'ENTREGUE', 'entrega_prevista' => '2026-03-12', 'entrega_realizada' => '2026-03-12']);

        $api = $this->como($this->admin);
        $this->assertSame(1, $api->getJson('/api/painel?ano=2025')['data']['kpis']['cargas']);
        $this->assertSame(2, $api->getJson('/api/painel?mes=3')['data']['kpis']['cargas']);
        $this->assertSame(0, $api->getJson('/api/painel?ano=2025&mes=4')['data']['kpis']['cargas']);
        $this->assertSame([2025, 2026], $api->getJson('/api/painel')['data']['opcoes']['anos']);
    }

    public function test_ranking_de_transportadoras_so_conta_frete_com_valor(): void
    {
        $this->carga(['transp' => $this->trdlog, 'vt' => '300.00']);
        $this->carga(['transp' => $this->trdlog, 'vt' => '300.00']);
        $this->carga(['transp' => $this->videira, 'vt' => '500.00']);
        $this->carga(['transp' => $this->videira, 'modalidade' => 'FOB', 'vt' => '0.00']);

        $r = $this->como($this->admin)->getJson('/api/painel')['data']['ranking_transportadoras'];

        $this->assertSame(['TRDLOG', 'VIDEIRA'], array_column($r, 'nome'));
        $this->assertSame([2, 1], array_column($r, 'pedidos'));
        $this->assertSame(['6000.00', '5000.00'], array_column($r, 'total'));
    }

    public function test_ranking_de_transportadoras_limita_a_cinco_pelas_de_maior_valor(): void
    {
        foreach (range(1, 7) as $i) {
            $this->carga(['transp' => $this->transportadora("RANK{$i}"), 'vt' => (string) (100 * $i).'.00']);
        }

        $r = $this->como($this->admin)->getJson('/api/painel')['data']['ranking_transportadoras'];

        $this->assertSame(['RANK7', 'RANK6', 'RANK5', 'RANK4', 'RANK3'], array_column($r, 'nome'));
    }

    public function test_tabela_de_cargas_pagina_busca_e_traz_as_colunas_do_mock(): void
    {
        foreach (range(1, 30) as $i) {
            $this->carga(['coleta' => now()->addDays($i)->toDateString()]);
        }
        $api = $this->como($this->admin);

        $p1 = $api->getJson('/api/painel?por_pagina=25')['data']['cargas'];
        $this->assertSame(30, $p1['total']);
        $this->assertCount(25, $p1['itens']);
        $this->assertCount(5, $api->getJson('/api/painel?por_pagina=25&pagina=2')['data']['cargas']['itens']);
        $this->assertSame('300000.000', $p1['total_kg']);

        $l = $p1['itens'][0];
        foreach (['id', 'codigo', 'data', 'deposito', 'pedido', 'vendedor', 'produto', 'kg', 'embalagem', 'cliente', 'origem', 'destino', 'frete', 'valor_tonelada', 'valor_total', 'transportadora', 'status', 'prazo'] as $campo) {
            $this->assertArrayHasKey($campo, $l, $campo);
        }
        $this->assertGreaterThan($p1['itens'][1]['data'], $l['data'] . 'x');
        $this->assertSame('SODA 32%', $l['produto']);
        $this->assertSame('CAMBUI', $l['cliente']);
        $this->assertSame('Campinas - SP', $l['destino']);

        $this->assertSame(0, $api->getJson('/api/painel?busca=zzz')['data']['cargas']['total']);
        $this->assertSame(30, $api->getJson('/api/painel?busca=cambui')['data']['cargas']['total']);
    }

    public function test_transportadora_ve_so_o_que_e_dela_e_sem_dados_internos(): void
    {
        $this->carga(['transp' => $this->trdlog]);
        $this->carga(['transp' => $this->trdlog, 'status' => 'EM_TRANSITO', 'destino' => 'Limeira']);
        $this->carga(['transp' => $this->videira]);

        $d = $this->como($this->utrd)->getJson('/api/painel')->assertOk()['data'];

        $this->assertSame(2, $d['kpis']['cargas']);
        $this->assertSame(2, $d['cargas']['total']);
        $this->assertArrayNotHasKey('vendedor', $d['cargas']['itens'][0]);
        $this->assertArrayNotHasKey('transportadora', $d['cargas']['itens'][0]);
        $this->assertArrayNotHasKey('ranking_transportadoras', $d);
        $this->assertSame(['Campinas - SP', 'Limeira - SP'], collect($d['ranking_destinos'])->pluck('destino')->sort()->values()->all());
        $this->assertSame([['id' => $this->trdlog->id, 'nome' => 'TRDLOG']], $d['opcoes']['transportadoras'] ?? [['id' => $this->trdlog->id, 'nome' => 'TRDLOG']]);
    }

    public function test_ranking_de_destinos_limita_a_oito(): void
    {
        foreach (range(1, 10) as $i) {
            $this->carga(['transp' => $this->trdlog, 'destino' => "Cidade {$i}"]);
        }

        $this->assertCount(8, $this->como($this->utrd)->getJson('/api/painel')['data']['ranking_destinos']);
    }

    public function test_transportadora_tem_acesso_ao_painel_e_vendedor_nao(): void
    {
        $this->como($this->utrd)->getJson('/api/painel')->assertOk();
        $vend = $this->usuario('vend', 'VENDEDOR');
        $this->como($vend)->getJson('/api/painel')->assertForbidden();
    }

    public function test_sem_token_devolve_401(): void
    {
        $this->getJson('/api/painel')->assertUnauthorized();
    }

    public function test_filtros_invalidos_sao_rejeitados(): void
    {
        $api = $this->como($this->admin);
        $api->getJson('/api/painel?frete=XYZ')->assertStatus(422);
        $api->getJson('/api/painel?mes=13')->assertStatus(422);
        $api->getJson('/api/painel?status=CANCELADO')->assertStatus(422);
    }

    public function test_migration_da_permissao_do_painel_da_transportadora_e_idempotente_e_libera_o_acesso(): void
    {
        $g = \Illuminate\Support\Facades\DB::table('grupo')->where('codigo', 'TRANSPORTADORA')->value('id');
        \Illuminate\Support\Facades\DB::table('grupo_permissao')->where(['grupo_id' => $g, 'recurso_codigo' => 'PAINEL'])->delete();
        $this->como($this->utrd)->getJson('/api/painel')->assertForbidden();

        $migration = require database_path('migrations/2026_11_23_000034_transportadora_ve_painel.php');
        $migration->up();
        $migration->up();
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('grupo_permissao')->where(['grupo_id' => $g, 'recurso_codigo' => 'PAINEL'])->count());
        $this->como($this->utrd)->getJson('/api/painel')->assertOk();
    }
}
