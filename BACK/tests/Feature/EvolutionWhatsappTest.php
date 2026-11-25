<?php

namespace Tests\Feature;

use App\Services\Notificacao\Whatsapp\EvolutionWhatsappGateway;
use App\Services\Notificacao\Whatsapp\TextoTemplate;
use App\Services\Notificacao\Whatsapp\WhatsappFalhaDefinitiva;
use App\Services\Notificacao\Whatsapp\WhatsappFalhaTemporaria;
use App\Services\Notificacao\Whatsapp\WhatsappGateway;
use App\Services\Notificacao\Whatsapp\WhatsappGatewayFactory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Segunda via do WhatsApp: Evolution API (Baileys) como driver ao lado da Cloud, com controller próprio (status, QR, teste). */
class EvolutionWhatsappTest extends TestCase
{
    private function evolution(): EvolutionWhatsappGateway
    {
        config(['whatsapp.driver' => 'evolution', 'whatsapp.evolution' => ['url' => 'http://evo.test:8080', 'apikey' => 'k-secreta', 'instance' => 'fretesdt', 'timeout' => 10]]);

        return new EvolutionWhatsappGateway;
    }

    public function test_renderiza_o_texto_do_template_na_ordem_dos_parametros(): void
    {
        $texto = TextoTemplate::renderizar('carga_status', ['CRG-1', 'Em trânsito', '25/10/2026', 'https://x.test/c/1']);

        $this->assertStringContainsString('CRG-1', $texto);
        $this->assertStringContainsString('Em trânsito', $texto);
        $this->assertStringContainsString('https://x.test/c/1', $texto);
        $this->assertStringNotContainsString('{{', $texto);
    }

    public function test_parametro_faltando_vira_traco_e_template_desconhecido_cai_no_generico(): void
    {
        $this->assertStringNotContainsString('{{', TextoTemplate::renderizar('carga_status', ['CRG-1']));
        $this->assertStringContainsString('CRG-9', TextoTemplate::renderizar('inexistente', ['CRG-9', 'x']));
    }

    public function test_posta_o_texto_na_evolution_com_apikey(): void
    {
        Http::fake(['evo.test:8080/*' => Http::response(['key' => ['id' => 'BAE5ABC']], 201)]);

        $id = $this->evolution()->enviar('5519999990001', 'carga_status', ['CRG-1', 'Em trânsito', '25/10/2026', 'https://x.test']);

        $this->assertSame('BAE5ABC', $id);
        Http::assertSent(function ($req) {
            $d = $req->data();

            return $req->url() === 'http://evo.test:8080/message/sendText/fretesdt'
                && $req->hasHeader('apikey', 'k-secreta')
                && $d['number'] === '5519999990001' && str_contains($d['text'], 'CRG-1');
        });
    }

    public function test_4xx_e_falha_definitiva_e_5xx_429_sao_temporarias(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['response' => ['message' => ['numero invalido']]], 400)
            ->push([], 500)->push([], 503)->push([], 429)]);

        try {
            $this->evolution()->enviar('5519999990001', 'carga_status', []);
            $this->fail('esperava falha definitiva');
        } catch (WhatsappFalhaDefinitiva) {
            $this->assertTrue(true);
        }

        foreach ([500, 503, 429] as $codigo) {
            try {
                $this->evolution()->enviar('5519999990001', 'carga_status', []);
                $this->fail("esperava falha temporária para {$codigo}");
            } catch (WhatsappFalhaTemporaria) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_timeout_e_falha_temporaria(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $this->expectException(WhatsappFalhaTemporaria::class);
        $this->evolution()->enviar('5519999990001', 'carga_status', []);
    }

    public function test_factory_resolve_o_driver_evolution(): void
    {
        config(['whatsapp.driver' => 'evolution']);

        $this->assertInstanceOf(EvolutionWhatsappGateway::class, WhatsappGatewayFactory::criar());
    }

    public function test_controller_exige_permissao(): void
    {
        $this->evolution();
        $vendedor = $this->usuario('marcos', 'VENDEDOR');

        $this->como($vendedor)->getJson('/api/evolution/status')->assertForbidden();
        $this->como($vendedor)->getJson('/api/evolution/qr')->assertForbidden();
        $this->como($vendedor)->postJson('/api/evolution/teste', ['destino' => '(19) 99999-0001'])->assertForbidden();
    }

    public function test_controller_responde_409_fora_do_driver_evolution_sem_chamar_a_rede(): void
    {
        Http::fake();
        config(['whatsapp.driver' => 'cloud']);
        $admin = $this->usuario('admin', 'ADMINISTRADOR');

        $this->como($admin)->getJson('/api/evolution/status')->assertStatus(409);
        $this->como($admin)->getJson('/api/evolution/qr')->assertStatus(409);
        $this->como($admin)->postJson('/api/evolution/teste', ['destino' => '(19) 99999-0001'])->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_status_devolve_o_estado_da_instancia_sem_expor_a_apikey(): void
    {
        $this->evolution();
        Http::fake(['evo.test:8080/instance/connectionState/fretesdt' => Http::response(['instance' => ['instanceName' => 'fretesdt', 'state' => 'open']])]);
        $admin = $this->usuario('admin', 'ADMINISTRADOR');

        $r = $this->como($admin)->getJson('/api/evolution/status')->assertOk();

        $this->assertSame('open', $r['data']['estado']);
        $this->assertStringNotContainsString('k-secreta', $r->getContent());
        Http::assertSent(fn ($req) => $req->hasHeader('apikey', 'k-secreta'));
    }

    public function test_qr_devolve_o_codigo_para_parear(): void
    {
        $this->evolution();
        Http::fake(['evo.test:8080/instance/connect/fretesdt' => Http::response(['base64' => 'data:image/png;base64,AAA', 'code' => '2@abc', 'pairingCode' => 'ABCD1234', 'count' => 1])]);
        $admin = $this->usuario('admin', 'ADMINISTRADOR');

        $r = $this->como($admin)->getJson('/api/evolution/qr')->assertOk();

        $this->assertSame('data:image/png;base64,AAA', $r['data']['qr']);
        $this->assertSame('ABCD1234', $r['data']['codigo_pareamento']);
        $this->assertStringNotContainsString('k-secreta', $r->getContent());
    }

    public function test_teste_envia_pela_evolution_e_valida_o_telefone(): void
    {
        $this->evolution();
        Http::fake(['evo.test:8080/message/sendText/fretesdt' => Http::response(['key' => ['id' => 'BAE5XYZ']], 201)]);
        $admin = $this->usuario('admin', 'ADMINISTRADOR');

        $r = $this->como($admin)->postJson('/api/evolution/teste', ['destino' => '(19) 99999-0001'])->assertOk();

        $this->assertSame('BAE5XYZ', $r['data']['id_externo']);
        Http::assertSent(fn ($req) => $req->data()['number'] === '5519999990001');
        $this->como($admin)->postJson('/api/evolution/teste', ['destino' => '123'])->assertStatus(422);
    }

    public function test_gateway_e_resolvido_pelo_container_quando_o_driver_e_evolution(): void
    {
        config(['whatsapp.driver' => 'evolution']);
        $this->app->bind(WhatsappGateway::class, fn () => WhatsappGatewayFactory::criar());

        $this->assertInstanceOf(EvolutionWhatsappGateway::class, app(WhatsappGateway::class));
    }
}
