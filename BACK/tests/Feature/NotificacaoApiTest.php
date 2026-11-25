<?php

namespace Tests\Feature;

use App\Mail\TesteNotificacao;
use App\Models\Notificacao;
use App\Services\Notificacao\Whatsapp\WhatsappGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** API de configuração (matriz), histórico, reenvio e teste. Só POST (nada de PUT). */
class NotificacaoApiTest extends TestCase
{
    private $admin;

    private $vendedor;

    private $wa;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->admin = $this->usuario('admin', 'ADMINISTRADOR');
        $this->admin->update(['email' => 'admin@example.test']);
        $this->vendedor = $this->usuario('marcos', 'VENDEDOR');
        $this->wa = new class implements WhatsappGateway
        {
            public array $enviados = [];

            public function enviar(string $para, string $template, array $params): string
            {
                $this->enviados[] = [$para, $template, $params];

                return 'wamid.teste';
            }
        };
        $this->app->instance(WhatsappGateway::class, $this->wa);
    }

    private function linha(array $extra = []): Notificacao
    {
        static $n = 0;

        return Notificacao::create($extra + [
            'evento' => 'NEGOCIACAO_ENCAMINHADA', 'entidade' => 'NEGOCIACAO', 'registro_id' => 'x', 'registro_codigo' => 'COT-000001',
            'canal' => 'EMAIL', 'grupo_codigo' => 'TRANSPORTADORA', 'destino_nome' => 'TRANSLOG', 'endereco' => 'translog@t.test',
            'template' => 'cotacao_disponivel', 'payload' => '{"rota":"A → B","kg":"1000"}', 'status' => 'FALHA', 'erro' => 'smtp fora',
            'tentativas' => 3, 'idempotencia_key' => 'k'.++$n,
        ]);
    }

    public function test_so_administrador_acessa(): void
    {
        $this->como($this->vendedor)->getJson('/api/notificacoes/preferencias')->assertForbidden();
        $this->como($this->vendedor)->getJson('/api/notificacoes/historico')->assertForbidden();
        $this->como($this->vendedor)->postJson('/api/notificacoes/preferencias', ['preferencias' => []])->assertForbidden();
        $this->como($this->vendedor)->postJson('/api/notificacoes/teste', ['canal' => 'EMAIL', 'destino' => 'a@b.co'])->assertForbidden();
        $this->flushHeaders();
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->getJson('/api/notificacoes/preferencias')->assertUnauthorized();
    }

    public function test_matriz_lista_as_combinacoes_com_o_valor_efetivo_tudo_ligado_por_padrao(): void
    {
        $r = $this->como($this->admin)->getJson('/api/notificacoes/preferencias')->assertOk();

        $linhas = collect($r['data']);
        $acha = fn ($e, $c, $g) => $linhas->first(fn ($l) => $l['evento'] === $e && $l['canal'] === $c && $l['grupo_codigo'] === $g);
        $this->assertTrue($acha('NEGOCIACAO_ENCAMINHADA', 'EMAIL', 'TRANSPORTADORA')['ativo']);
        $this->assertTrue($acha('COTACAO_CANCELADA', 'WHATSAPP', 'VENDEDOR')['ativo']);
        $this->assertTrue($acha('COTACAO_CANCELADA', 'EMAIL', 'TRANSPORTADORA')['ativo']);
        $this->assertTrue($acha('NEGOCIACAO_RESPONDIDA', 'EMAIL', 'TRANSPORTADORA')['ativo']);
        $this->assertNull($acha('NEGOCIACAO_FECHADA', 'EMAIL', 'GRUPO_TRANSPORTADORA'), 'o grupo de WhatsApp não tem e-mail');
        $this->assertCount(3, $linhas->where('ativo', false), 'só o WhatsApp dos internos em PROGRAMACAO_CRIADA nasce desligado');
        $this->assertFalse($acha('PROGRAMACAO_CRIADA', 'WHATSAPP', 'VENDEDOR')['ativo']);
        $this->assertTrue($acha('PROGRAMACAO_CRIADA', 'WHATSAPP', 'TRANSPORTADORA')['ativo']);
        $this->assertSame(['evento', 'canal', 'grupo_codigo', 'ativo'], array_keys($linhas->first()));
    }

    public function test_post_salva_em_lote_e_reflete_no_get(): void
    {
        $this->como($this->admin)->postJson('/api/notificacoes/preferencias', ['preferencias' => [
            ['evento' => 'COTACAO_CANCELADA', 'canal' => 'WHATSAPP', 'grupo_codigo' => 'VENDEDOR', 'ativo' => true],
            ['evento' => 'NEGOCIACAO_ENCAMINHADA', 'canal' => 'EMAIL', 'grupo_codigo' => 'TRANSPORTADORA', 'ativo' => false],
        ]])->assertOk()->assertJsonPath('meta.alertas', []);

        $linhas = collect($this->como($this->admin)->getJson('/api/notificacoes/preferencias')['data']);
        $this->assertTrue($linhas->first(fn ($l) => $l['evento'] === 'COTACAO_CANCELADA' && $l['canal'] === 'WHATSAPP' && $l['grupo_codigo'] === 'VENDEDOR')['ativo']);
        $this->assertFalse($linhas->first(fn ($l) => $l['evento'] === 'NEGOCIACAO_ENCAMINHADA' && $l['canal'] === 'EMAIL' && $l['grupo_codigo'] === 'TRANSPORTADORA')['ativo']);
        $this->assertSame(2, DB::table('notificacao_preferencia')->count());
    }

    public function test_post_rejeita_combinacao_invalida_e_put_nao_existe(): void
    {
        foreach ([
            ['evento' => 'COTACAO_CANCELADA', 'canal' => 'EMAIL', 'grupo_codigo' => 'GRUPO_TRANSPORTADORA', 'ativo' => true],
            ['evento' => 'NAO_EXISTE', 'canal' => 'EMAIL', 'grupo_codigo' => 'VENDEDOR', 'ativo' => true],
            ['evento' => 'COTACAO_CANCELADA', 'canal' => 'SMS', 'grupo_codigo' => 'VENDEDOR', 'ativo' => true],
            ['evento' => 'COTACAO_CANCELADA', 'canal' => 'EMAIL', 'grupo_codigo' => 'VENDEDOR', 'ativo' => 'talvez'],
        ] as $linha) {
            $this->como($this->admin)->postJson('/api/notificacoes/preferencias', ['preferencias' => [$linha]])->assertStatus(422);
        }
        $this->como($this->admin)->postJson('/api/notificacoes/preferencias', ['preferencias' => []])->assertStatus(422);
        $this->como($this->admin)->putJson('/api/notificacoes/preferencias', ['preferencias' => []])->assertStatus(405);
        $this->assertSame(0, DB::table('notificacao_preferencia')->count());
    }

    public function test_historico_filtra_por_evento_status_e_canal(): void
    {
        $this->linha();
        $this->linha(['canal' => 'WHATSAPP', 'endereco' => '5519999990001', 'status' => 'ENVIADO', 'erro' => null]);
        $this->linha(['evento' => 'NEGOCIACAO_FECHADA', 'status' => 'ENVIADO', 'erro' => null]);

        $todos = $this->como($this->admin)->getJson('/api/notificacoes/historico')->assertOk();
        $this->assertSame(3, $todos['meta']['total']);
        $this->assertSame('COT-000001', $todos['data'][0]['registro_codigo']);

        $this->assertCount(1, $this->como($this->admin)->getJson('/api/notificacoes/historico?status=FALHA')['data']);
        $this->assertCount(1, $this->como($this->admin)->getJson('/api/notificacoes/historico?canal=WHATSAPP')['data']);
        $this->assertCount(1, $this->como($this->admin)->getJson('/api/notificacoes/historico?evento=NEGOCIACAO_FECHADA')['data']);
        $this->assertCount(0, $this->como($this->admin)->getJson('/api/notificacoes/historico?evento=NEGOCIACAO_FECHADA&status=FALHA')['data']);
    }

    public function test_reenviar_so_o_que_falhou(): void
    {
        $falha = $this->linha(['evento' => 'NEGOCIACAO_ENCAMINHADA']);

        $r = $this->como($this->admin)->postJson("/api/notificacoes/{$falha->id}/reenviar")->assertOk();

        $this->assertSame('ENVIADO', $r['data']['status']);
        $this->assertNull($r['data']['erro']);
        Mail::assertSent(\App\Mail\CotacaoEncaminhada::class, fn ($m) => $m->hasTo('translog@t.test'));

        $this->como($this->admin)->postJson("/api/notificacoes/{$falha->id}/reenviar")->assertStatus(422);
        $this->como($this->admin)->postJson('/api/notificacoes/inexistente/reenviar')->assertNotFound();
    }

    public function test_reenviar_whatsapp_usa_o_gateway(): void
    {
        $falha = $this->linha(['canal' => 'WHATSAPP', 'endereco' => '5519999990001', 'registro_codigo' => 'COT-000009']);

        $this->como($this->admin)->postJson("/api/notificacoes/{$falha->id}/reenviar")->assertOk()->assertJsonPath('data.status', 'ENVIADO');

        $this->assertSame('5519999990001', $this->wa->enviados[0][0]);
        $this->assertSame('cotacao_disponivel', $this->wa->enviados[0][1]);
    }

    public function test_teste_de_email_envia_e_fica_no_historico(): void
    {
        $r = $this->como($this->admin)->postJson('/api/notificacoes/teste', ['canal' => 'EMAIL', 'destino' => 'quem@example.test'])->assertOk();

        Mail::assertSent(TesteNotificacao::class, fn ($m) => $m->hasTo('quem@example.test'));
        $this->assertSame('ENVIADO', $r['data']['status']);
        $this->assertSame('TESTE', $r['data']['evento']);
        $this->assertSame(1, DB::table('notificacao')->where('evento', 'TESTE')->count());
    }

    public function test_teste_de_whatsapp_normaliza_o_numero_e_avisa_modo_simulado(): void
    {
        config(['whatsapp.driver' => 'log']);
        $r = $this->como($this->admin)->postJson('/api/notificacoes/teste', ['canal' => 'WHATSAPP', 'destino' => '(19) 99999-0001'])->assertOk();

        $this->assertSame('5519999990001', $this->wa->enviados[0][0]);
        $this->assertCount(1, collect($r['meta']['alertas'])->filter(fn ($a) => str_contains($a, 'simulado')));

        config(['whatsapp.driver' => 'cloud']);
        $r = $this->como($this->admin)->postJson('/api/notificacoes/teste', ['canal' => 'WHATSAPP', 'destino' => '19999990001'])->assertOk();
        $this->assertSame([], $r['meta']['alertas']);
    }

    public function test_teste_valida_canal_e_destino(): void
    {
        $this->como($this->admin)->postJson('/api/notificacoes/teste', ['canal' => 'SMS', 'destino' => 'x'])->assertStatus(422);
        $this->como($this->admin)->postJson('/api/notificacoes/teste', ['canal' => 'EMAIL', 'destino' => 'nao-e-email'])->assertStatus(422);
        $this->como($this->admin)->postJson('/api/notificacoes/teste', ['canal' => 'WHATSAPP', 'destino' => '123'])->assertStatus(422);
    }

    public function test_recurso_notificacao_existe_so_para_admin(): void
    {
        $this->assertDatabaseHas('recurso', ['codigo' => 'NOTIFICACAO']);
        $this->assertDatabaseHas('recurso_acao', ['recurso_codigo' => 'NOTIFICACAO', 'acao_codigo' => 'REENVIAR']);
        $this->assertTrue($this->admin->pode('NOTIFICACAO.ver'));
        $this->assertFalse($this->vendedor->pode('NOTIFICACAO.ver'));
    }
}
