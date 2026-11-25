<?php

namespace Tests\Feature;

use App\Models\Transportadora;
use App\Services\Notificacao\PreferenciaService;
use App\Services\Notificacao\Whatsapp\CloudWhatsappGateway;
use App\Services\Notificacao\Whatsapp\WhatsappFalhaDefinitiva;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Grupo de WhatsApp da transportadora: validado na Evolution ao salvar e avisado junto com e-mails e número no encaminhamento. */
class TransportadoraGrupoWhatsappTest extends TestCase
{
    private const JID = '120363123456789012@g.us';

    private function evolution(): void
    {
        config(['whatsapp.driver' => 'evolution', 'whatsapp.evolution' => ['url' => 'http://evo.test:8080', 'apikey' => 'k', 'instance' => 'fretesdt', 'timeout' => 10]]);
    }

    private function corpo(array $extra = []): array
    {
        return $extra + ['razao_social' => 'Rápido Ltda', 'nome_fantasia' => 'Rápido', 'cnpj' => '11222333000181'];
    }

    public function test_coluna_existe(): void
    {
        $this->assertTrue(Schema::hasColumn('transportadora', 'whatsapp_grupo_id'));
    }

    public function test_salva_grupo_existente_na_evolution(): void
    {
        $this->evolution();
        Http::fake(['evo.test:8080/group/findGroupInfos/fretesdt*' => Http::response(['id' => self::JID, 'subject' => 'Rápido'])]);
        $admin = $this->usuario('admin', 'ADMINISTRADOR');

        $r = $this->como($admin)->postJson('/api/transportadoras', $this->corpo(['whatsapp_grupo_id' => self::JID]))->assertCreated();

        $this->assertSame(self::JID, $r['data']['whatsapp_grupo_id']);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/group/findGroupInfos/fretesdt') && str_contains(urldecode($req->url()), self::JID));
    }

    public function test_formato_invalido_e_422_sem_chamar_a_evolution(): void
    {
        $this->evolution();
        Http::fake();
        $admin = $this->usuario('admin', 'ADMINISTRADOR');

        foreach (['12345', '120363123456789012', 'abc@g.us', '5519999990001@s.whatsapp.net'] as $ruim) {
            $this->como($admin)->postJson('/api/transportadoras', $this->corpo(['whatsapp_grupo_id' => $ruim]))
                ->assertStatus(422)->assertJsonValidationErrors('whatsapp_grupo_id');
        }
        Http::assertNothingSent();
    }

    public function test_grupo_inexistente_na_evolution_e_422(): void
    {
        $this->evolution();
        Http::fake(['evo.test:8080/group/findGroupInfos/fretesdt*' => Http::response(['response' => ['message' => 'Not Found']], 404)]);
        $admin = $this->usuario('admin', 'ADMINISTRADOR');

        $this->como($admin)->postJson('/api/transportadoras', $this->corpo(['whatsapp_grupo_id' => self::JID]))
            ->assertStatus(422)->assertJsonValidationErrors('whatsapp_grupo_id');
        $this->assertSame(0, Transportadora::count());
    }

    public function test_evolution_fora_do_ar_e_422_com_mensagem_propria(): void
    {
        $this->evolution();
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $admin = $this->usuario('admin', 'ADMINISTRADOR');

        $r = $this->como($admin)->postJson('/api/transportadoras', $this->corpo(['whatsapp_grupo_id' => self::JID]))->assertStatus(422);

        $this->assertStringContainsString('validar', $r['errors']['whatsapp_grupo_id'][0] ?? json_encode($r->json()));
    }

    public function test_fora_do_driver_evolution_valida_so_o_formato(): void
    {
        Http::fake();
        config(['whatsapp.driver' => 'cloud']);
        $admin = $this->usuario('admin', 'ADMINISTRADOR');

        $this->como($admin)->postJson('/api/transportadoras', $this->corpo(['whatsapp_grupo_id' => self::JID]))->assertCreated();
        Http::assertNothingSent();
    }

    public function test_nao_consulta_a_evolution_se_o_grupo_nao_mudou(): void
    {
        $this->evolution();
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $t = $this->transportadora('Rápido');
        $t->update(['whatsapp_grupo_id' => self::JID]);
        Http::fake();

        $this->como($admin)->patchJson("/api/transportadoras/{$t->id}", ['nome_fantasia' => 'Rápido 2', 'whatsapp_grupo_id' => self::JID, 'versao' => $t->versao])->assertOk();
        Http::assertNothingSent();
    }

    public function test_limpar_o_grupo_nao_consulta_a_evolution(): void
    {
        $this->evolution();
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $t = $this->transportadora('Rápido');
        $t->update(['whatsapp_grupo_id' => self::JID]);
        Http::fake();

        $r = $this->como($admin)->patchJson("/api/transportadoras/{$t->id}", ['whatsapp_grupo_id' => null, 'versao' => $t->versao])->assertOk();

        $this->assertNull($r['data']['whatsapp_grupo_id']);
        Http::assertNothingSent();
    }

    private const JID_B = '120363999888777666@g.us';

    private $admin;

    private $ta;

    private $tb;

    private $tc;

    private $ua;

    private $ub;

    private string $cotacaoId;

    /** A e B com grupo e usuário, C com grupo mas fora da cotação; driver Evolution com a rede simulada. */
    private function cenario(): void
    {
        $this->evolution();
        Http::fake(['evo.test:8080/*' => Http::response(['key' => ['id' => 'BAE5']], 201)]);
        $this->admin = $this->usuario('admin', 'ADMINISTRADOR');
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $this->ta = $this->transportadora('TRANSLOG');
        $this->ta->update(['email' => 'translog@t.test', 'telefone' => '19999990001', 'whatsapp_grupo_id' => self::JID]);
        $this->tb = $this->transportadora('ROTA SUL');
        $this->tb->update(['email' => 'via@t.test', 'telefone' => '19999990002', 'whatsapp_grupo_id' => self::JID_B]);
        $this->tc = $this->transportadora('OUTRA');
        $this->tc->update(['whatsapp_grupo_id' => '120363000111222333@g.us']);
        $this->ua = $this->usuario('translog', 'TRANSPORTADORA', $this->ta);
        $this->ub = $this->usuario('rotasul', 'TRANSPORTADORA', $this->tb);
        $this->cotacaoId = $this->como($vendedor)->postJson('/api/cotacoes', $this->corpoCotacao($this->cliente(), $this->produto()))->assertCreated()['data']['id'];
    }

    private function encaminhar(array $ts): array
    {
        return $this->como($this->admin)->postJson("/api/cotacoes/{$this->cotacaoId}/encaminhar", ['transportadora_ids' => array_map(fn ($t) => $t->id, $ts)])
            ->assertOk()['data']['negociacao']['respostas'];
    }

    private function respostaDe($respostas, $t): string
    {
        return collect($respostas)->firstWhere('transportadora.id', $t->id)['id'];
    }

    private function responder($respostas, $t, $usuario): void
    {
        $this->como($usuario)->postJson("/api/cotacoes/{$this->cotacaoId}/respostas/{$this->respostaDe($respostas, $t)}/responder", ['valor_tonelada' => '180.00', 'prazo_dias' => 3])->assertOk();
    }

    private function fechar($t): string
    {
        return $this->como($this->admin)->postJson("/api/cotacoes/{$this->cotacaoId}/fechar", [
            'transportadora_id' => $t->id, 'valor_tonelada' => '175.00', 'prazo_dias' => 3,
            'programacao' => ['deposito' => 'VIRTUAL', 'modalidade_frete' => 'CIF'],
        ])->assertOk()['meta']['programacao']['id'];
    }

    /** Endereços do grupo (WhatsApp) que receberam o evento. */
    private function grupos(string $evento): array
    {
        return DB::table('notificacao')->where('evento', $evento)->where('grupo_codigo', 'GRUPO_TRANSPORTADORA')
            ->where('canal', 'WHATSAPP')->pluck('endereco')->sort()->values()->all();
    }

    public function test_encaminhar_avisa_o_grupo_e_mantem_geral_e_usuarios(): void
    {
        $this->cenario();
        $this->ta->update(['email' => 'geral@t.test']);
        $this->ua->update(['email' => 'joao@t.test', 'telefone' => null]);

        $this->encaminhar([$this->ta, $this->tb]);

        $this->assertSame([self::JID, self::JID_B], $this->grupos('NEGOCIACAO_ENCAMINHADA'));
        $t = DB::table('notificacao')->where('evento', 'NEGOCIACAO_ENCAMINHADA')->where('grupo_codigo', 'TRANSPORTADORA')->get();
        $this->assertEqualsCanonicalizing(['geral@t.test', 'joao@t.test', 'via@t.test'], $t->where('canal', 'EMAIL')->pluck('endereco')->all());
        $this->assertEqualsCanonicalizing(['5519999990001', '5519999990002'], $t->where('canal', 'WHATSAPP')->pluck('endereco')->all());
        $this->assertSame(0, DB::table('notificacao')->where('status', 'FALHA')->count());
        $this->assertSame(0, DB::table('notificacao')->where('grupo_codigo', 'GRUPO_TRANSPORTADORA')->where('canal', 'EMAIL')->count());
    }

    public function test_so_grupo_sem_telefone_nao_gera_falha(): void
    {
        $this->cenario();
        $this->ta->update(['telefone' => null]);

        $this->como($this->admin)->postJson("/api/cotacoes/{$this->cotacaoId}/encaminhar", ['transportadora_ids' => [$this->ta->id]])->assertOk();

        $this->assertSame([self::JID], $this->grupos('NEGOCIACAO_ENCAMINHADA'));
        $this->assertSame(0, DB::table('notificacao')->where('status', 'FALHA')->count());
    }

    public function test_resposta_vai_so_ao_grupo_de_quem_respondeu(): void
    {
        $this->cenario();
        $resp = $this->encaminhar([$this->ta, $this->tb]);

        $this->responder($resp, $this->ta, $this->ua);

        $this->assertSame([self::JID], $this->grupos('NEGOCIACAO_RESPONDIDA'));
        $this->assertGreaterThan(0, DB::table('notificacao')->where('evento', 'NEGOCIACAO_RESPONDIDA')->where('grupo_codigo', 'TRANSPORTADORA')->where('destino_nome', $this->ta->nome_fantasia)->count());
        $this->assertSame(0, DB::table('notificacao')->where('evento', 'NEGOCIACAO_RESPONDIDA')->where('destino_nome', $this->tb->nome_fantasia)->count());
    }

    public function test_declinio_vai_so_ao_grupo_de_quem_declinou(): void
    {
        $this->cenario();
        $resp = $this->encaminhar([$this->ta, $this->tb]);

        $this->como($this->ub)->postJson("/api/cotacoes/{$this->cotacaoId}/respostas/{$this->respostaDe($resp, $this->tb)}/declinar", [])->assertOk();

        $this->assertSame([self::JID_B], $this->grupos('NEGOCIACAO_DECLINADA'));
    }

    public function test_fechamento_vai_so_ao_grupo_da_vencedora(): void
    {
        $this->cenario();
        $resp = $this->encaminhar([$this->ta, $this->tb]);
        $this->responder($resp, $this->ta, $this->ua);

        $this->fechar($this->ta);

        $this->assertSame([self::JID], $this->grupos('NEGOCIACAO_FECHADA'));
    }

    public function test_cancelamento_da_cotacao_vai_aos_grupos_e_as_pessoas_das_encaminhadas(): void
    {
        $this->cenario();
        $this->encaminhar([$this->ta, $this->tb]);

        $this->como($this->admin)->postJson("/api/cotacoes/{$this->cotacaoId}/cancelar", ['motivo' => 'cliente desistiu'])->assertOk();

        $this->assertSame([self::JID, self::JID_B], $this->grupos('COTACAO_CANCELADA'));
        $this->assertGreaterThan(0, DB::table('notificacao')->where('evento', 'COTACAO_CANCELADA')->where('grupo_codigo', 'TRANSPORTADORA')->count());
    }

    public function test_status_e_cancelamento_da_carga_vao_ao_grupo_da_transportadora_da_carga(): void
    {
        $this->cenario();
        $resp = $this->encaminhar([$this->ta, $this->tb]);
        $this->responder($resp, $this->ta, $this->ua);
        $prog = $this->fechar($this->ta);

        $this->como($this->ua)->postJson("/api/programacoes/{$prog}/status", ['status' => 'EM_TRANSITO', 'data' => '2026-10-06'])->assertOk();
        $this->como($this->admin)->postJson("/api/programacoes/{$prog}/cancelar", ['motivo' => 'erro'])->assertOk();

        $this->assertSame([self::JID], $this->grupos('PROGRAMACAO_STATUS'));
        $this->assertSame([self::JID], $this->grupos('PROGRAMACAO_CANCELADA'));
    }

    public function test_todos_os_eventos_admitem_o_grupo_so_no_whatsapp(): void
    {
        $p = app(PreferenciaService::class);

        foreach (['PROGRAMACAO_QTD_EXCEDIDA', 'PROGRAMACAO_DIVERGE_FECHAMENTO', 'NEGOCIACAO_ENCAMINHADA', 'NEGOCIACAO_RESPONDIDA', 'NEGOCIACAO_DECLINADA', 'NEGOCIACAO_FECHADA', 'COTACAO_CANCELADA', 'NEGOCIACAO_CANCELADA', 'PROGRAMACAO_STATUS', 'PROGRAMACAO_CANCELADA'] as $evento) {
            $this->assertContains('GRUPO_TRANSPORTADORA', $p->gruposPermitidos($evento), $evento);
            $this->assertTrue($p->ativo($evento, 'WHATSAPP', 'GRUPO_TRANSPORTADORA'), $evento);
            $this->assertFalse($p->ativo($evento, 'EMAIL', 'GRUPO_TRANSPORTADORA'), $evento);
        }
    }

    public function test_matriz_so_tem_whatsapp_para_o_grupo_e_a_gravacao_recusa_email(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $linhas = collect(app(PreferenciaService::class)->matriz())->where('grupo_codigo', 'GRUPO_TRANSPORTADORA');

        $this->assertNotEmpty($linhas);
        $this->assertSame(['WHATSAPP'], $linhas->pluck('canal')->unique()->values()->all());
        $this->como($admin)->postJson('/api/notificacoes/preferencias', [
            'preferencias' => [['evento' => 'NEGOCIACAO_FECHADA', 'canal' => 'EMAIL', 'grupo_codigo' => 'GRUPO_TRANSPORTADORA', 'ativo' => true]],
        ])->assertStatus(422);
    }

    public function test_desligar_um_evento_na_matriz_suprime_so_o_grupo_dele(): void
    {
        $this->cenario();
        $this->como($this->admin)->postJson('/api/notificacoes/preferencias', [
            'preferencias' => [['evento' => 'NEGOCIACAO_FECHADA', 'canal' => 'WHATSAPP', 'grupo_codigo' => 'GRUPO_TRANSPORTADORA', 'ativo' => false]],
        ])->assertOk();
        $resp = $this->encaminhar([$this->ta]);
        $this->responder($resp, $this->ta, $this->ua);

        $this->fechar($this->ta);

        $this->assertSame([self::JID], $this->grupos('NEGOCIACAO_ENCAMINHADA'));
        $this->assertSame([], $this->grupos('NEGOCIACAO_FECHADA'));
        $this->assertSame(1, DB::table('notificacao')->where('evento', 'NEGOCIACAO_FECHADA')->where('grupo_codigo', 'TRANSPORTADORA')->where('canal', 'EMAIL')->count());
    }

    public function test_fora_do_driver_evolution_nao_cria_notificacao_de_grupo(): void
    {
        $this->cenario();
        config(['whatsapp.driver' => 'null']);

        $this->encaminhar([$this->ta]);

        $this->assertSame([], $this->grupos('NEGOCIACAO_ENCAMINHADA'));
        $this->assertSame(0, DB::table('notificacao')->where('status', 'FALHA')->count());
    }

    public function test_sem_grupo_cadastrado_nada_muda(): void
    {
        $this->cenario();
        $this->ta->update(['whatsapp_grupo_id' => null]);

        $this->encaminhar([$this->ta]);

        $this->assertSame([], $this->grupos('NEGOCIACAO_ENCAMINHADA'));
        $this->assertSame(['5519999990001'], DB::table('notificacao')->where('evento', 'NEGOCIACAO_ENCAMINHADA')->where('canal', 'WHATSAPP')->pluck('endereco')->all());
    }

    public function test_cloud_recusa_grupo_como_falha_definitiva(): void
    {
        config(['whatsapp.driver' => 'cloud']);
        Http::fake();

        $this->expectException(WhatsappFalhaDefinitiva::class);
        app(CloudWhatsappGateway::class)->enviar(self::JID, 'cotacao_disponivel', ['C-1', 'A → B', '12000', 'https://x.test']);
    }
}
