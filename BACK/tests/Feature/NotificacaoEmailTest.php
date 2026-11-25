<?php

namespace Tests\Feature;

use App\Mail\CargaStatusAlterado;
use App\Mail\CotacaoEncaminhada;
use App\Mail\NegociacaoFechada;
use App\Mail\RespostaRecebida;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** e-mail por evento, destinatário certo, 1 envio por transportadora e falha que não reverte o negócio. */
class NotificacaoEmailTest extends TestCase
{
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
        $this->admin = $this->usuario('admin', 'ADMINISTRADOR');
        $this->admin->update(['email' => 'admin@example.test']);
        $this->vendedor = $this->usuario('marcos', 'VENDEDOR');
        $this->vendedor->update(['email' => 'marcos@example.test']);
        $this->ta = $this->transportadora('TRANSLOG');
        $this->ta->update(['email' => 'translog@t.test', 'telefone' => '19999990001']);
        $this->tb = $this->transportadora('ROTA SUL');
        $this->tb->update(['email' => 'via@t.test', 'telefone' => '19999990002']);
        $this->ua = $this->usuario('translog', 'TRANSPORTADORA', $this->ta);
        $this->ub = $this->usuario('rotasul', 'TRANSPORTADORA', $this->tb);

        $this->cotacaoId = $this->como($this->vendedor)->postJson('/api/cotacoes', $this->corpoCotacao($this->cliente(), $this->produto()))
            ->assertCreated()['data']['id'];
    }

    private function encaminhar(array $transportadoras): array
    {
        return $this->como($this->admin)->postJson("/api/cotacoes/{$this->cotacaoId}/encaminhar", ['transportadora_ids' => array_map(fn ($t) => $t->id, $transportadoras)])
            ->assertOk()['data']['negociacao']['respostas'];
    }

    private function respostaDe($respostas, $transportadora): string
    {
        return collect($respostas)->firstWhere('transportadora.id', $transportadora->id)['id'];
    }

    private function destinos(string $mailable): array
    {
        return Mail::sent($mailable)->map(fn ($m) => $m->to[0]['address'])->sort()->values()->all();
    }

    public function test_encaminhar_envia_um_email_por_transportadora_e_tambem_ao_vendedor_e_ao_admin(): void
    {
        $this->encaminhar([$this->ta, $this->tb]);

        $this->assertSame(['admin@example.test', 'marcos@example.test', 'translog@t.test', 'via@t.test'], $this->destinos(CotacaoEncaminhada::class));
        Mail::assertSent(CotacaoEncaminhada::class, fn ($m) => count($m->to) === 1 && $m->cc === [] && $m->bcc === []);
        Mail::assertNotSent(RespostaRecebida::class);
        $this->assertSame(4, DB::table('notificacao')->where('canal', 'EMAIL')->where('status', 'ENVIADO')->count());
    }

    public function test_reencaminhar_nao_reenvia_para_quem_ja_foi_avisado(): void
    {
        $this->encaminhar([$this->ta]);
        $this->encaminhar([$this->ta, $this->tb]);

        $this->assertSame(['admin@example.test', 'marcos@example.test', 'translog@t.test', 'via@t.test'], $this->destinos(CotacaoEncaminhada::class));
    }

    public function test_resposta_e_declinio_vao_para_vendedor_admin_e_so_para_a_transportadora_que_respondeu(): void
    {
        $resp = $this->encaminhar([$this->ta, $this->tb]);
        $this->como($this->ua)->postJson("/api/cotacoes/{$this->cotacaoId}/respostas/{$this->respostaDe($resp, $this->ta)}/responder", ['valor_tonelada' => '180.00', 'prazo_dias' => 3])->assertOk();
        $this->como($this->ub)->postJson("/api/cotacoes/{$this->cotacaoId}/respostas/{$this->respostaDe($resp, $this->tb)}/declinar", ['observacao' => 'sem frota'])->assertOk();

        $this->assertSame(['admin@example.test', 'admin@example.test', 'marcos@example.test', 'marcos@example.test', 'translog@t.test', 'via@t.test'], $this->destinos(RespostaRecebida::class));
    }

    public function test_fechar_avisa_so_a_vencedora_o_vendedor_e_o_admin(): void
    {
        $resp = $this->encaminhar([$this->ta, $this->tb]);
        $this->como($this->ua)->postJson("/api/cotacoes/{$this->cotacaoId}/respostas/{$this->respostaDe($resp, $this->ta)}/responder", ['valor_tonelada' => '180.00', 'prazo_dias' => 3])->assertOk();
        Mail::fake();

        $this->como($this->admin)->postJson("/api/cotacoes/{$this->cotacaoId}/fechar", [
            'transportadora_id' => $this->ta->id, 'valor_tonelada' => '175.00', 'prazo_dias' => 3,
            'programacao' => ['deposito' => 'VIRTUAL', 'modalidade_frete' => 'CIF'],
        ])->assertOk();

        $this->assertSame(['admin@example.test', 'marcos@example.test', 'translog@t.test'], $this->destinos(NegociacaoFechada::class));
    }

    public function test_cancelar_cotacao_avisa_vendedor_admin_e_a_transportadora_que_a_recebeu(): void
    {
        $this->encaminhar([$this->ta]);
        Mail::fake();

        $this->como($this->admin)->postJson("/api/cotacoes/{$this->cotacaoId}/cancelar", ['motivo' => 'cliente desistiu'])->assertOk();

        Mail::assertNotSent(NegociacaoFechada::class);
        $this->assertSame(['admin@example.test', 'marcos@example.test', 'translog@t.test'], DB::table('notificacao')->whereIn('evento', ['COTACAO_CANCELADA', 'NEGOCIACAO_CANCELADA'])
            ->where('canal', 'EMAIL')->pluck('endereco')->unique()->sort()->values()->all());
        $this->assertSame(['translog@t.test'], DB::table('notificacao')->whereIn('evento', ['COTACAO_CANCELADA', 'NEGOCIACAO_CANCELADA'])
            ->where('grupo_codigo', 'TRANSPORTADORA')->where('canal', 'EMAIL')->pluck('endereco')->unique()->values()->all());
    }

    private function fecharComTa(): string
    {
        $resp = $this->encaminhar([$this->ta]);
        $this->como($this->ua)->postJson("/api/cotacoes/{$this->cotacaoId}/respostas/{$this->respostaDe($resp, $this->ta)}/responder", ['valor_tonelada' => '180.00', 'prazo_dias' => 3])->assertOk();

        return $this->como($this->admin)->postJson("/api/cotacoes/{$this->cotacaoId}/fechar", [
            'transportadora_id' => $this->ta->id, 'valor_tonelada' => '175.00', 'prazo_dias' => 3,
            'programacao' => ['deposito' => 'VIRTUAL', 'modalidade_frete' => 'CIF'],
        ])->assertOk()['meta']['programacao']['id'];
    }

    public function test_status_da_carga_avisa_transportadora_da_carga_vendedor_e_admin(): void
    {
        $prog = $this->fecharComTa();
        Mail::fake();

        $this->como($this->ua)->postJson("/api/programacoes/{$prog}/status", ['status' => 'EM_TRANSITO', 'data' => '2026-10-06'])->assertOk();

        $this->assertSame(['admin@example.test', 'marcos@example.test', 'translog@t.test'], $this->destinos(CargaStatusAlterado::class));
    }

    public function test_cada_mudanca_de_status_gera_o_proprio_aviso(): void
    {
        $prog = $this->fecharComTa();
        Mail::fake();

        $this->como($this->ua)->postJson("/api/programacoes/{$prog}/status", ['status' => 'EM_TRANSITO'])->assertOk();
        $this->como($this->admin)->postJson("/api/programacoes/{$prog}/entregar")->assertOk();

        $this->assertSame(6, Mail::sent(CargaStatusAlterado::class)->count());
    }

    public function test_cancelar_carga_avisa_vendedor_admin_e_a_transportadora_da_carga(): void
    {
        $prog = $this->fecharComTa();

        $this->como($this->admin)->postJson("/api/programacoes/{$prog}/cancelar", ['motivo' => 'erro'])->assertOk();

        $linhas = DB::table('notificacao')->where('evento', 'PROGRAMACAO_CANCELADA')->get();
        $this->assertSame(['EMAIL', 'WHATSAPP'], $linhas->pluck('canal')->unique()->sort()->values()->all());
        $this->assertSame(['ADMINISTRADOR', 'TRANSPORTADORA', 'VENDEDOR'], $linhas->pluck('grupo_codigo')->unique()->sort()->values()->all());
    }

    public function test_assunto_traz_o_codigo_e_o_corpo_o_link_do_sistema(): void
    {
        config(['notificacoes.front_url' => 'https://fretes.example.test']);
        $this->encaminhar([$this->ta]);

        Mail::assertSent(CotacaoEncaminhada::class, function ($m) {
            $this->assertStringContainsString('[Fretes COT-', $m->envelope()->subject);
            $html = $m->render();
            $this->assertStringContainsString('https://fretes.example.test', $html);
            $this->assertStringContainsString('não responda este e-mail', mb_strtolower($html));

            return true;
        });
    }

    public function test_falha_no_smtp_nao_reverte_o_negocio_e_marca_a_notificacao_como_falha(): void
    {
        Mail::swap(new \Illuminate\Mail\MailManager(app()));
        Event::listen(MessageSending::class, fn () => throw new \RuntimeException('smtp fora do ar'));

        $this->como($this->admin)->postJson("/api/cotacoes/{$this->cotacaoId}/encaminhar", ['transportadora_ids' => [$this->ta->id]])
            ->assertOk()->assertJsonPath('data.status', 'EM_NEGOCIACAO');

        $n = DB::table('notificacao')->where('canal', 'EMAIL')->first();
        $this->assertSame('FALHA', $n->status);
        $this->assertStringContainsString('smtp fora do ar', $n->erro);
        $this->assertSame(1, DB::table('negociacao_resposta')->count());
    }

    public function test_transportadora_sem_email_vira_alerta_e_linha_com_falha(): void
    {
        $this->ta->update(['email' => null]);

        $r = $this->como($this->admin)->postJson("/api/cotacoes/{$this->cotacaoId}/encaminhar", ['transportadora_ids' => [$this->ta->id]])->assertOk();

        $this->assertCount(1, collect($r['meta']['alertas'])->filter(fn ($a) => str_contains($a, 'TRANSLOG') && str_contains($a, 'e-mail')));
        $this->assertSame('FALHA', DB::table('notificacao')->where('canal', 'EMAIL')->where('grupo_codigo', 'TRANSPORTADORA')->value('status'));
        Mail::assertNotSent(CotacaoEncaminhada::class, fn ($m) => $m->hasTo('translog@t.test'));
    }
}
