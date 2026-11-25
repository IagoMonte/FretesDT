<?php

namespace Tests\Feature;

use App\Jobs\EnviarWhatsappJob;
use App\Models\Notificacao;
use App\Services\Notificacao\Whatsapp\CloudWhatsappGateway;
use App\Services\Notificacao\Whatsapp\LogWhatsappGateway;
use App\Services\Notificacao\Whatsapp\NullWhatsappGateway;
use App\Services\Notificacao\Whatsapp\WhatsappFalhaDefinitiva;
use App\Services\Notificacao\Whatsapp\WhatsappFalhaTemporaria;
use App\Services\Notificacao\Whatsapp\WhatsappGateway;
use App\Support\Telefone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class FakeWhatsappGateway implements WhatsappGateway
{
    /** @var array<int, array{0: string, 1: string, 2: array}> */
    public array $enviados = [];

    public ?\Throwable $falha = null;

    public function enviar(string $para, string $template, array $params): string
    {
        if ($this->falha) {
            throw $this->falha;
        }
        $this->enviados[] = [$para, $template, $params];

        return 'wamid.'.count($this->enviados);
    }
}

/** interface plugável, drivers, normalização E.164, retry e o driver Cloud. */
class NotificacaoWhatsappTest extends TestCase
{
    private FakeWhatsappGateway $wa;

    private $admin;

    private $ta;

    private string $cotacaoId;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->wa = new FakeWhatsappGateway;
        $this->app->instance(WhatsappGateway::class, $this->wa);
        $this->admin = $this->usuario('admin', 'ADMINISTRADOR');
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $this->ta = $this->transportadora('TRANSLOG');
        $this->ta->update(['email' => 'translog@t.test', 'telefone' => '(19) 99999-0001']);
        $this->usuario('translog', 'TRANSPORTADORA', $this->ta);
        $this->cotacaoId = $this->como($vendedor)->postJson('/api/cotacoes', $this->corpoCotacao($this->cliente(), $this->produto()))->assertCreated()['data']['id'];
    }

    private function encaminhar(): \Illuminate\Testing\TestResponse
    {
        return $this->como($this->admin)->postJson("/api/cotacoes/{$this->cotacaoId}/encaminhar", ['transportadora_ids' => [$this->ta->id]])->assertOk();
    }

    public function test_normaliza_telefone_para_e164(): void
    {
        $this->assertSame('5519999990001', Telefone::e164('(19) 99999-0001'));
        $this->assertSame('5519999990001', Telefone::e164('+55 19 99999-0001'));
        $this->assertSame('551933334444', Telefone::e164('019 3333-4444'));
        $this->assertNull(Telefone::e164('99999-0001'), 'sem DDD');
        $this->assertNull(Telefone::e164('123'));
        $this->assertNull(Telefone::e164(null));
        $this->assertNull(Telefone::e164(''));
    }

    public function test_encaminhar_envia_whatsapp_com_o_template_e_os_parametros(): void
    {
        $this->encaminhar();

        $this->assertCount(1, $this->wa->enviados);
        [$para, $template, $params] = $this->wa->enviados[0];
        $this->assertSame('5519999990001', $para);
        $this->assertSame('cotacao_disponivel', $template);
        $this->assertSame('COT-000001', $params[0]);
        $this->assertStringContainsString('→', $params[1]);
        $this->assertSame('12.000', $params[2]);
        $n = Notificacao::where('canal', 'WHATSAPP')->first();
        $this->assertSame('ENVIADO', $n->status);
        $this->assertSame('wamid.1', $n->id_externo);
        $this->assertSame(1, $n->tentativas);
    }

    public function test_sem_numero_valido_cai_para_so_email_com_alerta(): void
    {
        $this->ta->update(['telefone' => '123']);

        $r = $this->encaminhar();

        $this->assertCount(0, $this->wa->enviados);
        $this->assertCount(1, collect($r['meta']['alertas'])->filter(fn ($a) => str_contains($a, 'TRANSLOG') && str_contains($a, 'WhatsApp')));
        $this->assertSame('FALHA', DB::table('notificacao')->where('canal', 'WHATSAPP')->value('status'));
        $this->assertSame('ENVIADO', DB::table('notificacao')->where('canal', 'EMAIL')->value('status'));
    }

    public function test_cancelamento_usa_whatsapp_por_padrao(): void
    {
        $this->encaminhar();
        $this->wa->enviados = [];

        $this->como($this->admin)->postJson("/api/cotacoes/{$this->cotacaoId}/cancelar", ['motivo' => 'teste'])->assertOk();

        $this->assertContains('cotacao_cancelada', array_column($this->wa->enviados, 1));
        $this->assertGreaterThan(0, DB::table('notificacao')->where('evento', 'COTACAO_CANCELADA')->where('canal', 'WHATSAPP')->count());
    }

    public function test_falha_do_gateway_nao_reverte_o_negocio(): void
    {
        $this->wa->falha = new WhatsappFalhaDefinitiva('numero invalido');

        $this->encaminhar()->assertJsonPath('data.status', 'EM_NEGOCIACAO');

        $n = Notificacao::where('canal', 'WHATSAPP')->first();
        $this->assertSame('FALHA', $n->status);
        $this->assertStringContainsString('numero invalido', $n->erro);
    }

    public function test_falha_temporaria_mantem_pendente_e_rearma_com_backoff(): void
    {
        $this->encaminhar();
        $n = Notificacao::where('canal', 'WHATSAPP')->first();
        $n->forceFill(['status' => 'PENDENTE', 'tentativas' => 0, 'id_externo' => null])->save();
        $this->wa->falha = new WhatsappFalhaTemporaria('timeout');
        $job = new EnviarWhatsappJob($n->id);

        try {
            $job->handle($this->wa);
            $this->fail('deveria relançar para a fila tentar de novo');
        } catch (WhatsappFalhaTemporaria) {
        }

        $n->refresh();
        $this->assertSame('PENDENTE', $n->status);
        $this->assertSame(1, $n->tentativas);
        $this->assertSame('timeout', $n->erro);
        $this->assertSame([60, 300, 1800], $job->backoff());
        $this->assertSame(4, $job->tries);
        $this->assertSame('whatsapp', $job->queue);

        $job->failed(new WhatsappFalhaTemporaria('timeout'));
        $this->assertSame('FALHA', $n->refresh()->status);
    }

    public function test_job_nao_reenvia_o_que_ja_foi_enviado(): void
    {
        $this->encaminhar();
        $n = Notificacao::where('canal', 'WHATSAPP')->first();

        (new EnviarWhatsappJob($n->id))->handle($this->wa);

        $this->assertCount(1, $this->wa->enviados);
    }

    public function test_driver_configurado_escolhe_o_gateway(): void
    {
        $this->app->forgetInstance(WhatsappGateway::class);
        $this->app->offsetUnset(WhatsappGateway::class);
        $this->app->bind(WhatsappGateway::class, fn () => \App\Services\Notificacao\Whatsapp\WhatsappGatewayFactory::criar());

        config(['whatsapp.driver' => 'log']);
        $this->assertInstanceOf(LogWhatsappGateway::class, app(WhatsappGateway::class));
        config(['whatsapp.driver' => 'null']);
        $this->assertInstanceOf(NullWhatsappGateway::class, app(WhatsappGateway::class));
        config(['whatsapp.driver' => 'cloud']);
        $this->assertInstanceOf(CloudWhatsappGateway::class, app(WhatsappGateway::class));
    }

    public function test_drivers_log_e_null_devolvem_id_sem_chamar_rede(): void
    {
        Http::fake();

        $this->assertNotSame('', (new LogWhatsappGateway)->enviar('5519999990001', 'carga_status', ['CRG-1']));
        $this->assertSame('null', (new NullWhatsappGateway)->enviar('5519999990001', 'carga_status', ['CRG-1']));
        Http::assertNothingSent();
    }

    private function cloud(): CloudWhatsappGateway
    {
        config(['whatsapp.token' => 'tok123', 'whatsapp.phone_number_id' => '999', 'whatsapp.template_lang' => 'pt_BR', 'whatsapp.timeout' => 10, 'whatsapp.app_secret' => null]);

        return new CloudWhatsappGateway;
    }

    public function test_cloud_posta_o_template_na_graph_api(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ABC']]], 200)]);

        $id = $this->cloud()->enviar('5519999990001', 'cotacao_disponivel', ['COT-000001', 'A → B', '12000']);

        $this->assertSame('wamid.ABC', $id);
        Http::assertSent(function ($req) {
            $d = $req->data();

            return $req->url() === 'https://graph.facebook.com/v22.0/999/messages'
                && $req->hasHeader('Authorization', 'Bearer tok123')
                && $d['messaging_product'] === 'whatsapp' && $d['to'] === '5519999990001' && $d['type'] === 'template'
                && $d['template']['name'] === 'cotacao_disponivel' && $d['template']['language']['code'] === 'pt_BR'
                && $d['template']['components'][0]['parameters'][0] === ['type' => 'text', 'text' => 'COT-000001'];
        });
    }

    public function test_cloud_4xx_e_falha_definitiva_e_5xx_429_sao_temporarias(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['error' => ['message' => 'invalid number']], 400)
            ->push([], 500)->push([], 503)->push([], 429)]);

        try {
            $this->cloud()->enviar('5519999990001', 't', []);
            $this->fail('esperava falha definitiva');
        } catch (WhatsappFalhaDefinitiva $e) {
            $this->assertStringContainsString('invalid number', $e->getMessage());
        }

        foreach ([500, 503, 429] as $codigo) {
            try {
                $this->cloud()->enviar('5519999990001', 't', []);
                $this->fail("esperava falha temporária para {$codigo}");
            } catch (WhatsappFalhaTemporaria) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_cloud_timeout_e_falha_temporaria(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $this->expectException(WhatsappFalhaTemporaria::class);
        $this->cloud()->enviar('5519999990001', 't', []);
    }
}
