<?php

namespace Tests\Feature;

use App\Models\Cotacao;
use App\Models\NegociacaoResposta;
use App\Models\Produto;
use App\Models\Transportadora;
use App\Models\Usuario;
use App\Support\Dinheiro;
use App\Support\RecalculaFretes;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Contrato v1.1 item 5: cotação repetida de granel líquido vinculada à original (referência viva). */
class CotacaoRepetidaTest extends TestCase
{
    private Usuario $admin;

    private Usuario $vendedor;

    private Transportadora $ta;

    private Transportadora $tb;

    private Usuario $ua;

    private Usuario $ub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->usuario('admin', 'ADMINISTRADOR');
        $this->vendedor = $this->usuario('marcos', 'VENDEDOR');
        $this->ta = $this->transportadora('TRANSLOG');
        $this->tb = $this->transportadora('ROTASUL');
        $this->ua = $this->usuario('translog', 'TRANSPORTADORA', $this->ta);
        $this->ub = $this->usuario('rotasul', 'TRANSPORTADORA', $this->tb);
    }

    private ?Produto $padrao = null;

    /** Produto usado por padrão: as cotações que querem repetir a anterior precisam do mesmo produto (descrição). */
    private function produtoPadrao(): Produto
    {
        return $this->padrao ??= $this->produto('SODA', ['BOMBONA', 'GRANEL_LIQUIDO', 'GRANEL_SOLIDO']);
    }

    private function cotar(array $extra = [], string $embalagem = 'GRANEL_LIQUIDO', string $kg = '10000', ?Produto $produto = null): TestResponse
    {
        $produto ??= $this->produtoPadrao();
        $corpo = $this->corpoCotacao($this->cliente(), $produto, $extra);
        $corpo['itens'] = [['produto_id' => $produto->id, 'embalagem_codigo' => $embalagem, 'quantidade_kg' => $kg]];

        return $this->como($this->vendedor)->postJson('/api/cotacoes', $corpo)->assertCreated();
    }

    /** Original (10 t) encaminhada a TRANSLOG, que responde 90 R$/t. */
    private function original(): TestResponse
    {
        $r = $this->cotar();
        $this->responderNaOriginal($r['data']['id'], $this->ta, $this->ua, '90');

        return $r;
    }

    private function responderNaOriginal(string $id, Transportadora $t, Usuario $u, string $valor): void
    {
        $this->como($this->admin)->postJson("/api/cotacoes/{$id}/encaminhar", ['transportadora_ids' => [$t->id]])->assertOk();
        $resp = NegociacaoResposta::where('transportadora_id', $t->id)->whereHas('negociacao', fn ($n) => $n->where('cotacao_id', $id))->value('id');
        $this->como($u)->postJson("/api/cotacoes/{$id}/respostas/{$resp}/responder", ['valor_tonelada' => $valor, 'prazo_dias' => 2])->assertOk();
    }

    public function test_vinculo_criado_com_alerta_status_e_sem_duplicar_respostas(): void
    {
        $orig = $this->original();
        $this->assertNull($orig['data']['cotacao_origem']);

        $nova = $this->cotar(['origem' => ['cidade' => 'PAULINIA', 'uf' => 'sp'], 'destino' => ['cidade' => 'campinas', 'uf' => 'SP']], kg: '20000');

        $this->assertSame($orig['data']['id'], $nova['data']['cotacao_origem']['id']);
        $this->assertSame($orig['data']['codigo'], $nova['data']['cotacao_origem']['codigo']);
        $this->assertSame('EM_NEGOCIACAO', $nova['data']['status']);
        $this->assertSame(["Cotação repetida: usando as respostas da {$orig['data']['codigo']}."], $nova['meta']['alertas']);
        $this->assertSame($orig['data']['id'], Cotacao::find($nova['data']['id'])->cotacao_origem_id);

        $respostas = $nova['data']['negociacao']['respostas'];
        $this->assertCount(1, $respostas);
        $this->assertSame('REFERENCIADA', $respostas[0]['origem']);
        $this->assertSame($orig['data']['codigo'], $respostas[0]['cotacao_codigo']);
        $this->assertSame('RESPONDIDA', $respostas[0]['status']);
        $this->assertSame('90.00', $respostas[0]['valor_tonelada']);
        $this->assertSame('1800.00', $respostas[0]['valor_frete']);
        $this->assertSame(1, NegociacaoResposta::count());

        $o = $this->como($this->vendedor)->getJson('/api/cotacoes/'.$orig['data']['id'])->json('data.negociacao.respostas.0');
        $this->assertSame('PROPRIA', $o['origem']);
        $this->assertSame('900.00', $o['valor_frete']);
    }

    public function test_mudancas_na_original_aparecem_na_nova_e_o_valor_e_recalculado(): void
    {
        $orig = $this->original();
        $oid = $orig['data']['id'];
        $novaId = $this->cotar(kg: '25000')['data']['id'];

        $resp = NegociacaoResposta::first();
        $this->como($this->ua)->postJson("/api/cotacoes/{$oid}/respostas/{$resp->id}/responder", ['valor_tonelada' => '100.50', 'prazo_dias' => 4])->assertOk();
        $r = $this->como($this->vendedor)->getJson("/api/cotacoes/{$novaId}")->json('data.negociacao.respostas');
        $this->assertSame('100.50', $r[0]['valor_tonelada']);
        $this->assertSame(4, $r[0]['prazo_dias']);
        $this->assertSame('2512.50', $r[0]['valor_frete']);

        $this->responderNaOriginal($oid, $this->tb, $this->ub, '80');
        $r = $this->como($this->vendedor)->getJson("/api/cotacoes/{$novaId}")->json('data.negociacao.respostas');
        $this->assertCount(2, $r);
        $this->assertSame(['REFERENCIADA', 'REFERENCIADA'], array_column($r, 'origem'));
        $this->assertSame('2000.00', collect($r)->firstWhere('transportadora.id', $this->tb->id)['valor_frete']);

        $resp = NegociacaoResposta::where('transportadora_id', $this->tb->id)->first();
        $this->como($this->ub)->postJson("/api/cotacoes/{$oid}/respostas/{$resp->id}/declinar")->assertOk();
        $r = $this->como($this->vendedor)->getJson("/api/cotacoes/{$novaId}")->json('data.negociacao.respostas');
        $declinada = collect($r)->firstWhere('transportadora.id', $this->tb->id);
        $this->assertSame('DECLINADA', $declinada['status']);
        $this->assertNull($declinada['valor_frete']);
    }

    public function test_resposta_referenciada_e_somente_leitura(): void
    {
        $this->original();
        $nova = $this->cotar();
        $nid = $nova['data']['id'];
        $rid = $nova['data']['negociacao']['respostas'][0]['id'];

        foreach (['responder' => ['valor_tonelada' => '50', 'prazo_dias' => 1], 'declinar' => []] as $acao => $corpo) {
            $this->como($this->ua)->postJson("/api/cotacoes/{$nid}/respostas/{$rid}/{$acao}", $corpo)->assertStatus(422);
            $this->como($this->admin)->postJson("/api/cotacoes/{$nid}/respostas/{$rid}/{$acao}", $corpo)->assertStatus(422);
        }
        $this->como($this->ub)->postJson("/api/cotacoes/{$nid}/respostas/{$rid}/responder", ['valor_tonelada' => '1', 'prazo_dias' => 1])->assertNotFound();
        $this->assertSame('90.00', NegociacaoResposta::first()->valor_tonelada);
    }

    public function test_encaminhar_so_cria_resposta_para_quem_nao_tem_e_fechar_aceita_referenciada(): void
    {
        $this->original();
        $nova = $this->cotar(kg: '20000');
        $nid = $nova['data']['id'];

        $e = $this->como($this->admin)->postJson("/api/cotacoes/{$nid}/encaminhar", ['transportadora_ids' => [$this->ta->id, $this->tb->id]])->assertOk();
        $rs = collect($e['data']['negociacao']['respostas']);
        $this->assertCount(2, $rs);
        $this->assertSame('REFERENCIADA', $rs->firstWhere('transportadora.id', $this->ta->id)['origem']);
        $propria = $rs->firstWhere('transportadora.id', $this->tb->id);
        $this->assertSame('PROPRIA', $propria['origem']);
        $this->assertSame('AGUARDANDO', $propria['status']);
        $this->assertSame(2, NegociacaoResposta::count());

        $this->como($this->ub)->postJson("/api/cotacoes/{$nid}/respostas/{$propria['id']}/responder", ['valor_tonelada' => '70', 'prazo_dias' => 1])
            ->assertOk()->assertJsonPath('data.negociacao.respostas.0.valor_frete', '1400.00');

        $f = $this->como($this->admin)->postJson("/api/cotacoes/{$nid}/fechar", ['transportadora_id' => $this->ta->id, 'valor_tonelada' => '90.00', 'prazo_dias' => 2])->assertOk();
        $this->assertSame('PROGRAMADA', $f['data']['status']);
        $this->assertSame('1800.00', $f['data']['negociacao']['fechamento']['valor_frete']);
        $this->assertSame('1800.00', $f['meta']['programacao']['valor_total']);
    }

    public function test_janela_de_60_dias_sem_validade_usa_a_data_da_original(): void
    {
        $orig = $this->original();
        $hoje = now('America/Sao_Paulo');

        Cotacao::whereKey($orig['data']['id'])->update(['data_solicitacao' => $hoje->copy()->subDays(60)->toDateString()]);
        $dentro = $this->cotar();
        $this->assertSame($orig['data']['id'], $dentro['data']['cotacao_origem']['id']);

        Cotacao::whereKey($orig['data']['id'])->update(['data_solicitacao' => $hoje->copy()->subDays(61)->toDateString()]);
        $fora = $this->cotar();
        $this->assertNull($fora['data']['cotacao_origem']);
        $this->assertSame('ABERTA', $fora['data']['status']);
        $this->assertSame([], $fora['meta']['alertas']);
    }

    public function test_usar_anterior_false_nao_vincula(): void
    {
        $this->original();
        $nova = $this->cotar(['usar_anterior' => false]);

        $this->assertNull($nova['data']['cotacao_origem']);
        $this->assertSame('ABERTA', $nova['data']['status']);
        $this->assertSame([], $nova['data']['negociacao']['respostas']);
        $this->assertSame([], $nova['meta']['alertas']);
        $this->assertNull(Cotacao::find($nova['data']['id'])->cotacao_origem_id);
    }

    public function test_nunca_vincula_a_um_vinculo_e_segue_ate_a_original(): void
    {
        $orig = $this->original();
        $segunda = $this->cotar();
        $terceira = $this->cotar();

        $this->assertSame($orig['data']['id'], $segunda['data']['cotacao_origem']['id']);
        $this->assertSame($orig['data']['id'], $terceira['data']['cotacao_origem']['id']);
        $this->assertCount(1, $terceira['data']['negociacao']['respostas']);
    }

    public function test_nao_vincula_quando_nao_casa_com_os_criterios(): void
    {
        $orig = $this->original();

        $this->assertNull($this->cotar(['destino' => ['cidade' => 'Sorocaba', 'uf' => 'SP']])['data']['cotacao_origem']);
        $this->assertNull($this->cotar(['tipo_veiculo_codigo' => 'TRUCK'])['data']['cotacao_origem']);
        $this->assertNull($this->cotar(['destino' => ['cidade' => 'Campinas', 'uf' => 'MG']])['data']['cotacao_origem']);
        $this->assertNull($this->cotar(embalagem: 'BOMBONA')['data']['cotacao_origem']);
        $this->assertNull($this->cotar(embalagem: 'GRANEL_SOLIDO')['data']['cotacao_origem']);

        $this->como($this->admin)->postJson('/api/cotacoes/'.$orig['data']['id'].'/cancelar', ['motivo' => 'teste'])->assertOk();
        $this->assertNull($this->cotar()['data']['cotacao_origem']);
        $semResposta = $this->cotar(['origem' => ['cidade' => 'Santos', 'uf' => 'SP']]);
        $this->assertNull($this->cotar(['origem' => ['cidade' => 'Santos', 'uf' => 'SP']])['data']['cotacao_origem']);
        $this->assertNull($semResposta['data']['cotacao_origem']);
    }

    public function test_escopo_da_transportadora_inclui_vinculadas_e_mostra_so_a_propria_resposta(): void
    {
        $orig = $this->original();
        $this->responderNaOriginal($orig['data']['id'], $this->tb, $this->ub, '80');
        $outraTransp = $this->transportadora('OUTRA');
        $uc = $this->usuario('outra', 'TRANSPORTADORA', $outraTransp);
        $nid = $this->cotar()['data']['id'];

        $d = $this->como($this->ua)->getJson("/api/cotacoes/{$nid}")->assertOk()->json('data');
        $this->assertNull($d['cotacao_origem']);
        $this->assertCount(1, $d['negociacao']['respostas']);
        $this->assertSame($this->ta->id, $d['negociacao']['respostas'][0]['transportadora']['id']);
        $this->assertSame('PROPRIA', $d['negociacao']['respostas'][0]['origem']);
        $this->assertNull($d['negociacao']['respostas'][0]['cotacao_codigo']);
        $this->assertStringNotContainsString($orig['data']['codigo'], json_encode($d));
        $this->assertContains($nid, array_column($this->getJson('/api/cotacoes')->json('data'), 'id'));

        $this->como($uc)->getJson("/api/cotacoes/{$nid}")->assertNotFound();
        $this->assertNotContains($nid, array_column($this->getJson('/api/cotacoes')->json('data'), 'id'));

        $i = $this->como($this->admin)->getJson("/api/cotacoes/{$nid}")->json('data');
        $this->assertCount(2, $i['negociacao']['respostas']);
        $this->assertNotNull($i['cotacao_origem']);
    }

    public function test_previa_acha_com_normalizacao_e_transportadora_recebe_403(): void
    {
        $orig = $this->original();
        $pid = $this->produtoPadrao()->id;
        $q = http_build_query(['tipo_veiculo_codigo' => 'CARRETA', 'origem_cidade' => 'paulínia', 'origem_uf' => 'SP', 'destino_cidade' => 'CAMPINAS', 'destino_uf' => 'sp', 'produto_ids' => $pid]);

        $r = $this->como($this->vendedor)->getJson("/api/cotacoes/anterior?{$q}")->assertOk();
        $this->assertSame($orig['data']['id'], $r['data']['id']);
        $this->assertCount(1, $r['data']['respostas']);
        $this->assertSame('RESPONDIDA', $r['data']['respostas'][0]['status']);
        $this->como($this->admin)->getJson("/api/cotacoes/anterior?{$q}")->assertOk()->assertJsonPath('data.id', $orig['data']['id']);

        $this->como($this->ua)->getJson("/api/cotacoes/anterior?{$q}")->assertForbidden();

        $outra = http_build_query(['tipo_veiculo_codigo' => 'CARRETA', 'origem_cidade' => 'Santos', 'origem_uf' => 'SP', 'destino_cidade' => 'Campinas', 'destino_uf' => 'SP', 'produto_ids' => $pid]);
        $this->como($this->vendedor)->getJson("/api/cotacoes/anterior?{$outra}")->assertOk()->assertJsonPath('data', null);
        $this->getJson('/api/cotacoes/anterior?tipo_veiculo_codigo=CARRETA')->assertStatus(422);
        $this->getJson('/api/cotacoes/anterior?'.http_build_query(['tipo_veiculo_codigo' => 'CARRETA', 'origem_cidade' => 'Paulinia', 'origem_uf' => 'SP', 'destino_cidade' => 'Campinas', 'destino_uf' => 'SP']))->assertStatus(422);
    }

    public function test_produto_com_outra_descricao_nao_vincula_e_mesma_descricao_vincula(): void
    {
        $orig = $this->original();

        $outro = $this->produto('ACIDO', ['GRANEL_LIQUIDO']);
        $semVinculo = $this->cotar(produto: $outro);
        $this->assertNull($semVinculo['data']['cotacao_origem']);
        $this->assertSame('ABERTA', $semVinculo['data']['status']);

        $this->padrao->update(['descricao' => 'Soda Cáustica']);
        $gemeo = $this->produto('SODA2', ['GRANEL_LIQUIDO']);
        $gemeo->update(['descricao' => '  SODA   CAUSTICA ']);
        $this->assertSame($orig['data']['id'], $this->cotar(produto: $gemeo)['data']['cotacao_origem']['id']);
    }

    public function test_previa_nao_acha_com_produto_de_outra_descricao(): void
    {
        $this->original();
        $outro = $this->produto('ACIDO', ['GRANEL_LIQUIDO']);
        $q = http_build_query(['tipo_veiculo_codigo' => 'CARRETA', 'origem_cidade' => 'Paulinia', 'origem_uf' => 'SP', 'destino_cidade' => 'Campinas', 'destino_uf' => 'SP', 'produto_ids' => $outro->id]);

        $this->como($this->vendedor)->getJson("/api/cotacoes/anterior?{$q}")->assertOk()->assertJsonPath('data', null);
    }

    public function test_original_cancelada_depois_do_vinculo_mantem_o_vinculo_e_deixa_de_ser_candidata(): void
    {
        $orig = $this->original();
        $oid = $orig['data']['id'];
        $nid = $this->cotar()['data']['id'];

        $this->como($this->admin)->postJson("/api/cotacoes/{$oid}/cancelar", ['motivo' => 'teste'])->assertOk();

        $d = $this->como($this->admin)->getJson("/api/cotacoes/{$nid}")->assertOk()->json('data');
        $this->assertSame($oid, $d['cotacao_origem']['id']);
        $this->assertSame('CANCELADA', $d['cotacao_origem']['status']);
        $this->assertSame([], $d['negociacao']['respostas']);

        $outra = $this->cotar();
        $this->assertNull($outra['data']['cotacao_origem']);
        $this->assertSame('ABERTA', $outra['data']['status']);
    }

    public function test_cotacao_vinculada_nao_pode_ser_editada_por_patch(): void
    {
        $this->original();
        $nova = $this->cotar();

        $this->como($this->vendedor)->patchJson('/api/cotacoes/'.$nova['data']['id'], ['versao' => $nova['data']['versao']] + $this->corpoCotacao($this->cliente(), $this->produto('X')))
            ->assertStatus(422);
    }

    public function test_patch_em_cotacao_aberta_nao_cria_nem_remove_vinculo(): void
    {
        $this->original();
        $livre = $this->cotar(['usar_anterior' => false]);
        $produto = $this->produto('Q'.uniqid(), ['GRANEL_LIQUIDO']);
        $corpo = $this->corpoCotacao($this->cliente(), $produto, ['versao' => $livre['data']['versao'], 'usar_anterior' => true]);
        $corpo['itens'] = [['produto_id' => $produto->id, 'embalagem_codigo' => 'GRANEL_LIQUIDO', 'quantidade_kg' => '5000']];

        $r = $this->como($this->vendedor)->patchJson('/api/cotacoes/'.$livre['data']['id'], $corpo)->assertOk();

        $this->assertNull($r['data']['cotacao_origem']);
        $this->assertNull(Cotacao::find($livre['data']['id'])->cotacao_origem_id);
    }

    public function test_valor_tonelada_com_tres_casas_e_arredondado_antes_do_frete(): void
    {
        $r = $this->cotar();
        $id = $r['data']['id'];
        $this->como($this->admin)->postJson("/api/cotacoes/{$id}/encaminhar", ['transportadora_ids' => [$this->ta->id]])->assertOk();
        $resp = NegociacaoResposta::where('transportadora_id', $this->ta->id)->value('id');

        $this->como($this->ua)->postJson("/api/cotacoes/{$id}/respostas/{$resp}/responder", ['valor_tonelada' => 90.005, 'prazo_dias' => 2])
            ->assertOk()
            ->assertJsonPath('data.negociacao.respostas.0.valor_tonelada', '90.01')
            ->assertJsonPath('data.negociacao.respostas.0.valor_frete', '900.10');

        $f = $this->como($this->admin)->postJson("/api/cotacoes/{$id}/fechar", ['transportadora_id' => $this->ta->id, 'valor_tonelada' => '90.005', 'prazo_dias' => 2])->assertOk();
        $this->assertSame('90.01', $f['data']['negociacao']['fechamento']['valor_tonelada']);
        $this->assertSame('900.10', $f['data']['negociacao']['fechamento']['valor_frete']);
        $this->assertSame('900.10', $f['meta']['programacao']['valor_total']);
    }

    public function test_valores_fora_da_faixa_da_coluna_dao_422_e_nao_500(): void
    {
        $r = $this->cotar();
        $id = $r['data']['id'];
        $this->como($this->admin)->postJson("/api/cotacoes/{$id}/encaminhar", ['transportadora_ids' => [$this->ta->id]])->assertOk();
        $resp = NegociacaoResposta::where('transportadora_id', $this->ta->id)->value('id');

        $this->como($this->ua)->postJson("/api/cotacoes/{$id}/respostas/{$resp}/responder", ['valor_tonelada' => '99999999999999', 'prazo_dias' => 2])->assertStatus(422);
        $this->como($this->ua)->postJson("/api/cotacoes/{$id}/respostas/{$resp}/responder", ['valor_tonelada' => '10', 'prazo_dias' => 99999])->assertStatus(422);
    }

    public function test_transportadora_nao_recebe_codigo_nem_origem_e_o_422_e_generico(): void
    {
        $orig = $this->original();
        $nova = $this->cotar();
        $nid = $nova['data']['id'];
        $rid = $nova['data']['negociacao']['respostas'][0]['id'];

        $corpo = $this->como($this->ua)->getJson("/api/cotacoes/{$nid}")->assertOk()->json('data.negociacao.respostas.0');
        $this->assertSame('PROPRIA', $corpo['origem']);
        $this->assertNull($corpo['cotacao_codigo']);

        $r = $this->como($this->ua)->postJson("/api/cotacoes/{$nid}/respostas/{$rid}/responder", ['valor_tonelada' => '50', 'prazo_dias' => 1])->assertStatus(422);
        $this->assertStringNotContainsString($orig['data']['codigo'], $r->getContent());
        $this->assertSame('Esta proposta deve ser respondida na cotação original.', $r->json('message'));

        $r = $this->como($this->admin)->postJson("/api/cotacoes/{$nid}/respostas/{$rid}/responder", ['valor_tonelada' => '50', 'prazo_dias' => 1])->assertStatus(422);
        $this->assertStringContainsString($orig['data']['codigo'], $r->json('message'));
    }

    public function test_original_cancelada_esconde_respostas_bloqueia_fechar_e_permite_reencaminhar(): void
    {
        $oid = $this->original()['data']['id'];
        $nid = $this->cotar(kg: '20000')['data']['id'];
        $this->como($this->admin)->postJson("/api/cotacoes/{$oid}/cancelar", ['motivo' => 'teste'])->assertOk();

        $this->como($this->ua)->getJson("/api/cotacoes/{$nid}")->assertNotFound();

        $f = $this->como($this->admin)->postJson("/api/cotacoes/{$nid}/fechar", ['transportadora_id' => $this->ta->id, 'valor_tonelada' => '90', 'prazo_dias' => 2])->assertStatus(422);
        $this->assertStringNotContainsString('COT-', $f->getContent());
        $this->assertSame('EM_NEGOCIACAO', Cotacao::find($nid)->status->value);

        $e = $this->como($this->admin)->postJson("/api/cotacoes/{$nid}/encaminhar", ['transportadora_ids' => [$this->ta->id]])->assertOk();
        $this->assertSame(['PROPRIA'], array_column($e['data']['negociacao']['respostas'], 'origem'));
        $this->assertSame('AGUARDANDO', $e['data']['negociacao']['respostas'][0]['status']);
        $this->como($this->ua)->getJson("/api/cotacoes/{$nid}")->assertOk();

        $this->como($this->admin)->postJson("/api/cotacoes/{$nid}/fechar", ['transportadora_id' => $this->ta->id, 'valor_tonelada' => '90', 'prazo_dias' => 2])->assertOk();
    }

    public function test_migration_recalcula_respostas_fechamentos_e_programacoes_de_forma_idempotente(): void
    {
        $r = $this->cotar(kg: '15000');
        $id = $r['data']['id'];
        $this->como($this->admin)->postJson("/api/cotacoes/{$id}/encaminhar", ['transportadora_ids' => [$this->ta->id]])->assertOk();
        $resp = NegociacaoResposta::first();
        $this->como($this->ua)->postJson("/api/cotacoes/{$id}/respostas/{$resp->id}/responder", ['valor_tonelada' => '1000', 'prazo_dias' => 2])->assertOk();
        $this->como($this->admin)->postJson("/api/cotacoes/{$id}/fechar", ['transportadora_id' => $this->ta->id, 'valor_tonelada' => '500', 'prazo_dias' => 2])->assertOk();

        DB::table('negociacao_resposta')->update(['valor_frete' => '27000.00']);
        DB::table('negociacao')->update(['valor_frete' => '13500.00']);
        DB::table('programacao')->update(['valor_total' => '13500.00']);

        $this->assertSame(['respostas' => 1, 'fechamentos' => 1, 'programacoes' => 1], RecalculaFretes::executar());
        $this->assertSame('15000.00', Dinheiro::dec(DB::table('negociacao_resposta')->value('valor_frete'), 2));
        $this->assertSame('7500.00', Dinheiro::dec(DB::table('negociacao')->value('valor_frete'), 2));
        $this->assertSame('7500.00', Dinheiro::dec(DB::table('programacao')->value('valor_total'), 2));

        $this->assertSame(['respostas' => 0, 'fechamentos' => 0, 'programacoes' => 0], RecalculaFretes::executar());
    }
}
