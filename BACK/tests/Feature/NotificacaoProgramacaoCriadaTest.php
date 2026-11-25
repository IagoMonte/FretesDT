<?php

namespace Tests\Feature;

use App\Models\Notificacao;
use App\Services\Notificacao\Whatsapp\TextoTemplate;
use App\Services\Notificacao\Whatsapp\WhatsappGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\GatewayFake;
use Tests\TestCase;

/**
 * Carga criada SEM cotação (Programação de Cargas) avisa a transportadora com o programar_pedido; o número do pedido
 * é obrigatório no fechamento e na criação; e nenhuma notificação mostra o nome do produto, só a descrição.
 */
class NotificacaoProgramacaoCriadaTest extends TestCase
{
    private GatewayFake $wa;

    private $admin;

    private $ta;

    private $tb;

    private $cliente;

    private $produto;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->wa = new GatewayFake;
        $this->app->instance(WhatsappGateway::class, $this->wa);
        config(['notificacoes.front_url' => 'https://fretes.example.test']);

        $this->admin = $this->usuario('admin', 'ADMINISTRADOR');
        $this->admin->update(['email' => 'admin@example.test', 'telefone' => '(19) 99999-0010']);
        $this->ta = $this->transportadora('TRANSLOG');
        $this->ta->update(['email' => 'translog@t.test', 'telefone' => '(19) 99999-0001']);
        $this->tb = $this->transportadora('ROTA SUL');
        $this->tb->update(['email' => 'via@t.test', 'telefone' => '(19) 99999-0002']);

        $this->cliente = $this->cliente('Indústria Exemplo Ltda');
        $this->cliente->update(['cnpj' => '12345678000190']);
        $this->produto = $this->produto('Soda cáustica');
        $this->produto->update(['descricao' => 'Líquido alcalino', 'onu' => '1824', 'risco' => '80']);
    }

    private function carga(array $extra = [], bool $granel = false): array
    {
        $item = ['produto_id' => $this->produto->id, 'embalagem_codigo' => $granel ? 'GRANEL_LIQUIDO' : 'BOMBONA', 'quantidade_kg' => '27000'];
        if (! $granel) {
            $item['volumes'] = 540;
        }

        return $extra + [
            'operacao' => 'CARREGAMENTO',
            'parte' => ['tipo' => 'CLIENTE', 'id' => $this->cliente->id],
            'pedido_venda' => 'PV-4455',
            'deposito' => 'FISICO', 'modalidade_frete' => 'FOB',
            'transportadora_id' => $this->ta->id,
            'tipo_veiculo_codigo' => 'TRUCK',
            'origem' => ['cidade' => 'Santos', 'uf' => 'SP'],
            'destino' => ['cidade' => 'Campinas', 'uf' => 'SP'],
            'valor_tonelada' => '240.00',
            'data_coleta_prevista' => now()->addDays(3)->toDateString(), 'data_entrega_prevista' => now()->addDays(5)->toDateString(),
            'itens' => [$item],
        ];
    }

    private function criar(array $extra = [], bool $granel = false): \Illuminate\Testing\TestResponse
    {
        return $this->como($this->admin)->postJson('/api/programacoes', $this->carga($extra, $granel));
    }


    public function test_criar_carga_sem_pedido_da_422(): void
    {
        $this->semPedidoAutomatico = true;

        $corpo = $this->carga();
        unset($corpo['pedido_venda']);
        $this->como($this->admin)->postJson('/api/programacoes', $corpo)->assertStatus(422)->assertJsonValidationErrors('pedido_venda');
        $this->como($this->admin)->postJson('/api/programacoes', ['pedido_venda' => '  '] + $corpo)->assertStatus(422);
        $this->assertSame(0, DB::table('programacao')->count());
    }

    public function test_editar_carga_antiga_sem_pedido_continua_possivel(): void
    {
        $id = $this->criar()->assertCreated()['data']['id'];
        DB::table('programacao')->where('id', $id)->update(['pedido_venda' => null]);
        $versao = DB::table('programacao')->where('id', $id)->value('versao');

        $this->como($this->admin)->patchJson("/api/programacoes/{$id}", ['observacao' => 'ajuste', 'versao' => $versao])->assertOk();
    }

    public function test_fechar_sem_pedido_da_422_e_com_pedido_grava_na_carga(): void
    {
        $this->semPedidoAutomatico = true;
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $id = $this->como($vendedor)->postJson('/api/cotacoes', $this->corpoCotacao($this->cliente, $this->produto))->assertCreated()['data']['id'];
        $base = ['transportadora_id' => $this->ta->id, 'valor_tonelada' => '175.00', 'prazo_dias' => 3, 'negociado_por_fora' => true];

        foreach ([null, ['modalidade_frete' => 'FOB'], ['pedido_venda' => ''], ['pedido_venda' => str_repeat('9', 31)]] as $programacao) {
            $corpo = $programacao === null ? $base : $base + ['programacao' => $programacao];
            $this->como($this->admin)->postJson("/api/cotacoes/{$id}/fechar", $corpo)->assertStatus(422);
        }
        $this->assertSame(0, DB::table('programacao')->count());

        $r = $this->como($this->admin)->postJson("/api/cotacoes/{$id}/fechar", $base + ['programacao' => ['pedido_venda' => 'PV-9001']])->assertOk();
        $this->assertSame('PV-9001', $r['meta']['programacao']['pedido_venda']);
    }


    public function test_carga_sem_cotacao_manda_programar_pedido_para_a_transportadora(): void
    {
        $this->criar()->assertCreated();

        [, $template, $params] = $this->wa->para('5519999990001');
        $this->assertSame('programar_pedido', $template);
        $this->assertCount(8, $params);
        $this->assertSame('PV-4455', $params[0]);
        $this->assertSame('Indústria Exemplo Ltda, CNPJ 12.345.678/0001-90', $params[1]);
        $this->assertSame('FOB', $params[2]);
        $this->assertSame('Truck de Líquido alcalino: ONU:1824 - RISCO:80', $params[3]);
        $this->assertSame('SANTOS/SP x CAMPINAS/SP', $params[4]);
        $this->assertSame('R$ 6.480,00', $params[5]);
        $this->assertSame(now()->addDays(3)->format('d/m/Y').' e '.now()->addDays(5)->format('d/m/Y'), $params[6]);
        $this->assertMatchesRegularExpression('#^https://fretes.example.test/loads\?abrir=CRG-\d{6}$#', $params[7]);
        $this->assertSame(1, DB::table('notificacao')->where('evento', 'PROGRAMACAO_CRIADA')->where('canal', 'WHATSAPP')->where('grupo_codigo', 'TRANSPORTADORA')->count());
    }

    public function test_carga_granel_sem_cotacao_manda_por_tonelada(): void
    {
        $this->criar([], granel: true)->assertCreated();

        $this->assertSame('R$ 240,00 por tonelada', $this->wa->para('5519999990001', 'programar_pedido')[2][5]);
    }

    public function test_so_a_transportadora_recebe_whatsapp_e_os_internos_so_email(): void
    {
        $this->criar()->assertCreated();

        $linhas = Notificacao::where('evento', 'PROGRAMACAO_CRIADA')->get();
        $this->assertSame(['TRANSPORTADORA'], $linhas->where('canal', 'WHATSAPP')->pluck('grupo_codigo')->unique()->values()->all());
        $this->assertEqualsCanonicalizing(['TRANSPORTADORA', 'VENDEDOR'], $linhas->where('canal', 'EMAIL')->pluck('grupo_codigo')->unique()->values()->all());
        $this->assertContains('admin@example.test', $linhas->where('canal', 'EMAIL')->pluck('endereco')->all());
        $this->assertNull($this->wa->para('5519999990010'), 'o admin não recebe WhatsApp deste evento');
        $this->assertSame(['translog@t.test'], $linhas->where('canal', 'EMAIL')->where('grupo_codigo', 'TRANSPORTADORA')->pluck('endereco')->all());
    }

    public function test_carga_sem_transportadora_nao_emite(): void
    {
        $this->criar(['transportadora_id' => null])->assertCreated();

        $this->assertSame(0, Notificacao::where('evento', 'PROGRAMACAO_CRIADA')->count());
        $this->assertSame([], $this->wa->enviados);
    }

    public function test_carga_do_fechamento_nao_emite_programacao_criada(): void
    {
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $id = $this->como($vendedor)->postJson('/api/cotacoes', $this->corpoCotacao($this->cliente, $this->produto))->assertCreated()['data']['id'];

        $this->como($this->admin)->postJson("/api/cotacoes/{$id}/fechar", [
            'transportadora_id' => $this->ta->id, 'valor_tonelada' => '175.00', 'prazo_dias' => 3, 'negociado_por_fora' => true,
            'programacao' => ['pedido_venda' => 'PV-1', 'modalidade_frete' => 'CIF'],
        ])->assertOk();

        $this->assertSame(0, Notificacao::where('evento', 'PROGRAMACAO_CRIADA')->count());
        $this->assertSame(1, Notificacao::where('evento', 'NEGOCIACAO_FECHADA')->where('canal', 'WHATSAPP')->where('grupo_codigo', 'TRANSPORTADORA')->count());
    }

    public function test_texto_enviado_pela_evolution_traz_pedido_e_descricao(): void
    {
        $this->criar()->assertCreated();

        [, $template, $params] = $this->wa->para('5519999990001');
        $texto = TextoTemplate::renderizar($template, $params);
        $this->assertStringContainsString('Favor programar pedido: PV-4455,', $texto);
        $this->assertStringContainsString('Carga: Truck de Líquido alcalino: ONU:1824 - RISCO:80', $texto);
    }


    public function test_nenhuma_notificacao_traz_o_nome_do_produto_so_a_descricao(): void
    {
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $vendedor->update(['email' => 'marcos@example.test', 'telefone' => '(19) 99999-0020']);
        $id = $this->como($vendedor)->postJson('/api/cotacoes', $this->corpoCotacao($this->cliente, $this->produto))->assertCreated()['data']['id'];
        $resp = $this->como($this->admin)->postJson("/api/cotacoes/{$id}/encaminhar", ['transportadora_ids' => [$this->ta->id, $this->tb->id]])->assertOk()['data']['negociacao']['respostas'];
        $ua = $this->usuario('translog', 'TRANSPORTADORA', $this->ta);
        $r = collect($resp)->firstWhere('transportadora.id', $this->ta->id)['id'];
        $this->como($ua)->postJson("/api/cotacoes/{$id}/respostas/{$r}/responder", ['valor_tonelada' => '180.00', 'prazo_dias' => 3])->assertOk();
        $prog = $this->como($this->admin)->postJson("/api/cotacoes/{$id}/fechar", [
            'transportadora_id' => $this->ta->id, 'valor_tonelada' => '175.00', 'prazo_dias' => 3,
            'programacao' => ['pedido_venda' => 'PV-1', 'modalidade_frete' => 'CIF'],
        ])->assertOk()['meta']['programacao']['id'];
        $this->como($ua)->postJson("/api/programacoes/{$prog}/status", ['status' => 'EM_TRANSITO', 'data' => '2026-10-06'])->assertOk();
        $this->como($this->admin)->postJson("/api/programacoes/{$prog}/cancelar", ['motivo' => 'erro'])->assertOk();
        $this->criar()->assertCreated();
        $this->criar([], granel: true)->assertCreated();

        $registros = Notificacao::all();
        $this->assertGreaterThan(8, $registros->count());
        foreach ($registros as $n) {
            $this->assertStringNotContainsString('Soda cáustica', (string) $n->payload, "{$n->evento}/{$n->canal}");
        }
        foreach ($this->wa->enviados as [, $template, $params]) {
            $this->assertStringNotContainsString('Soda cáustica', TextoTemplate::renderizar($template, $params), $template);
        }
        $this->assertStringContainsString('Líquido alcalino', TextoTemplate::renderizar(...array_slice($this->wa->para('5519999990001', 'programar_pedido'), 1)));
        Mail::assertSent(\App\Mail\NegociacaoFechada::class, function ($m) {
            $this->assertStringNotContainsString('Soda cáustica', $m->render());

            return true;
        });
    }

    public function test_sem_descricao_usa_o_nome_como_ultimo_recurso(): void
    {
        $this->produto->update(['descricao' => null]);

        $this->criar()->assertCreated();

        $this->assertSame('Truck de Soda cáustica: ONU:1824 - RISCO:80', $this->wa->para('5519999990001', 'programar_pedido')[2][3]);
    }

    public function test_onu_ou_risco_ausentes_nao_deixam_rotulo_solto(): void
    {
        $this->produto->update(['risco' => null]);
        $this->criar()->assertCreated();
        $this->assertSame('Truck de Líquido alcalino: ONU:1824', $this->wa->para('5519999990001', 'programar_pedido')[2][3]);

        $this->wa->enviados = [];
        $this->produto->update(['onu' => null, 'risco' => '80']);
        $this->criar()->assertCreated();
        $this->assertSame('Truck de Líquido alcalino: RISCO:80', $this->wa->para('5519999990001', 'programar_pedido')[2][3]);

        $this->wa->enviados = [];
        $this->produto->update(['onu' => null, 'risco' => null]);
        $this->criar()->assertCreated();
        $this->assertSame('Truck de Líquido alcalino', $this->wa->para('5519999990001', 'programar_pedido')[2][3]);
    }
}
