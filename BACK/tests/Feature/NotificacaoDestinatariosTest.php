<?php

namespace Tests\Feature;

use App\Models\Cotacao;
use App\Models\Negociacao;
use App\Models\NegociacaoResposta;
use App\Models\Transportadora;
use App\Models\Usuario;
use App\Services\Notificacao\DestinatarioResolver;
use App\Services\Notificacao\NotificacaoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Contato de cada grupo: vendedor, administrador e gestor = e-mail e telefone do próprio usuário;
 * transportadora = contato geral do cadastro + usuários dela (todos no encaminhamento; quem respondeu nos demais eventos).
 */
class NotificacaoDestinatariosTest extends TestCase
{
    private Cotacao $cotacao;

    private Usuario $vendedor;

    private function cotacao(): Cotacao
    {
        $this->vendedor = $this->usuario('marcos', 'VENDEDOR');
        $this->vendedor->update(['email' => 'marcos@example.test', 'telefone' => '19988880001']);
        $id = $this->como($this->vendedor)->postJson('/api/cotacoes', $this->corpoCotacao($this->cliente(), $this->produto()))->assertCreated()['data']['id'];

        return $this->cotacao = Cotacao::findOrFail($id);
    }

    private function transp(string $nome, ?string $email, ?string $telefone): Transportadora
    {
        $t = $this->transportadora($nome);
        $t->update(['email' => $email, 'telefone' => $telefone]);

        return $t;
    }

    private function usuarioDe(Transportadora $t, string $login, ?string $email, ?string $telefone, string $situacao = 'ATIVO'): Usuario
    {
        $u = $this->usuario($login, 'TRANSPORTADORA', $t);
        $u->update(['email' => $email, 'telefone' => $telefone, 'situacao' => $situacao]);

        return $u;
    }

    private function respondeu(Transportadora $t, Usuario $quem): void
    {
        $neg = Negociacao::firstOrCreate(['cotacao_id' => $this->cotacao->id], ['status' => 'ABERTA']);
        NegociacaoResposta::create([
            'negociacao_id' => $neg->id, 'transportadora_id' => $t->id, 'status' => 'RESPONDIDA', 'encaminhado_em' => now(),
            'encaminhado_por_id' => $this->vendedor->id, 'valor_tonelada' => 100, 'valor_frete' => 1000, 'prazo_dias' => 2,
            'respondido_em' => now(), 'respondido_por_id' => $quem->id,
        ]);
    }

    private function resolver(string $evento, array $ctx = []): \Illuminate\Support\Collection
    {
        return collect(app(DestinatarioResolver::class)->resolver($evento, $this->cotacao, $ctx));
    }

    public function test_usuario_tem_telefone(): void
    {
        $this->assertTrue(Schema::hasColumn('usuario', 'telefone'));
    }

    public function test_vendedor_recebe_no_email_e_telefone_do_usuario(): void
    {
        $this->cotacao();

        $v = $this->resolver('NEGOCIACAO_RESPONDIDA')->firstWhere('grupo', 'VENDEDOR');

        $this->assertSame('marcos@example.test', $v['email']);
        $this->assertSame('19988880001', $v['telefone']);
    }

    public function test_admin_e_gestor_recebem_no_email_e_telefone_de_cada_usuario(): void
    {
        $this->cotacao();
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $admin->update(['email' => 'admin@example.test', 'telefone' => '19988880002']);
        $gestor = $this->usuario('gestor', 'GESTAO_ADMIN');
        $gestor->update(['email' => 'gestor@example.test', 'telefone' => '19988880003']);
        $gestor2 = $this->usuario('gestor2', 'GESTAO_ADMIN');
        $gestor2->update(['email' => 'gestor2@example.test', 'telefone' => '19988880004']);

        $dest = $this->resolver('NEGOCIACAO_RESPONDIDA');

        $a = $dest->where('grupo', 'ADMINISTRADOR');
        $this->assertSame(['admin@example.test'], $a->pluck('email')->all());
        $this->assertSame(['19988880002'], $a->pluck('telefone')->all());
        $g = $dest->where('grupo', 'GESTOR');
        $this->assertEqualsCanonicalizing(['gestor@example.test', 'gestor2@example.test'], $g->pluck('email')->all());
        $this->assertEqualsCanonicalizing(['19988880003', '19988880004'], $g->pluck('telefone')->all());
        $this->assertEqualsCanonicalizing([$gestor->id, $gestor2->id], $g->pluck('usuario_id')->all());
    }

    public function test_gestor_recebe_os_mesmos_eventos_do_admin(): void
    {
        $this->cotacao();
        foreach (array_keys(config('notificacoes.eventos')) as $evento) {
            foreach (['EMAIL', 'WHATSAPP'] as $canal) {
                $pref = app(\App\Services\Notificacao\PreferenciaService::class);
                $this->assertSame($pref->ativo($evento, $canal, 'ADMINISTRADOR'), $pref->ativo($evento, $canal, 'GESTOR'), "$evento/$canal");
            }
        }
        $this->assertContains('GESTOR', config('notificacoes.grupos'));
    }

    public function test_preferencia_aceita_o_grupo_gestor(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');

        $this->como($admin)->postJson('/api/notificacoes/preferencias', [
            'preferencias' => [['evento' => 'NEGOCIACAO_RESPONDIDA', 'canal' => 'EMAIL', 'grupo_codigo' => 'GESTOR', 'ativo' => false]],
        ])->assertOk();

        $this->assertFalse((bool) DB::table('notificacao_preferencia')->where('grupo_codigo', 'GESTOR')->value('ativo'));
    }

    public function test_cotacao_disponivel_vai_ao_geral_e_a_todos_os_usuarios_ativos_da_transportadora(): void
    {
        $this->cotacao();
        $t = $this->transp('TRANSLOG', 'geral@t.test', '19911110000');
        $this->usuarioDe($t, 'ana', 'ana@t.test', '19911110001');
        $this->usuarioDe($t, 'beto', 'beto@t.test', null);
        $this->usuarioDe($t, 'caio', null, '19911110003');
        $this->usuarioDe($t, 'inativo', 'inativo@t.test', '19911110004', 'BLOQUEADO');
        $this->usuarioDe($t, 'semcontato', null, null);
        $outra = $this->transp('OUTRA', 'outra@t.test', '19922220000');
        $this->usuarioDe($outra, 'duda', 'duda@t.test', '19922220001');

        $dest = $this->resolver('NEGOCIACAO_ENCAMINHADA', ['transportadora_ids' => [$t->id]])->where('grupo', 'TRANSPORTADORA');

        $this->assertEqualsCanonicalizing(['geral@t.test', 'ana@t.test', 'beto@t.test', null], $dest->pluck('email')->all());
        $this->assertEqualsCanonicalizing(['19911110000', '19911110001', null, '19911110003'], $dest->pluck('telefone')->all());
        $this->assertSame([$t->id], $dest->pluck('transportadora_id')->unique()->values()->all());
    }

    public function test_demais_eventos_vao_ao_geral_e_so_ao_usuario_que_respondeu(): void
    {
        $this->cotacao();
        $t = $this->transp('TRANSLOG', 'geral@t.test', '19911110000');
        $ana = $this->usuarioDe($t, 'ana', 'ana@t.test', '19911110001');
        $this->usuarioDe($t, 'beto', 'beto@t.test', '19911110002');
        $this->respondeu($t, $ana);

        $dest = $this->resolver('NEGOCIACAO_FECHADA', ['transportadora_ids' => [$t->id]])->where('grupo', 'TRANSPORTADORA');

        $this->assertEqualsCanonicalizing(['geral@t.test', 'ana@t.test'], $dest->pluck('email')->all());
        $this->assertEqualsCanonicalizing(['19911110000', '19911110001'], $dest->pluck('telefone')->all());
    }

    public function test_sem_resposta_do_usuario_so_o_geral_recebe(): void
    {
        $this->cotacao();
        $t = $this->transp('TRANSLOG', 'geral@t.test', '19911110000');
        $this->usuarioDe($t, 'ana', 'ana@t.test', '19911110001');

        $dest = $this->resolver('NEGOCIACAO_FECHADA', ['transportadora_ids' => [$t->id]])->where('grupo', 'TRANSPORTADORA');

        $this->assertSame(['geral@t.test'], $dest->pluck('email')->all());
    }

    public function test_sem_geral_so_o_usuario_recebe(): void
    {
        $this->cotacao();
        $t = $this->transp('TRANSLOG', null, null);
        $ana = $this->usuarioDe($t, 'ana', 'ana@t.test', '19911110001');
        $this->respondeu($t, $ana);

        $dest = $this->resolver('NEGOCIACAO_FECHADA', ['transportadora_ids' => [$t->id]])->where('grupo', 'TRANSPORTADORA');

        $this->assertSame(['ana@t.test'], $dest->pluck('email')->filter()->values()->all());
    }

    public function test_sem_nenhum_dos_dois_nao_manda_nada_e_gera_alerta(): void
    {
        $this->cotacao();
        $t = $this->transp('TRANSLOG', null, null);
        $this->usuarioDe($t, 'ana', null, null);

        $criadas = app(NotificacaoService::class)->registrar('NEGOCIACAO_ENCAMINHADA', 'NEGOCIACAO', $this->cotacao, ['transportadora_ids' => [$t->id]])
            ->where('grupo_codigo', 'TRANSPORTADORA');

        $this->assertCount(0, $criadas->where('status', 'PENDENTE'));
    }

    public function test_endereco_repetido_entre_geral_e_usuario_e_enviado_uma_vez(): void
    {
        $this->cotacao();
        $t = $this->transp('TRANSLOG', 'mesmo@t.test', '19911110000');
        $this->usuarioDe($t, 'ana', 'mesmo@t.test', '19911110000');

        $criadas = app(NotificacaoService::class)->registrar('NEGOCIACAO_ENCAMINHADA', 'NEGOCIACAO', $this->cotacao, ['transportadora_ids' => [$t->id]])
            ->where('grupo_codigo', 'TRANSPORTADORA');

        $this->assertSame(1, $criadas->where('canal', 'EMAIL')->count());
        $this->assertSame(1, $criadas->where('canal', 'WHATSAPP')->count());
    }

    public function test_cada_canal_vai_para_quem_o_tem(): void
    {
        $this->cotacao();
        $t = $this->transp('TRANSLOG', 'geral@t.test', null);
        $this->usuarioDe($t, 'ana', null, '19911110001');

        $criadas = app(NotificacaoService::class)->registrar('NEGOCIACAO_ENCAMINHADA', 'NEGOCIACAO', $this->cotacao, ['transportadora_ids' => [$t->id]])
            ->where('grupo_codigo', 'TRANSPORTADORA');

        $this->assertSame(['geral@t.test'], $criadas->where('canal', 'EMAIL')->pluck('endereco')->all());
        $this->assertCount(1, $criadas->where('canal', 'WHATSAPP')->where('status', 'PENDENTE'));
    }

    public function test_usuario_cadastra_e_edita_telefone(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $u = $this->usuario('joana', 'VENDEDOR');

        $this->como($admin)->patchJson("/api/usuarios/{$u->id}", ['telefone' => '(19) 99999-0001', 'versao' => $u->versao])->assertOk();

        $this->assertSame('19999990001', $u->fresh()->telefone);
        $this->como($admin)->getJson("/api/usuarios/{$u->id}")->assertJsonPath('data.telefone', '19999990001');
    }
}
