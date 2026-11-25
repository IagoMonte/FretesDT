<?php

namespace Tests\Feature;

use App\Mail\NegociacaoFechada;
use App\Mail\RespostaRecebida;
use App\Models\Embalagem;
use App\Models\LogOperacao;
use App\Models\Programacao;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Prazo de coleta (dias): campo informativo ao lado do prazo de entrega; obrigatório só na cotação fracionada. */
class PrazoColetaTest extends TestCase
{
    private $admin;

    private $vendedor;

    private $ta;

    private $ua;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->admin = $this->usuario('admin', 'ADMINISTRADOR');
        $this->admin->update(['email' => 'admin@example.test']);
        $this->vendedor = $this->usuario('marcos', 'VENDEDOR');
        $this->vendedor->update(['email' => 'marcos@example.test']);
        $this->ta = $this->transportadora('TRANSLOG');
        $this->ta->update(['email' => 'translog@t.test']);
        $this->ua = $this->usuario('translog', 'TRANSPORTADORA', $this->ta);
    }

    /** @return array{0: string, 1: string} [id da cotação, id da resposta] */
    private function cotacao(bool $fracionada = false): array
    {
        if ($fracionada) {
            Embalagem::where('codigo', 'BOMBONA')->update(['fracionada' => true]);
            $corpo = $this->corpoCotacao($this->cliente(), $this->produto(), ['tipo_veiculo_codigo' => null]);
        } else {
            $corpo = $this->corpoCotacao($this->cliente(), $this->produto());
        }
        $id = $this->como($this->vendedor)->postJson('/api/cotacoes', $corpo)->assertCreated()['data']['id'];
        $e = $this->como($this->admin)->postJson("/api/cotacoes/{$id}/encaminhar", ['transportadora_ids' => [$this->ta->id]])->assertOk();

        return [$id, $e['data']['negociacao']['respostas'][0]['id']];
    }

    private function corpoResposta(bool $fracionada, array $extra = []): array
    {
        $base = $fracionada ? ['valor_frete' => '2500.00', 'tipo_veiculo_codigo' => 'TRUCK'] : ['valor_tonelada' => '180.00'];

        return $extra + $base + ['prazo_dias' => 3];
    }

    private function responder(string $id, string $resp, array $corpo)
    {
        return $this->como($this->ua)->postJson("/api/cotacoes/{$id}/respostas/{$resp}/responder", $corpo);
    }

    private function fechar(string $id, array $extra = [], bool $fracionada = false)
    {
        $base = $fracionada ? ['valor_frete' => '2400.00'] : ['valor_tonelada' => '175.00'];

        return $this->como($this->admin)->postJson("/api/cotacoes/{$id}/fechar", $extra + $base + [
            'transportadora_id' => $this->ta->id, 'negociado_por_fora' => true, 'prazo_dias' => 3, 'programacao' => ['deposito' => 'VIRTUAL', 'modalidade_frete' => 'CIF'],
        ]);
    }

    public function test_responder_grava_e_expoe_prazo_de_entrega_e_de_coleta(): void
    {
        [$id, $resp] = $this->cotacao();
        $r = $this->responder($id, $resp, $this->corpoResposta(false, ['prazo_coleta_dias' => 1]))->assertOk();
        $this->assertSame(3, $r['data']['negociacao']['respostas'][0]['prazo_dias']);
        $this->assertSame(1, $r['data']['negociacao']['respostas'][0]['prazo_coleta_dias']);
        $this->assertDatabaseHas('negociacao_resposta', ['id' => $resp, 'prazo_dias' => 3, 'prazo_coleta_dias' => 1]);
    }

    public function test_fechar_grava_na_negociacao_e_copia_para_a_programacao(): void
    {
        [$id] = $this->cotacao();
        $f = $this->fechar($id, ['prazo_coleta_dias' => 2])->assertOk();
        $this->assertSame(2, $f['data']['negociacao']['fechamento']['prazo_coleta_dias']);
        $this->assertSame(2, $f['meta']['programacao']['prazo_coleta_dias']);
        $this->assertDatabaseHas('negociacao', ['cotacao_id' => $id, 'prazo_coleta_dias' => 2]);
        $this->assertSame(2, Programacao::first()->prazo_coleta_dias);
        $this->como($this->admin)->getJson('/api/programacoes/'.$f['meta']['programacao']['id'])->assertOk()->assertJsonPath('data.prazo_coleta_dias', 2);
    }

    public function test_limites_do_prazo_de_coleta(): void
    {
        [$id, $resp] = $this->cotacao();
        foreach ([-1, 91, 'abc', 1.5] as $ruim) {
            $r = $this->responder($id, $resp, $this->corpoResposta(false, ['prazo_coleta_dias' => $ruim]))
                ->assertStatus(422)->assertJsonValidationErrors('prazo_coleta_dias');
            $this->assertStringContainsString('prazo de coleta', $r->json('errors.prazo_coleta_dias.0'));
        }
        $this->fechar($id, ['prazo_coleta_dias' => 91])->assertStatus(422)->assertJsonValidationErrors('prazo_coleta_dias');
        $r = $this->responder($id, $resp, $this->corpoResposta(false, ['prazo_coleta_dias' => 0]))->assertOk();
        $this->assertSame(0, $r['data']['negociacao']['respostas'][0]['prazo_coleta_dias']);
    }

    public function test_ausente_na_cotacao_comum_e_aceito_e_fica_nulo(): void
    {
        [$id, $resp] = $this->cotacao();
        $r = $this->responder($id, $resp, $this->corpoResposta(false))->assertOk();
        $this->assertNull($r['data']['negociacao']['respostas'][0]['prazo_coleta_dias']);
        $f = $this->fechar($id)->assertOk();
        $this->assertNull($f['data']['negociacao']['fechamento']['prazo_coleta_dias']);
        $this->assertNull($f['meta']['programacao']['prazo_coleta_dias']);
    }

    public function test_ausente_na_cotacao_fracionada_da_422_na_resposta_e_no_fechamento(): void
    {
        [$id, $resp] = $this->cotacao(true);
        $this->responder($id, $resp, $this->corpoResposta(true))->assertStatus(422)->assertJsonValidationErrors('prazo_coleta_dias');
        $this->fechar($id, [], true)->assertStatus(422)->assertJsonValidationErrors('prazo_coleta_dias');

        $this->responder($id, $resp, $this->corpoResposta(true, ['prazo_coleta_dias' => 1]))->assertOk();
        $this->fechar($id, ['prazo_coleta_dias' => 1, 'tipo_veiculo_codigo' => 'TRUCK'], true)->assertOk();
    }

    public function test_declinar_zera_o_prazo_de_coleta(): void
    {
        [$id, $resp] = $this->cotacao();
        $this->responder($id, $resp, $this->corpoResposta(false, ['prazo_coleta_dias' => 2]))->assertOk();
        $this->como($this->ua)->postJson("/api/cotacoes/{$id}/respostas/{$resp}/declinar", ['observacao' => 'sem frota'])->assertOk();
        $this->assertNull(\Illuminate\Support\Facades\DB::table('negociacao_resposta')->where('id', $resp)->value('prazo_coleta_dias'));
    }

    public function test_patch_da_programacao_edita_e_registra_no_log(): void
    {
        [$id] = $this->cotacao();
        $p = $this->fechar($id, ['prazo_coleta_dias' => 2])->assertOk()['meta']['programacao'];
        $r = $this->como($this->admin)->patchJson("/api/programacoes/{$p['id']}", ['versao' => $p['versao'], 'prazo_coleta_dias' => 4])->assertOk();
        $this->assertSame(4, $r['data']['prazo_coleta_dias']);
        $log = LogOperacao::where('evento', 'PROGRAMACAO_EDITADA')->latest('id')->first();
        $this->assertStringContainsString('prazo_coleta_dias', json_encode($log->getAttributes()));
        $this->como($this->admin)->patchJson("/api/programacoes/{$p['id']}", ['versao' => $r['data']['versao'], 'prazo_coleta_dias' => 999])
            ->assertStatus(422)->assertJsonValidationErrors('prazo_coleta_dias');
    }

    public function test_legado_sem_prazo_de_coleta_sai_nulo_no_resource(): void
    {
        [$id] = $this->cotacao();
        $p = $this->fechar($id)->assertOk()['meta']['programacao'];
        Programacao::query()->update(['prazo_coleta_dias' => null]);
        $this->como($this->admin)->getJson("/api/programacoes/{$p['id']}")->assertOk()->assertJsonPath('data.prazo_coleta_dias', null);
    }

    public function test_emails_de_resposta_e_fechamento_trazem_o_prazo_de_coleta(): void
    {
        [$id, $resp] = $this->cotacao();
        $this->responder($id, $resp, $this->corpoResposta(false, ['prazo_coleta_dias' => 7]))->assertOk();
        $html = Mail::sent(RespostaRecebida::class)->first()->render();
        $this->assertMatchesRegularExpression('/Prazo de coleta \(dias\).*?7/s', $html);

        $this->fechar($id, ['prazo_coleta_dias' => 5])->assertOk();
        $html = Mail::sent(NegociacaoFechada::class)->first()->render();
        $this->assertMatchesRegularExpression('/Prazo de coleta \(dias\).*?5/s', $html);
    }

    public function test_colunas_existem_nas_tres_tabelas(): void
    {
        foreach (['negociacao', 'negociacao_resposta', 'programacao'] as $t) {
            $this->assertTrue(Schema::hasColumn($t, 'prazo_coleta_dias'), $t);
        }
    }

    public function test_campo_e_informativo_e_nao_mexe_em_datas_nem_no_prazo(): void
    {
        [$id] = $this->cotacao();
        $f = $this->fechar($id, [
            'prazo_coleta_dias' => 30,
            'programacao' => ['deposito' => 'VIRTUAL', 'modalidade_frete' => 'CIF', 'data_coleta_prevista' => '2026-11-10', 'data_entrega_prevista' => '2026-11-12'],
        ])->assertOk()['meta']['programacao'];
        $this->assertSame('2026-11-10', $f['data_coleta_prevista']);
        $this->assertSame('2026-11-12', $f['data_entrega_prevista']);
        $this->assertSame(3, $f['prazo_dias']);
        $this->assertNull($f['entregue_no_prazo']);

        $r = $this->como($this->admin)->patchJson("/api/programacoes/{$f['id']}", ['versao' => $f['versao'], 'prazo_coleta_dias' => 1])->assertOk();
        $this->assertSame('2026-11-10', $r['data']['data_coleta_prevista']);
        $this->assertSame('2026-11-12', $r['data']['data_entrega_prevista']);
        $this->assertSame(3, $r['data']['prazo_dias']);
    }
}
