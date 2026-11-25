<?php

namespace Tests\Feature;

use App\Models\Cotacao;
use App\Models\Programacao;
use App\Models\Usuario;
use App\Services\Notificacao\DestinatarioResolver;
use App\Services\Notificacao\NotificacaoService;
use App\Services\Notificacao\PreferenciaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** tabelas, destinatários por grupo, preferências e registro idempotente. */
class NotificacaoFundacaoTest extends TestCase
{
    private function cenario(): array
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $admin->update(['email' => 'admin@example.test']);
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $vendedor->update(['email' => 'marcos@example.test']);
        $ta = $this->transportadora('TRANSLOG');
        $ta->update(['email' => 'translog@t.test', 'telefone' => '19999990001']);
        $tb = $this->transportadora('ROTA SUL');
        $tb->update(['email' => 'via@t.test', 'telefone' => '19999990002']);
        $this->usuario('translog', 'TRANSPORTADORA', $ta);
        $this->usuario('rotasul', 'TRANSPORTADORA', $tb);

        $id = $this->como($vendedor)->postJson('/api/cotacoes', $this->corpoCotacao($this->cliente(), $this->produto()))->assertCreated()['data']['id'];

        return [Cotacao::findOrFail($id), $admin, $vendedor, $ta, $tb];
    }

    private function grupos($destinatarios): array
    {
        return collect($destinatarios)->pluck('grupo')->unique()->sort()->values()->all();
    }

    public function test_tabelas_de_notificacao_existem(): void
    {
        $this->assertTrue(Schema::hasColumns('notificacao', [
            'id', 'evento', 'entidade', 'registro_id', 'registro_codigo', 'canal', 'grupo_codigo', 'destino_nome', 'endereco',
            'template', 'payload', 'status', 'tentativas', 'erro', 'idempotencia_key', 'enviado_em', 'correlacao_id',
            'criado_em', 'atualizado_em', 'versao',
        ]));
        $this->assertTrue(Schema::hasColumns('notificacao_preferencia', ['evento', 'canal', 'grupo_codigo', 'ativo', 'criado_em', 'atualizado_em']));
    }

    public function test_fila_em_banco_tem_jobs_e_failed_jobs(): void
    {
        $this->assertTrue(Schema::hasTable('jobs'));
        $this->assertTrue(Schema::hasTable('failed_jobs'));
        $this->assertSame('database', config('queue.connections.database.driver'));
    }

    public function test_encaminhamento_mira_so_as_transportadoras_recem_encaminhadas(): void
    {
        [$cotacao, , , $ta] = $this->cenario();

        $dest = collect(app(DestinatarioResolver::class)->resolver('NEGOCIACAO_ENCAMINHADA', $cotacao, ['transportadora_ids' => [$ta->id]]));

        $this->assertSame(['ADMINISTRADOR', 'TRANSPORTADORA', 'VENDEDOR'], $this->grupos($dest));
        $transportadora = $dest->where('grupo', 'TRANSPORTADORA');
        $this->assertCount(1, $transportadora, 'só a recém-encaminhada, nenhuma outra');
        $this->assertSame('translog@t.test', $transportadora->first()['email']);
        $this->assertSame('19999990001', $transportadora->first()['telefone']);
    }

    public function test_resposta_vai_para_vendedor_admin_e_so_para_a_transportadora_que_respondeu(): void
    {
        [$cotacao, , , $ta] = $this->cenario();

        $dest = collect(app(DestinatarioResolver::class)->resolver('NEGOCIACAO_RESPONDIDA', $cotacao, ['transportadora_ids' => [$ta->id]]));

        $this->assertSame(['ADMINISTRADOR', 'TRANSPORTADORA', 'VENDEDOR'], $this->grupos($dest));
        $this->assertSame([$ta->id], $dest->where('grupo', 'TRANSPORTADORA')->pluck('transportadora_id')->unique()->values()->all());
        $this->assertSame(['ADMINISTRADOR', 'VENDEDOR'], $this->grupos(app(DestinatarioResolver::class)->resolver('NEGOCIACAO_RESPONDIDA', $cotacao)), 'sem transportadora no contexto, nenhuma é avisada');
    }

    public function test_fechamento_inclui_so_a_transportadora_vencedora(): void
    {
        [$cotacao, , , $ta] = $this->cenario();

        $dest = collect(app(DestinatarioResolver::class)->resolver('NEGOCIACAO_FECHADA', $cotacao, ['transportadora_ids' => [$ta->id]]));

        $this->assertSame(['ADMINISTRADOR', 'TRANSPORTADORA', 'VENDEDOR'], $this->grupos($dest));
        $this->assertSame(['translog@t.test'], $dest->where('grupo', 'TRANSPORTADORA')->pluck('email')->all());
    }

    public function test_cancelada_mira_a_transportadora_do_contexto(): void
    {
        [$cotacao, , , $ta] = $this->cenario();

        foreach (['COTACAO_CANCELADA', 'NEGOCIACAO_CANCELADA'] as $evento) {
            $dest = app(DestinatarioResolver::class)->resolver($evento, $cotacao, ['transportadora_ids' => [$ta->id]]);
            $this->assertSame(['ADMINISTRADOR', 'TRANSPORTADORA', 'VENDEDOR'], $this->grupos($dest), $evento);
        }
    }

    public function test_usuario_inativo_nao_recebe(): void
    {
        [$cotacao, $admin] = $this->cenario();
        $admin->forceFill(['situacao' => 'INATIVO'])->save();

        $dest = app(DestinatarioResolver::class)->resolver('NEGOCIACAO_RESPONDIDA', $cotacao);

        $this->assertSame(['VENDEDOR'], $this->grupos($dest));
    }

    public function test_preferencia_usa_default_da_config_e_override_do_banco(): void
    {
        $pref = app(PreferenciaService::class);

        $this->assertTrue($pref->ativo('NEGOCIACAO_ENCAMINHADA', 'WHATSAPP', 'TRANSPORTADORA'));
        $this->assertTrue($pref->ativo('COTACAO_CANCELADA', 'WHATSAPP', 'VENDEDOR'), 'tudo ligado por padrão');
        $this->assertTrue($pref->ativo('COTACAO_CANCELADA', 'EMAIL', 'VENDEDOR'));
        $this->assertTrue($pref->ativo('PROGRAMACAO_QTD_EXCEDIDA', 'WHATSAPP', 'VENDEDOR'));
        $this->assertTrue($pref->ativo('NEGOCIACAO_ENCAMINHADA', 'EMAIL', 'VENDEDOR'));

        $pref->salvar([['evento' => 'COTACAO_CANCELADA', 'canal' => 'WHATSAPP', 'grupo_codigo' => 'VENDEDOR', 'ativo' => false]]);
        $pref->salvar([['evento' => 'NEGOCIACAO_ENCAMINHADA', 'canal' => 'EMAIL', 'grupo_codigo' => 'TRANSPORTADORA', 'ativo' => false]]);

        $this->assertFalse($pref->ativo('COTACAO_CANCELADA', 'WHATSAPP', 'VENDEDOR'));
        $this->assertFalse($pref->ativo('NEGOCIACAO_ENCAMINHADA', 'EMAIL', 'TRANSPORTADORA'));
        $this->assertSame(2, DB::table('notificacao_preferencia')->count());
    }

    public function test_registrar_cria_uma_linha_pendente_por_destinatario_e_canal_ativo(): void
    {
        [$cotacao, , , $ta, $tb] = $this->cenario();

        $criadas = app(NotificacaoService::class)->registrar('NEGOCIACAO_ENCAMINHADA', 'NEGOCIACAO', $cotacao, ['transportadora_ids' => [$ta->id, $tb->id]]);

        $this->assertCount(4, $criadas->where('grupo_codigo', 'TRANSPORTADORA'), '2 transportadoras x (WHATSAPP + EMAIL)');
        $this->assertCount(6, $criadas, 'mais vendedor e administrador, nos dois canais');
        $this->assertSame(6, DB::table('notificacao')->where('status', 'PENDENTE')->count());
        $this->assertSame(2, DB::table('notificacao')->where('canal', 'EMAIL')->where('grupo_codigo', 'TRANSPORTADORA')->distinct()->count('endereco'), '1 envio por transportadora, sem cc coletivo');
        $this->assertSame($cotacao->codigo, DB::table('notificacao')->value('registro_codigo'));
    }

    public function test_registrar_e_idempotente(): void
    {
        [$cotacao, , , $ta] = $this->cenario();
        $svc = app(NotificacaoService::class);

        $svc->registrar('NEGOCIACAO_ENCAMINHADA', 'NEGOCIACAO', $cotacao, ['transportadora_ids' => [$ta->id]]);
        $svc->registrar('NEGOCIACAO_ENCAMINHADA', 'NEGOCIACAO', $cotacao, ['transportadora_ids' => [$ta->id]]);

        $this->assertSame(4, DB::table('notificacao')->count(), 'a segunda chamada não duplica nada');
    }

    public function test_cancelamento_registra_os_dois_canais_para_vendedor_admin_e_transportadora(): void
    {
        [$cotacao, , , $ta] = $this->cenario();

        app(NotificacaoService::class)->registrar('COTACAO_CANCELADA', 'COTACAO', $cotacao, ['transportadora_ids' => [$ta->id]]);

        $linhas = DB::table('notificacao')->get();
        $this->assertSame(['EMAIL', 'WHATSAPP'], $linhas->pluck('canal')->unique()->sort()->values()->all());
        $this->assertSame(['ADMINISTRADOR', 'TRANSPORTADORA', 'VENDEDOR'], $linhas->pluck('grupo_codigo')->unique()->sort()->values()->all());
    }

    public function test_programacao_usa_representante_como_vendedor(): void
    {
        [, $admin, $vendedor] = $this->cenario();
        $prog = new Programacao(['representante_id' => $vendedor->id]);
        $prog->id = 'prog-teste';

        $dest = app(DestinatarioResolver::class)->resolver('PROGRAMACAO_QTD_EXCEDIDA', $prog);

        $this->assertSame(['ADMINISTRADOR', 'VENDEDOR'], $this->grupos($dest));
        $this->assertSame([$vendedor->id], collect($dest)->where('grupo', 'VENDEDOR')->pluck('usuario_id')->all());
        $this->assertInstanceOf(Usuario::class, $admin);
    }
}
