<?php

namespace Tests\Feature;

use App\Mail\CotacaoEncaminhada;
use App\Mail\RespostaRecebida;
use App\Models\Notificacao;
use App\Services\Notificacao\PreferenciaService;
use App\Services\Notificacao\Whatsapp\WhatsappGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class GatewayMatrizFake implements WhatsappGateway
{
    /** @var array<int, array{0: string, 1: string, 2: array}> */
    public array $enviados = [];

    public function enviar(string $para, string $template, array $params): string
    {
        $this->enviados[] = [$para, $template, $params];

        return 'wamid.'.count($this->enviados);
    }

    /** @return array{0: string, 1: string, 2: array}|null */
    public function para(string $telefone): ?array
    {
        return collect($this->enviados)->first(fn ($e) => $e[0] === $telefone);
    }
}

/**
 * Matriz evento x canal x grupo: o padrão é "todo mundo recebe tudo" (sem bloqueio por sigilo) e o administrador decide quem recebe o quê.
 * Receber, para a transportadora, é só o que é dela: a cotação que recebeu, a resposta que deu, a carga que ganhou. Nunca a de outra.
 */
class NotificacaoMatrizTest extends TestCase
{
    private GatewayMatrizFake $wa;

    private $admin;

    private $vendedor;

    private $ta;

    private $tb;

    private $ua;

    private $ub;

    private string $cotacaoId;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->wa = new GatewayMatrizFake;
        $this->app->instance(WhatsappGateway::class, $this->wa);
        $this->admin = $this->usuario('admin', 'ADMINISTRADOR');
        $this->admin->update(['email' => 'admin@example.test', 'telefone' => '(19) 99999-0010']);
        $this->vendedor = $this->usuario('marcos', 'VENDEDOR');
        $this->vendedor->update(['email' => 'marcos@example.test', 'telefone' => '(19) 99999-0020']);
        $this->ta = $this->transportadora('TRANSLOG');
        $this->ta->update(['email' => 'translog@t.test', 'telefone' => '(19) 99999-0001']);
        $this->tb = $this->transportadora('ROTA SUL');
        $this->tb->update(['email' => 'via@t.test', 'telefone' => '(19) 99999-0002']);
        $this->ua = $this->usuario('translog', 'TRANSPORTADORA', $this->ta);
        $this->ub = $this->usuario('rotasul', 'TRANSPORTADORA', $this->tb);

        $this->cotacaoId = $this->como($this->vendedor)->postJson('/api/cotacoes', $this->corpoCotacao($this->cliente(), $this->produto()))->assertCreated()['data']['id'];
    }

    private function encaminhar(): array
    {
        return $this->como($this->admin)->postJson("/api/cotacoes/{$this->cotacaoId}/encaminhar", ['transportadora_ids' => [$this->ta->id, $this->tb->id]])
            ->assertOk()['data']['negociacao']['respostas'];
    }

    private function emails(string $mailable): array
    {
        return Mail::sent($mailable)->map(fn ($m) => $m->to[0]['address'])->sort()->values()->all();
    }


    public function test_padrao_e_tudo_ligado_para_todos_os_grupos_nos_dois_canais(): void
    {
        $matriz = collect(app(PreferenciaService::class)->matriz());
        $eventos = array_keys(config('notificacoes.eventos'));

        $this->assertCount(count($eventos) * 9, $matriz);
        $desligadas = $matriz->where('ativo', false)->map(fn ($l) => "{$l['evento']}/{$l['canal']}/{$l['grupo_codigo']}")->sort()->values()->all();
        $this->assertSame(['PROGRAMACAO_CRIADA/WHATSAPP/ADMINISTRADOR', 'PROGRAMACAO_CRIADA/WHATSAPP/GESTOR', 'PROGRAMACAO_CRIADA/WHATSAPP/VENDEDOR'], $desligadas);
        foreach ($eventos as $evento) {
            foreach (['VENDEDOR', 'ADMINISTRADOR', 'GESTOR', 'TRANSPORTADORA'] as $grupo) {
                foreach (['WHATSAPP', 'EMAIL'] as $canal) {
                    $this->assertNotNull($matriz->first(fn ($l) => $l['evento'] === $evento && $l['canal'] === $canal && $l['grupo_codigo'] === $grupo), "{$evento}/{$canal}/{$grupo}");
                }
            }
            $this->assertNotNull($matriz->first(fn ($l) => $l['evento'] === $evento && $l['canal'] === 'WHATSAPP' && $l['grupo_codigo'] === 'GRUPO_TRANSPORTADORA'));
            $this->assertNull($matriz->first(fn ($l) => $l['evento'] === $evento && $l['canal'] === 'EMAIL' && $l['grupo_codigo'] === 'GRUPO_TRANSPORTADORA'));
        }
    }

    public function test_api_aceita_ligar_e_desligar_qualquer_celula_inclusive_as_antes_bloqueadas(): void
    {
        $this->como($this->admin)->postJson('/api/notificacoes/preferencias', ['preferencias' => [
            ['evento' => 'COTACAO_CANCELADA', 'canal' => 'EMAIL', 'grupo_codigo' => 'TRANSPORTADORA', 'ativo' => false],
            ['evento' => 'NEGOCIACAO_RESPONDIDA', 'canal' => 'WHATSAPP', 'grupo_codigo' => 'TRANSPORTADORA', 'ativo' => false],
        ]])->assertOk();

        $ativo = fn ($e, $c, $g) => app(PreferenciaService::class)->ativo($e, $c, $g);
        $this->assertFalse($ativo('COTACAO_CANCELADA', 'EMAIL', 'TRANSPORTADORA'));
        $this->assertFalse($ativo('NEGOCIACAO_RESPONDIDA', 'WHATSAPP', 'TRANSPORTADORA'));
        $this->assertTrue($ativo('NEGOCIACAO_RESPONDIDA', 'EMAIL', 'TRANSPORTADORA'));
    }

    public function test_grupo_de_whatsapp_continua_sem_email(): void
    {
        $this->como($this->admin)->postJson('/api/notificacoes/preferencias', ['preferencias' => [
            ['evento' => 'NEGOCIACAO_FECHADA', 'canal' => 'EMAIL', 'grupo_codigo' => 'GRUPO_TRANSPORTADORA', 'ativo' => true],
        ]])->assertStatus(422);
    }


    public function test_transportadora_recebe_a_propria_resposta_e_nunca_a_da_concorrente(): void
    {
        $resp = $this->encaminhar();
        Mail::fake();
        $this->wa->enviados = [];
        $id = collect($resp)->firstWhere('transportadora.id', $this->ta->id)['id'];

        $this->como($this->ua)->postJson("/api/cotacoes/{$this->cotacaoId}/respostas/{$id}/responder", ['valor_tonelada' => '180.00', 'prazo_dias' => 3])->assertOk();

        $this->assertSame(['admin@example.test', 'marcos@example.test', 'translog@t.test'], $this->emails(RespostaRecebida::class));
        $this->assertNotNull($this->wa->para('5519999990001'));
        $this->assertNull($this->wa->para('5519999990002'), 'a concorrente não recebe a resposta alheia');
    }

    public function test_cancelar_cotacao_avisa_as_transportadoras_que_a_receberam(): void
    {
        $this->encaminhar();
        Mail::fake();
        $this->wa->enviados = [];

        $this->como($this->admin)->postJson("/api/cotacoes/{$this->cotacaoId}/cancelar", ['motivo' => 'cliente desistiu'])->assertOk();

        $emails = DB::table('notificacao')->whereIn('evento', ['COTACAO_CANCELADA', 'NEGOCIACAO_CANCELADA'])->where('canal', 'EMAIL')->pluck('endereco')->unique()->sort()->values()->all();
        $this->assertSame(['admin@example.test', 'marcos@example.test', 'translog@t.test', 'via@t.test'], $emails);
        $this->assertNotNull($this->wa->para('5519999990001'));
        $this->assertNotNull($this->wa->para('5519999990002'));
    }

    public function test_cancelar_carga_avisa_a_transportadora_da_carga(): void
    {
        $resp = $this->encaminhar();
        $id = collect($resp)->firstWhere('transportadora.id', $this->ta->id)['id'];
        $this->como($this->ua)->postJson("/api/cotacoes/{$this->cotacaoId}/respostas/{$id}/responder", ['valor_tonelada' => '180.00', 'prazo_dias' => 3])->assertOk();
        $prog = $this->como($this->admin)->postJson("/api/cotacoes/{$this->cotacaoId}/fechar", [
            'transportadora_id' => $this->ta->id, 'valor_tonelada' => '175.00', 'prazo_dias' => 3,
            'programacao' => ['deposito' => 'VIRTUAL', 'modalidade_frete' => 'CIF'],
        ])->assertOk()['meta']['programacao']['id'];

        $this->como($this->admin)->postJson("/api/programacoes/{$prog}/cancelar", ['motivo' => 'erro'])->assertOk();

        $linhas = DB::table('notificacao')->where('evento', 'PROGRAMACAO_CANCELADA')->where('canal', 'EMAIL')->get();
        $this->assertSame(['ADMINISTRADOR', 'TRANSPORTADORA', 'VENDEDOR'], $linhas->pluck('grupo_codigo')->unique()->sort()->values()->all());
        $this->assertSame(['translog@t.test'], $linhas->where('grupo_codigo', 'TRANSPORTADORA')->pluck('endereco')->unique()->values()->all());
    }


    public function test_admin_desliga_um_grupo_e_ele_deixa_de_receber(): void
    {
        $this->como($this->admin)->postJson('/api/notificacoes/preferencias', ['preferencias' => [
            ['evento' => 'NEGOCIACAO_ENCAMINHADA', 'canal' => 'EMAIL', 'grupo_codigo' => 'TRANSPORTADORA', 'ativo' => false],
            ['evento' => 'NEGOCIACAO_ENCAMINHADA', 'canal' => 'WHATSAPP', 'grupo_codigo' => 'VENDEDOR', 'ativo' => false],
        ]])->assertOk();

        $this->encaminhar();

        Mail::assertNotSent(CotacaoEncaminhada::class, fn ($m) => $m->hasTo('translog@t.test') || $m->hasTo('via@t.test'));
        $this->assertNotNull($this->wa->para('5519999990001'), 'o WhatsApp da transportadora segue ligado');
    }

    public function test_admin_liga_o_vendedor_no_encaminhamento(): void
    {
        $this->encaminhar();

        $this->assertSame(['admin@example.test', 'marcos@example.test', 'translog@t.test', 'via@t.test'], $this->emails(CotacaoEncaminhada::class));
        $this->assertSame(1, Notificacao::where('evento', 'NEGOCIACAO_ENCAMINHADA')->where('canal', 'WHATSAPP')->where('endereco', '5519999990020')->count());
    }
}
