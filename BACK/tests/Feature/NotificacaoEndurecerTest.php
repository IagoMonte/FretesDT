<?php

namespace Tests\Feature;

use App\Jobs\EnviarEmailJob;
use App\Jobs\EnviarWhatsappJob;
use App\Models\Notificacao;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** rate-limit do WhatsApp, métricas no painel e correlação com os logs. */
class NotificacaoEndurecerTest extends TestCase
{
    private function linha(array $extra = []): Notificacao
    {
        static $n = 0;

        return Notificacao::create($extra + [
            'evento' => 'NEGOCIACAO_ENCAMINHADA', 'entidade' => 'NEGOCIACAO', 'registro_id' => 'x', 'registro_codigo' => 'COT-000001',
            'canal' => 'EMAIL', 'grupo_codigo' => 'TRANSPORTADORA', 'destino_nome' => 'TRANSLOG', 'endereco' => 'translog@t.test',
            'template' => 'cotacao_disponivel', 'status' => 'PENDENTE', 'tentativas' => 0, 'idempotencia_key' => 'e'.++$n,
        ]);
    }

    public function test_whatsapp_tem_rate_limit_para_respeitar_o_limite_da_meta(): void
    {
        $job = new EnviarWhatsappJob('x');

        $this->assertNotNull(RateLimiter::limiter('whatsapp'));
        $this->assertTrue(collect($job->middleware())->contains(fn ($m) => $m instanceof RateLimited));
    }

    public function test_painel_mostra_pendentes_e_falhas_das_ultimas_24h_so_para_quem_ve_notificacoes(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $gestao = $this->usuario('gestao', 'GESTAO_ADMIN');
        $this->linha();
        $this->linha(['status' => 'FALHA', 'erro' => 'x']);
        $velha = $this->linha(['status' => 'FALHA', 'erro' => 'x']);
        DB::table('notificacao')->where('id', $velha->id)->update(['atualizado_em' => now()->subHours(30)->format('Y-m-d H:i:s.v')]);
        $this->linha(['status' => 'ENVIADO']);

        $d = $this->como($admin)->getJson('/api/painel')->assertOk()['data'];
        $this->assertSame(['pendentes' => 1, 'falhas_24h' => 1], $d['notificacoes']);

        $this->assertArrayNotHasKey('notificacoes', $this->como($gestao)->getJson('/api/painel')->assertOk()['data']);
    }

    public function test_notificacao_e_log_de_operacao_compartilham_a_correlacao_da_requisicao(): void
    {
        Mail::fake();
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $ta = $this->transportadora('TRANSLOG');
        $id = $this->como($vendedor)->postJson('/api/cotacoes', $this->corpoCotacao($this->cliente(), $this->produto()))->assertCreated()['data']['id'];

        $this->como($admin)->postJson("/api/cotacoes/{$id}/encaminhar", ['transportadora_ids' => [$ta->id]])->assertOk();

        $correlacao = DB::table('log_operacao')->where('evento', 'NEGOCIACAO_ENCAMINHADA')->value('correlacao_id');
        $this->assertNotNull($correlacao);
        $this->assertSame([$correlacao], DB::table('notificacao')->pluck('correlacao_id')->unique()->values()->all());
    }

    public function test_falha_definitiva_de_envio_vai_para_o_log_do_sistema_como_integracao_com_a_mesma_correlacao(): void
    {
        $corr = (string) \Illuminate\Support\Str::uuid();
        $n = $this->linha(['correlacao_id' => $corr]);

        (new EnviarEmailJob($n->id))->failed(new \RuntimeException('smtp caiu'));

        $this->assertSame('FALHA', $n->refresh()->status);
        $log = DB::table('log_sistema')->where('categoria', 'INTEGRACAO')->first();
        $this->assertNotNull($log);
        $this->assertSame($corr, $log->correlacao_id);
        $this->assertStringContainsString('smtp caiu', $log->mensagem);
    }
}
