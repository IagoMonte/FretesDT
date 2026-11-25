<?php

namespace Tests\Feature;

use App\Models\Notificacao;
use App\Services\Notificacao\Whatsapp\TextoTemplate;
use App\Services\Notificacao\Whatsapp\WhatsappGateway;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class GatewayValorFake implements WhatsappGateway
{
    /** @var array<int, array{0: string, 1: string, 2: array}> */
    public array $enviados = [];

    public function enviar(string $para, string $template, array $params): string
    {
        $this->enviados[] = [$para, $template, $params];

        return 'wamid.'.count($this->enviados);
    }

    /** @return array{0: string, 1: string, 2: array}|null */
    public function para(string $telefone, ?string $template = null): ?array
    {
        return collect($this->enviados)->first(fn ($e) => $e[0] === $telefone && ($template === null || $e[1] === $template));
    }
}

/**
 * Valor das mensagens (e-mail e WhatsApp): valor cheio (frete total) por padrão; R$/t só em cotação 100% granel.
 * Fechamento: a transportadora vencedora recebe `programar_pedido`; vendedor, admin e gestor seguem com `frete_fechado`.
 */
class NotificacaoValorTemplateTest extends TestCase
{
    private GatewayValorFake $wa;

    private $admin;

    private $vendedor;

    private $ta;

    private $ua;

    private $produto;

    private $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->wa = new GatewayValorFake;
        $this->app->instance(WhatsappGateway::class, $this->wa);
        config(['notificacoes.front_url' => 'https://fretes.example.test']);

        $this->admin = $this->usuario('admin', 'ADMINISTRADOR');
        $this->admin->update(['telefone' => '(19) 99999-0010', 'email' => 'admin@example.test']);
        $this->vendedor = $this->usuario('marcos', 'VENDEDOR');
        $this->vendedor->update(['telefone' => '(19) 99999-0020', 'email' => 'marcos@example.test']);
        $this->ta = $this->transportadora('TRANSLOG');
        $this->ta->update(['email' => 'translog@t.test', 'telefone' => '(19) 99999-0001']);
        $this->ua = $this->usuario('translog', 'TRANSPORTADORA', $this->ta);

        $this->cliente = $this->cliente('Indústria Exemplo Ltda');
        $this->cliente->update(['cnpj' => '12345678000190']);
        $this->produto = $this->produto('Soda cáustica');
        $this->produto->update(['onu' => '1824', 'risco' => '80']);
    }

    private function cotacao(bool $granel = false): string
    {
        $corpo = $this->corpoCotacao($this->cliente, $this->produto);
        if ($granel) {
            $corpo['itens'] = [['produto_id' => $this->produto->id, 'embalagem_codigo' => 'GRANEL_LIQUIDO', 'quantidade_kg' => '12000', 'volumes' => null]];
        }

        return $this->como($this->vendedor)->postJson('/api/cotacoes', $corpo)->assertCreated()['data']['id'];
    }

    private function responder(string $cotacaoId, string $valorTonelada): void
    {
        $resp = $this->como($this->admin)->postJson("/api/cotacoes/{$cotacaoId}/encaminhar", ['transportadora_ids' => [$this->ta->id]])
            ->assertOk()['data']['negociacao']['respostas'];
        $id = collect($resp)->firstWhere('transportadora.id', $this->ta->id)['id'];
        $this->como($this->ua)->postJson("/api/cotacoes/{$cotacaoId}/respostas/{$id}/responder", ['valor_tonelada' => $valorTonelada, 'prazo_dias' => 3])->assertOk();
    }

    private function fechar(string $cotacaoId, array $programacao = []): void
    {
        $this->como($this->admin)->postJson("/api/cotacoes/{$cotacaoId}/fechar", [
            'transportadora_id' => $this->ta->id, 'valor_tonelada' => '175.00', 'prazo_dias' => 3,
            'programacao' => $programacao + ['pedido_venda' => 'PV-7788', 'deposito' => 'VIRTUAL', 'modalidade_frete' => 'FOB', 'data_coleta_prevista' => '2026-11-03', 'data_entrega_prevista' => '2026-11-05'],
        ])->assertOk();
    }

    private function payload(string $evento): array
    {
        return (array) json_decode((string) Notificacao::where('evento', $evento)->value('payload'), true);
    }


    public function test_resposta_normal_manda_o_valor_cheio_e_nao_o_por_tonelada(): void
    {
        $this->responder($this->cotacao(), '180.00');

        $p = $this->payload('NEGOCIACAO_RESPONDIDA');
        $this->assertSame('R$ 2.160,00', $p['valor']);
        $this->assertArrayNotHasKey('valor_tonelada', $p);
    }

    public function test_resposta_granel_manda_por_tonelada(): void
    {
        $this->responder($this->cotacao(granel: true), '180.00');

        $this->assertSame('R$ 180,00 por tonelada', $this->payload('NEGOCIACAO_RESPONDIDA')['valor']);
    }

    public function test_declinio_continua_dizendo_declinou(): void
    {
        $id = $this->cotacao();
        $resp = $this->como($this->admin)->postJson("/api/cotacoes/{$id}/encaminhar", ['transportadora_ids' => [$this->ta->id]])->assertOk()['data']['negociacao']['respostas'];
        $this->como($this->ua)->postJson("/api/cotacoes/{$id}/respostas/{$resp[0]['id']}/declinar", ['observacao' => 'sem frota'])->assertOk();

        $this->assertSame('declinou', $this->payload('NEGOCIACAO_DECLINADA')['valor']);
    }

    public function test_whatsapp_dos_internos_leva_o_valor_ja_formatado_no_texto(): void
    {
        $this->responder($this->cotacao(), '180.00');

        [, $template, $params] = $this->wa->para('5519999990010', 'resposta_recebida');
        $this->assertSame('resposta_recebida', $template);
        $this->assertSame('R$ 2.160,00', $params[2]);
        $this->assertStringContainsString('R$ 2.160,00', TextoTemplate::renderizar($template, $params));
        $this->assertStringNotContainsString('por tonelada', TextoTemplate::renderizar($template, $params));
    }

    public function test_email_mostra_o_valor_cheio_com_rotulo_proprio(): void
    {
        $this->responder($this->cotacao(), '180.00');

        $n = Notificacao::where('evento', 'NEGOCIACAO_RESPONDIDA')->where('canal', 'EMAIL')->where('grupo_codigo', 'VENDEDOR')->firstOrFail();
        $mailable = new \App\Mail\RespostaRecebida($n);
        $html = $mailable->render();
        $this->assertStringContainsString('R$ 2.160,00', $html);
        $this->assertStringContainsString('Valor', $html);
        $this->assertStringNotContainsString('180.00', $html);
    }


    public function test_fechar_manda_programar_pedido_para_a_transportadora_com_8_parametros(): void
    {
        $id = $this->cotacao();
        $this->responder($id, '180.00');
        $this->wa->enviados = [];

        $this->fechar($id);

        [, $template, $params] = $this->wa->para('5519999990001');
        $this->assertSame('programar_pedido', $template);
        $this->assertCount(8, $params);
        $this->assertSame('PV-7788', $params[0]);
        $this->assertSame('Indústria Exemplo Ltda, CNPJ 12.345.678/0001-90', $params[1]);
        $this->assertSame('FOB', $params[2]);
        $this->assertStringContainsString('Soda cáustica', $params[3]);
        $this->assertStringContainsString('ONU:1824 - RISCO:80', $params[3]);
        $this->assertSame('PAULÍNIA/SP x CAMPINAS/SP', $params[4]);
        $this->assertSame('R$ 2.100,00', $params[5]);
        $this->assertSame('03/11/2026 e 05/11/2026', $params[6]);
        $this->assertSame('https://fretes.example.test/quotes?abrir=COT-000001', $params[7]);
    }

    public function test_fechar_granel_manda_por_tonelada_no_programar_pedido(): void
    {
        $id = $this->cotacao(granel: true);
        $this->responder($id, '180.00');
        $this->wa->enviados = [];

        $this->fechar($id);

        $this->assertSame('R$ 175,00 por tonelada', $this->wa->para('5519999990001')[2][5]);
    }

    public function test_fechar_sem_datas_diz_a_combinar(): void
    {
        $id = $this->cotacao();
        $this->responder($id, '180.00');
        $this->wa->enviados = [];

        $this->fechar($id, ['data_coleta_prevista' => null, 'data_entrega_prevista' => null]);

        $this->assertSame('a combinar e a combinar', $this->wa->para('5519999990001')[2][6]);
    }

    public function test_fechar_mantem_frete_fechado_para_vendedor_e_admin(): void
    {
        $id = $this->cotacao();
        $this->responder($id, '180.00');
        $this->wa->enviados = [];

        $this->fechar($id);

        foreach (['5519999990010', '5519999990020'] as $telefone) {
            [, $template, $params] = $this->wa->para($telefone);
            $this->assertSame('frete_fechado', $template);
            $this->assertSame('R$ 2.100,00', $params[2]);
        }
        $this->assertSame(['programar_pedido'], collect($this->wa->enviados)->where(0, '5519999990001')->pluck(1)->unique()->values()->all());
    }

    public function test_email_de_fechamento_nao_muda_de_template(): void
    {
        $id = $this->cotacao();
        $this->responder($id, '180.00');
        Mail::fake();

        $this->fechar($id);

        Mail::assertSent(\App\Mail\NegociacaoFechada::class, 3);
        $this->assertSame(['frete_fechado'], Notificacao::where('evento', 'NEGOCIACAO_FECHADA')->where('canal', 'EMAIL')->pluck('template')->unique()->values()->all());
    }

    public function test_texto_do_programar_pedido_para_a_evolution(): void
    {
        $params = ['CRG-000001', 'Cliente, CNPJ 1', 'CIF', 'Carreta de Soda: 1824 - 80', 'A/SP x B/SP', 'R$ 2.100,00', '03/11/2026 e 05/11/2026', 'https://x/quotes?abrir=COT-000001'];
        $texto = TextoTemplate::renderizar('programar_pedido', $params);

        $this->assertStringContainsString('Favor programar pedido: CRG-000001', $texto);
        $this->assertStringContainsString('Valor: R$ 2.100,00', $texto);
        $this->assertStringNotContainsString('{{', $texto);
    }
}
