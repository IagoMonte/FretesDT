<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Correções P1 da validação do MVP. */
class ValidacaoP1Test extends TestCase
{
    /** @return array{0: mixed, 1: mixed, 2: string, 3: string, 4: mixed} [admin, transportadora, cotacaoId, respostaId, usuário da transportadora] */
    private function cotacaoEncaminhada(): array
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $t = $this->transportadora('TRANSLOG');
        $ut = $this->usuario('translog', 'TRANSPORTADORA', $t);
        $id = $this->como($vendedor)->postJson('/api/cotacoes', $this->corpoCotacao($this->cliente(), $this->produto()))->assertCreated()['data']['id'];
        $e = $this->como($admin)->postJson("/api/cotacoes/{$id}/encaminhar", ['transportadora_ids' => [$t->id]])->assertOk();

        return [$admin, $t, $id, $e['data']['negociacao']['respostas'][0]['id'], $ut];
    }


    public function test_t04_responder_recusa_valor_prazo_e_validade_fora_do_limite(): void
    {
        [, , $id, $resp, $ut] = $this->cotacaoEncaminhada();
        $ok = ['valor_tonelada' => '200.00', 'prazo_dias' => 2, 'validade' => now()->addDays(7)->toDateString()];
        $url = "/api/cotacoes/{$id}/respostas/{$resp}/responder";

        foreach ([0, -5, 1e9, 999999999999, 'abc'] as $v) {
            $this->como($ut)->postJson($url, ['valor_tonelada' => $v] + $ok)->assertStatus(422)->assertJsonValidationErrors('valor_tonelada');
        }
        foreach ([0, 366, -3] as $p) {
            $this->postJson($url, ['prazo_dias' => $p] + $ok)->assertStatus(422)->assertJsonValidationErrors('prazo_dias');
        }
        $this->postJson($url, ['validade' => '9999-12-31'] + $ok)->assertStatus(422)->assertJsonValidationErrors('validade');

        $this->postJson($url, $ok)->assertOk();
    }

    public function test_t04_fechar_recusa_valor_e_prazo_fora_do_limite(): void
    {
        [$admin, $t, $id] = $this->cotacaoEncaminhada();
        $ok = ['transportadora_id' => $t->id, 'valor_tonelada' => '200.00', 'prazo_dias' => 2, 'negociado_por_fora' => true];
        $url = "/api/cotacoes/{$id}/fechar";

        foreach ([0, -5, 1e9, 'abc'] as $v) {
            $this->como($admin)->postJson($url, ['valor_tonelada' => $v] + $ok)->assertStatus(422)->assertJsonValidationErrors('valor_tonelada');
        }
        foreach ([0, 366, -3] as $p) {
            $this->postJson($url, ['prazo_dias' => $p] + $ok)->assertStatus(422)->assertJsonValidationErrors('prazo_dias');
        }
        $this->assertDatabaseCount('programacao', 0);

        $this->postJson($url, $ok)->assertOk();
    }


    public function test_t05_fechar_sem_resposta_exige_negociado_por_fora(): void
    {
        [$admin, $t, $id] = $this->cotacaoEncaminhada();
        $outra = $this->transportadora('NUNCA RECEBEU');
        $corpo = ['valor_tonelada' => '200.00', 'prazo_dias' => 2];
        $url = "/api/cotacoes/{$id}/fechar";

        foreach ([$t, $outra] as $transp) {
            $this->como($admin)->postJson($url, $corpo + ['transportadora_id' => $transp->id])
                ->assertStatus(422)->assertJsonValidationErrors('transportadora_id');
            $this->postJson($url, $corpo + ['transportadora_id' => $transp->id, 'negociado_por_fora' => false])->assertStatus(422);
        }
        $this->assertDatabaseCount('programacao', 0);

        $this->postJson($url, $corpo + ['transportadora_id' => $outra->id, 'negociado_por_fora' => true])
            ->assertOk()->assertJsonPath('data.status', 'PROGRAMADA');
        $this->assertDatabaseHas('log_operacao', ['evento' => 'NEGOCIACAO_FECHADA']);
    }

    public function test_t05_fechar_com_transportadora_que_respondeu_nao_precisa_da_marcacao(): void
    {
        [$admin, $t, $id, $resp, $ut] = $this->cotacaoEncaminhada();
        $this->como($ut)->postJson("/api/cotacoes/{$id}/respostas/{$resp}/responder", ['valor_tonelada' => '250.00', 'prazo_dias' => 2])->assertOk();

        $this->como($admin)->postJson("/api/cotacoes/{$id}/fechar", ['transportadora_id' => $t->id, 'valor_tonelada' => '240.00', 'prazo_dias' => 2])->assertOk();
    }

    public function test_t05_carga_mostra_ao_transportador_o_valor_proposto_quando_o_fechamento_difere(): void
    {
        [$admin, $t, $id, $resp, $ut] = $this->cotacaoEncaminhada();
        $this->como($ut)->postJson("/api/cotacoes/{$id}/respostas/{$resp}/responder", ['valor_tonelada' => '250.00', 'prazo_dias' => 2])->assertOk();
        $this->como($admin)->postJson("/api/cotacoes/{$id}/fechar", ['transportadora_id' => $t->id, 'valor_tonelada' => '200.00', 'prazo_dias' => 2])->assertOk();

        $carga = $this->como($ut)->getJson('/api/programacoes')->assertOk()['data'][0];
        $this->assertSame('200.00', $carga['valor_tonelada']);
        $this->assertSame('250.00', $carga['valor_proposto']);
    }

    public function test_t05_carga_sem_diferenca_ou_avulsa_nao_traz_valor_proposto(): void
    {
        [$admin, $t, $id, $resp, $ut] = $this->cotacaoEncaminhada();
        $this->como($ut)->postJson("/api/cotacoes/{$id}/respostas/{$resp}/responder", ['valor_tonelada' => '250.00', 'prazo_dias' => 2])->assertOk();
        $this->como($admin)->postJson("/api/cotacoes/{$id}/fechar", ['transportadora_id' => $t->id, 'valor_tonelada' => '250.00', 'prazo_dias' => 2])->assertOk();

        $this->assertNull($this->como($ut)->getJson('/api/programacoes')['data'][0]['valor_proposto']);
    }


    public function test_t06_cotacao_recusa_quantidade_nf_e_distancia_fora_do_limite(): void
    {
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $cliente = $this->cliente();
        $produto = $this->produto();
        $com = fn (array $extra, ?float $kg = null) => $this->corpoCotacao($cliente, $produto, $extra)
            + [];
        $corpo = function (array $extra = [], $kg = '12000') use ($cliente, $produto) {
            $c = $this->corpoCotacao($cliente, $produto, $extra);
            $c['itens'][0]['quantidade_kg'] = $kg;

            return $c;
        };

        foreach (['0.0004', 0, '1e9', '999999999999', -5] as $kg) {
            $this->como($vendedor)->postJson('/api/cotacoes', $corpo([], $kg))->assertStatus(422)->assertJsonValidationErrors('itens.0.quantidade_kg');
        }
        foreach ([null, '', ' ', 0, '0', '1e12', -1] as $nf) {
            $this->postJson('/api/cotacoes', $corpo(['valor_nf' => $nf]))->assertStatus(422)->assertJsonValidationErrors('valor_nf');
        }
        $this->postJson('/api/cotacoes', $corpo(['distancia_km' => 99999999]))->assertStatus(422)->assertJsonValidationErrors('distancia_km');

        $this->assertDatabaseCount('cotacao', 0);
        $this->postJson('/api/cotacoes', $corpo(['distancia_km' => 250.5], '12000'))->assertCreated();
    }

    public function test_t06_item_repetido_na_cotacao_gera_alerta(): void
    {
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $produto = $this->produto();
        $corpo = $this->corpoCotacao($this->cliente(), $produto);
        $corpo['itens'][] = $corpo['itens'][0];

        $r = $this->como($vendedor)->postJson('/api/cotacoes', $corpo)->assertCreated();
        $this->assertStringContainsString('repetido', implode(' ', $r['meta']['alertas']));
    }


    public function test_t07_cotacao_recusa_uf_inexistente_e_origem_igual_ao_destino(): void
    {
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $base = $this->corpoCotacao($this->cliente(), $this->produto());

        $this->como($vendedor)->postJson('/api/cotacoes', ['origem' => ['cidade' => 'Paulínia', 'uf' => 'ZZ']] + $base)
            ->assertStatus(422)->assertJsonValidationErrors('origem.uf');
        $this->postJson('/api/cotacoes', ['destino' => ['cidade' => 'Campinas', 'uf' => 'XX']] + $base)
            ->assertStatus(422)->assertJsonValidationErrors('destino.uf');
        $this->postJson('/api/cotacoes', ['destino' => ['cidade' => 'PAULINIA', 'uf' => 'sp']] + $base)
            ->assertStatus(422)->assertJsonValidationErrors('destino');
        $this->assertDatabaseCount('cotacao', 0);

        $this->postJson('/api/cotacoes', ['destino' => ['cidade' => 'Paulínia', 'uf' => 'MG']] + $base)->assertCreated();
        $this->postJson('/api/cotacoes', ['origem' => ['cidade' => 'Paulínia', 'uf' => 'sp']] + $base)->assertCreated();
    }

    public function test_t07_cancelar_cotacao_exige_motivo(): void
    {
        [$admin, , $id] = $this->cotacaoEncaminhada();

        foreach ([[], ['motivo' => ''], ['motivo' => 'ab'], ['motivo' => str_repeat('x', 501)]] as $corpo) {
            $this->como($admin)->postJson("/api/cotacoes/{$id}/cancelar", $corpo)->assertStatus(422)->assertJsonValidationErrors('motivo');
        }
        $this->postJson("/api/cotacoes/{$id}/cancelar", ['motivo' => 'cliente desistiu'])->assertOk()->assertJsonPath('data.status', 'CANCELADA');
    }


    private function fecharCotacao(): array
    {
        [$admin, $t, $id] = $this->cotacaoEncaminhada();
        $f = $this->como($admin)->postJson("/api/cotacoes/{$id}/fechar", ['transportadora_id' => $t->id, 'valor_tonelada' => '200.00', 'prazo_dias' => 2, 'negociado_por_fora' => true])->assertOk();

        return [$admin, $t, $id, $f['meta']['programacao']['id']];
    }

    public function test_t08_cancelar_a_unica_carga_volta_a_cotacao_para_negociacao_e_permite_refechar(): void
    {
        [$admin, $t, $id, $cargaId] = $this->fecharCotacao();

        $this->como($admin)->postJson("/api/programacoes/{$cargaId}/cancelar", ['motivo' => 'cliente mudou o pedido'])->assertOk();

        $c = $this->getJson("/api/cotacoes/{$id}")->assertOk();
        $this->assertSame('EM_NEGOCIACAO', $c['data']['status']);
        $this->assertSame('ABERTA', $c['data']['negociacao']['status']);
        $this->assertNull($c['data']['negociacao']['fechamento']);
        $this->assertDatabaseHas('log_operacao', ['evento' => 'COTACAO_STATUS', 'status_de' => 'PROGRAMADA', 'status_para' => 'EM_NEGOCIACAO']);

        $this->postJson("/api/cotacoes/{$id}/fechar", ['transportadora_id' => $t->id, 'valor_tonelada' => '210.00', 'prazo_dias' => 2, 'negociado_por_fora' => true])
            ->assertOk()->assertJsonPath('data.status', 'PROGRAMADA');
    }

    public function test_t08_cotacao_reaberta_pode_ser_cancelada(): void
    {
        [$admin, , $id, $cargaId] = $this->fecharCotacao();
        $this->como($admin)->postJson("/api/programacoes/{$cargaId}/cancelar", ['motivo' => 'erro'])->assertOk();

        $this->postJson("/api/cotacoes/{$id}/cancelar", ['motivo' => 'cliente desistiu'])->assertOk()->assertJsonPath('data.status', 'CANCELADA');
    }

    public function test_t08_com_outra_carga_ativa_a_cotacao_continua_programada(): void
    {
        [$admin, , $id, $cargaId] = $this->fecharCotacao();
        $cot = $this->getJson("/api/cotacoes/{$id}")['data'];
        $corpo = [
            'operacao' => 'CARREGAMENTO', 'parte' => ['tipo' => 'CLIENTE', 'id' => $cot['parte']['id']],
            'deposito' => 'FISICO', 'modalidade_frete' => 'FOB', 'tipo_veiculo_codigo' => 'TRUCK',
            'origem' => ['cidade' => 'Santos', 'uf' => 'SP'], 'destino' => ['cidade' => 'Campinas', 'uf' => 'SP'],
            'itens' => [['produto_id' => $cot['itens'][0]['produto']['id'], 'embalagem_codigo' => 'BOMBONA', 'quantidade_kg' => '1000', 'cotacao_item_id' => $cot['itens'][0]['id']]],
        ];
        $this->postJson('/api/programacoes', $corpo)->assertCreated();

        $this->postJson("/api/programacoes/{$cargaId}/cancelar", ['motivo' => 'erro'])->assertOk();

        $this->getJson("/api/cotacoes/{$id}")->assertJsonPath('data.status', 'PROGRAMADA');
    }


    private function semInglesNemEnum(string $msg): void
    {
        $this->assertDoesNotMatchRegularExpression('/validation\.|The |selected|[A-Z]{4,}_[A-Z]+|(PROGRAMADA|ENTREGUE|EM_TRANSITO|ABERTA)/', $msg, "mensagem fora do padrão: {$msg}");
    }

    public function test_t09_validacoes_saem_em_portugues(): void
    {
        [$admin, , $id, $resp] = $this->cotacaoEncaminhada();
        $url = "/api/cotacoes/{$id}/respostas/{$resp}/responder";
        $ok = ['valor_tonelada' => '200.00', 'prazo_dias' => 2];

        $r = $this->como($admin)->postJson($url, ['eixos' => 12] + $ok)->assertStatus(422);
        $this->assertStringContainsString('entre 2 e 9', $r['errors']['eixos'][0]);
        $this->semInglesNemEnum($r['message']);

        $r = $this->postJson($url, ['tipo_carga_antt_codigo' => 'XX'] + $ok)->assertStatus(422);
        $this->assertStringContainsString('tipo de carga', $r['message']);

        $r = $this->postJson($url, ['observacao' => str_repeat('x', 2001)] + $ok)->assertStatus(422);
        $this->assertStringContainsString('observação', $r['message']);
        $this->semInglesNemEnum($r['message']);
    }

    public function test_t09_status_invalido_e_transicoes_saem_em_portugues(): void
    {
        [$admin, , $id, $resp] = $this->cotacaoEncaminhada();
        $cliente = $this->cliente();
        $carga = $this->como($admin)->postJson('/api/programacoes', [
            'operacao' => 'CARREGAMENTO', 'parte' => ['tipo' => 'CLIENTE', 'id' => $cliente->id],
            'deposito' => 'FISICO', 'modalidade_frete' => 'FOB', 'tipo_veiculo_codigo' => 'TRUCK',
            'origem' => ['cidade' => 'Santos', 'uf' => 'SP'], 'destino' => ['cidade' => 'Campinas', 'uf' => 'SP'],
            'itens' => [['produto_id' => $this->produto()->id, 'embalagem_codigo' => 'BOMBONA', 'quantidade_kg' => '1000']],
        ])->assertCreated()['data']['id'];

        $r = $this->postJson("/api/programacoes/{$carga}/status", ['status' => 'XYZ'])->assertStatus(422);
        $this->semInglesNemEnum($r['message']);

        $this->postJson("/api/programacoes/{$carga}/status", ['status' => 'EM_TRANSITO'])->assertOk();
        $this->postJson("/api/programacoes/{$carga}/entregar", [])->assertOk();
        $r = $this->postJson("/api/programacoes/{$carga}/entregar", [])->assertStatus(422);
        $this->assertSame('Esta carga já foi entregue.', $r['message']);
    }

    public function test_t09_cotacao_fechada_nao_aceita_resposta_com_mensagem_clara(): void
    {
        [$admin, $t, $id, $resp, $ut] = $this->cotacaoEncaminhada();
        $this->como($admin)->postJson("/api/cotacoes/{$id}/fechar", ['transportadora_id' => $t->id, 'valor_tonelada' => '200.00', 'prazo_dias' => 2, 'negociado_por_fora' => true])->assertOk();

        $r = $this->como($ut)->postJson("/api/cotacoes/{$id}/respostas/{$resp}/responder", ['valor_tonelada' => '200.00', 'prazo_dias' => 2])->assertStatus(422);
        $this->assertSame('Esta cotação já foi fechada e não aceita mais respostas.', $r['message']);
    }

    public function test_t09_erro_interno_devolve_mensagem_em_portugues(): void
    {
        config(['app.debug' => false]);
        \Illuminate\Support\Facades\Route::get('/api/_boom', fn () => throw new \RuntimeException('boom'));

        $r = $this->getJson('/api/_boom')->assertStatus(500);
        $this->assertSame('Erro interno. Tente novamente em instantes.', $r['message']);
    }


    public function test_t13_usuario_bloqueado_recebe_mensagem_de_bloqueio(): void
    {
        $u = $this->usuario('marcos', 'VENDEDOR');
        $u->forceFill(['situacao' => 'BLOQUEADO'])->saveQuietly();

        $r = $this->postJson('/api/auth/login', ['login' => 'marcos', 'senha' => 'senha1'])->assertStatus(403);
        $this->assertSame('Seu acesso está bloqueado. Procure o gestor.', $r['message']);
        $this->assertSame('ACESSO_BLOQUEADO', $r['code']);
    }

    private function entrar(string $login = 'marcos'): array
    {
        $r = $this->postJson('/api/auth/login', ['login' => $login, 'senha' => 'senha1'])->assertOk();

        return [$r['access_token'], $r['refresh_token']];
    }

    public function test_t13_logout_sem_corpo_e_tolerado_e_derruba_o_access_token(): void
    {
        $this->usuario('marcos', 'VENDEDOR');
        [$access] = $this->entrar();
        $cabecalho = ['Authorization' => "Bearer {$access}"];

        $this->getJson('/api/me', $cabecalho)->assertOk();
        $this->postJson('/api/auth/logout', [], $cabecalho)->assertNoContent();
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->getJson('/api/me', $cabecalho)->assertStatus(401);
    }

    public function test_t13_logout_com_refresh_revoga_a_sessao(): void
    {
        $this->usuario('marcos', 'VENDEDOR');
        [$a1, $r1] = $this->entrar();

        $this->postJson('/api/auth/logout', ['refresh_token' => $r1], ['Authorization' => "Bearer {$a1}"])->assertNoContent();
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->getJson('/api/me', ['Authorization' => "Bearer {$a1}"])->assertStatus(401);
        $this->postJson('/api/auth/refresh', ['refresh_token' => $r1])->assertStatus(401);
    }


    public function test_t15_novo_login_encerra_a_sessao_anterior_do_mesmo_usuario(): void
    {
        $this->usuario('marcos', 'VENDEDOR');
        $this->usuario('ana', 'VENDEDOR');
        [$a1, $r1] = $this->entrar();
        [$aAna] = $this->entrar('ana');

        [$a2, $r2] = $this->entrar();

        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->getJson('/api/me', ['Authorization' => "Bearer {$a1}"])->assertStatus(401);
        $r = $this->postJson('/api/auth/refresh', ['refresh_token' => $r1])->assertStatus(401);
        $this->assertSame('Sua sessão foi encerrada porque este usuário entrou em outro lugar.', $r['message']);
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->getJson('/api/me', ['Authorization' => "Bearer {$a2}"])->assertOk();
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->getJson('/api/me', ['Authorization' => "Bearer {$aAna}"])->assertOk();
        $this->postJson('/api/auth/refresh', ['refresh_token' => $r2])->assertOk();
    }

    public function test_t15_f5_renova_pelo_refresh_sem_derrubar_a_sessao(): void
    {
        $this->usuario('marcos', 'VENDEDOR');
        [, $refresh] = $this->entrar();

        $novo = $this->postJson('/api/auth/refresh', ['refresh_token' => $refresh])->assertOk();
        $novo2 = $this->postJson('/api/auth/refresh', ['refresh_token' => $novo['refresh_token']])->assertOk();
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->getJson('/api/me', ['Authorization' => "Bearer {$novo2['access_token']}"])->assertOk();
    }

    public function test_t13_reuso_de_refresh_derruba_tambem_o_access_da_familia(): void
    {
        $this->usuario('marcos', 'VENDEDOR');
        [$access, $refresh] = $this->entrar();

        $novo = $this->postJson('/api/auth/refresh', ['refresh_token' => $refresh])->assertOk();
        $this->travel(config('frete.jwt.refresh_graca_segundos') + 5)->seconds();
        $this->postJson('/api/auth/refresh', ['refresh_token' => $refresh])->assertStatus(401);
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->getJson('/api/me', ['Authorization' => "Bearer {$novo['access_token']}"])->assertStatus(401);
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->getJson('/api/me', ['Authorization' => "Bearer {$access}"])->assertStatus(401);
    }


    public function test_t11_responder_com_versao_desatualizada_devolve_409(): void
    {
        [, , $id, $resp, $ut] = $this->cotacaoEncaminhada();
        $url = "/api/cotacoes/{$id}/respostas/{$resp}/responder";
        $versao = $this->como($ut)->getJson("/api/cotacoes/{$id}")['data']['negociacao']['respostas'][0]['versao'];

        $this->postJson($url, ['valor_tonelada' => '200.00', 'prazo_dias' => 2, 'versao' => $versao])->assertOk();
        $r = $this->postJson($url, ['valor_tonelada' => '190.00', 'prazo_dias' => 2, 'versao' => $versao])->assertStatus(409);
        $this->assertStringContainsString('alterado', $r['message']);

        $nova = $this->getJson("/api/cotacoes/{$id}")['data']['negociacao']['respostas'][0]['versao'];
        $this->assertGreaterThan($versao, $nova);
        $this->postJson($url, ['valor_tonelada' => '190.00', 'prazo_dias' => 2, 'versao' => $nova])->assertOk();
    }

    public function test_t11_declinar_com_versao_desatualizada_devolve_409(): void
    {
        [, , $id, $resp, $ut] = $this->cotacaoEncaminhada();
        $versao = $this->como($ut)->getJson("/api/cotacoes/{$id}")['data']['negociacao']['respostas'][0]['versao'];
        $this->postJson("/api/cotacoes/{$id}/respostas/{$resp}/responder", ['valor_tonelada' => '200.00', 'prazo_dias' => 2, 'versao' => $versao])->assertOk();

        $this->postJson("/api/cotacoes/{$id}/respostas/{$resp}/declinar", ['versao' => $versao])->assertStatus(409);
    }

    public function test_t11_proposta_declinada_nao_volta_a_ser_respondida(): void
    {
        [$admin, , $id, $resp, $ut] = $this->cotacaoEncaminhada();
        $this->como($ut)->postJson("/api/cotacoes/{$id}/respostas/{$resp}/declinar", ['observacao' => 'sem frota'])->assertOk();

        foreach ([$ut, $admin] as $quem) {
            $r = $this->como($quem)->postJson("/api/cotacoes/{$id}/respostas/{$resp}/responder", ['valor_tonelada' => '200.00', 'prazo_dias' => 2])->assertStatus(422);
            $this->assertSame('Esta transportadora já declinou esta cotação.', $r['message']);
        }
    }


    private function cadastro(array $extra = []): array
    {
        return $extra + [
            'nome' => 'Fulano', 'login' => 'fulano', 'email' => 'f@x.com', 'senha' => 'senha1234', 'senha_confirmation' => 'senha1234',
            'tipo' => 'TRANSPORTADORA', 'razao_social' => 'Fulano Ltda', 'cnpj' => '11222333000181',
        ];
    }

    public function test_t12_auto_cadastro_recusa_cnpj_ja_pendente(): void
    {
        $this->postJson('/api/auth/cadastro', $this->cadastro())->assertCreated();

        $r = $this->postJson('/api/auth/cadastro', $this->cadastro(['login' => 'beltrano', 'email' => 'b@x.com']))->assertStatus(422);
        $this->assertSame('Já existe um cadastro pendente com este CNPJ.', $r['message']);

        $this->postJson('/api/auth/cadastro', $this->cadastro(['login' => 'cicrano', 'email' => 'c@x.com', 'cnpj' => '11444777000161']))->assertCreated();
    }

    public function test_t12_usuario_novo_exige_login_sem_espacos_nem_simbolos_e_senha_de_8(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $base = ['nome' => 'Fulano', 'login' => 'fulano', 'senha' => 'senha1234', 'grupos' => ['VENDEDOR']];

        foreach (['g min espaço!', 'com espaco', 'a#b', 'ab'] as $login) {
            $this->como($admin)->postJson('/api/usuarios', ['login' => $login] + $base)->assertStatus(422)->assertJsonValidationErrors('login');
        }
        foreach (['abcd', 'abc1234'] as $senha) {
            $this->postJson('/api/usuarios', ['senha' => $senha] + $base)->assertStatus(422)->assertJsonValidationErrors('senha');
        }
        $this->assertDatabaseCount('usuario', 1);

        $this->postJson('/api/usuarios', $base)->assertCreated();
        $this->postJson('/api/usuarios', ['login' => 'ana.souza-1'] + $base)->assertCreated();
    }


    public function test_t25_cotacao_identica_avisa_o_codigo_da_existente(): void
    {
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $corpo = $this->corpoCotacao($this->cliente(), $this->produto());

        $primeira = $this->como($vendedor)->postJson('/api/cotacoes', $corpo)->assertCreated();
        $this->assertSame([], $primeira['meta']['alertas']);

        $segunda = $this->postJson('/api/cotacoes', $corpo)->assertCreated();
        $this->assertSame(["Já existe uma cotação igual: {$primeira['data']['codigo']}. Confira se não é duplicidade."], $segunda['meta']['alertas']);
    }

    public function test_t25_nao_avisa_quando_difere_ou_a_existente_foi_cancelada(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $cliente = $this->cliente();
        $produto = $this->produto();
        $corpo = $this->corpoCotacao($cliente, $produto);

        $id = $this->como($admin)->postJson('/api/cotacoes', $corpo)->assertCreated()['data']['id'];

        $outraQtd = $corpo;
        $outraQtd['itens'][0]['quantidade_kg'] = '13000';
        $this->postJson('/api/cotacoes', $outraQtd)->assertCreated()->assertJsonPath('meta.alertas', []);

        $outroVeiculo = ['tipo_veiculo_codigo' => 'TRUCK'] + $corpo;
        $this->postJson('/api/cotacoes', $outroVeiculo)->assertCreated()->assertJsonPath('meta.alertas', []);

        $this->postJson("/api/cotacoes/{$id}/cancelar", ['motivo' => 'erro de digitação'])->assertOk();
        $depois = $this->postJson('/api/cotacoes', $corpo)->assertCreated();
        $this->assertStringNotContainsString('Já existe uma cotação igual', implode(' ', $depois['meta']['alertas']));
    }


    private function cargaDe($admin, $transportadora): string
    {
        return $this->como($admin)->postJson('/api/programacoes', [
            'operacao' => 'CARREGAMENTO', 'parte' => ['tipo' => 'CLIENTE', 'id' => $this->cliente()->id],
            'deposito' => 'FISICO', 'modalidade_frete' => 'FOB', 'tipo_veiculo_codigo' => 'TRUCK', 'transportadora_id' => $transportadora->id,
            'origem' => ['cidade' => 'Santos', 'uf' => 'SP'], 'destino' => ['cidade' => 'Campinas', 'uf' => 'SP'],
            'itens' => [['produto_id' => $this->produto()->id, 'embalagem_codigo' => 'BOMBONA', 'quantidade_kg' => '1000']],
        ])->assertCreated()['data']['id'];
    }

    public function test_t21_carga_de_outra_transportadora_e_404_para_leitura_e_escrita(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $dona = $this->transportadora('DONA');
        $outra = $this->transportadora('OUTRA');
        $uOutra = $this->usuario('outra', 'TRANSPORTADORA', $outra);
        $id = $this->cargaDe($admin, $dona);

        $this->como($uOutra)->getJson("/api/programacoes/{$id}")->assertNotFound();
        $this->patchJson("/api/programacoes/{$id}", ['versao' => 0, 'observacao' => 'x'])->assertNotFound();
        $this->patchJson('/api/programacoes/00000000-0000-0000-0000-000000000000', ['versao' => 0, 'observacao' => 'x'])->assertNotFound();
    }

    public function test_t21_painel_so_mede_prazo_das_entregas_com_previsao(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $t = $this->transportadora('TRANSLOG');
        $base = fn (array $extra) => $extra + [
            'operacao' => 'CARREGAMENTO', 'parte' => ['tipo' => 'CLIENTE', 'id' => $this->cliente()->id],
            'deposito' => 'FISICO', 'modalidade_frete' => 'FOB', 'tipo_veiculo_codigo' => 'TRUCK', 'transportadora_id' => $t->id,
            'origem' => ['cidade' => 'Santos', 'uf' => 'SP'], 'destino' => ['cidade' => 'Campinas', 'uf' => 'SP'],
            'itens' => [['produto_id' => $this->produto()->id, 'embalagem_codigo' => 'BOMBONA', 'quantidade_kg' => '1000']],
        ];
        $entregar = function (array $extra) use ($admin, $base) {
            $id = $this->como($admin)->postJson('/api/programacoes', $base($extra))->assertCreated()['data']['id'];
            $this->postJson("/api/programacoes/{$id}/status", ['status' => 'EM_TRANSITO'])->assertOk();
            $this->postJson("/api/programacoes/{$id}/entregar", [])->assertOk();
        };
        $entregar(['data_entrega_prevista' => now()->addDays(3)->toDateString()]);
        $entregar([]);

        $p = $this->getJson('/api/painel')->assertOk()['data'];
        $this->assertSame(2, $p['kpis']['entregues']);
        $this->assertSame(1, $p['kpis']['no_prazo']);
        $this->assertSame(0, $p['kpis']['atrasadas']);
        $this->assertSame(100, $p['kpis']['pct_no_prazo']);
    }

    public function test_t21_responder_de_novo_sem_eixos_preserva_eixos_e_piso(): void
    {
        [, , $id, $resp, $ut] = $this->cotacaoEncaminhada();
        $url = "/api/cotacoes/{$id}/respostas/{$resp}/responder";
        $this->como($ut)->postJson($url, ['valor_tonelada' => '200.00', 'prazo_dias' => 2, 'eixos' => 5, 'tipo_carga_antt_codigo' => 'CARGA_GERAL'])->assertOk();
        $antes = $this->getJson("/api/cotacoes/{$id}")['data']['negociacao']['respostas'][0];
        $this->assertSame(5, $antes['eixos']);

        $depois = $this->postJson($url, ['valor_tonelada' => '190.00', 'prazo_dias' => 2, 'versao' => $antes['versao']])->assertOk()['data']['negociacao']['respostas'][0];
        $this->assertSame(5, $depois['eixos']);
        $this->assertSame('CARGA_GERAL', $depois['tipo_carga_antt_codigo']);
    }

    public function test_t21_cancelar_cotacao_avisa_quando_ninguem_pode_ser_notificado(): void
    {
        [$admin, $t, $id] = $this->cotacaoEncaminhada();
        $t->forceFill(['email' => null, 'telefone' => null])->save();

        $r = $this->como($admin)->postJson("/api/cotacoes/{$id}/cancelar", ['motivo' => 'cliente desistiu'])->assertOk();
        $this->assertNotEmpty($r['meta']['alertas']);
    }

    public function test_t21_nome_fantasia_telefone_e_produto_nao_aceitam_valores_vazios_de_sentido(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $base = ['razao_social' => 'Trans Log Ltda', 'nome_fantasia' => 'TRANSLOG', 'cnpj' => '11222333000181'];

        $this->como($admin)->postJson('/api/transportadoras', ['nome_fantasia' => 'A'] + $base)->assertStatus(422)->assertJsonValidationErrors('nome_fantasia');
        foreach (['(1', '123', '(19) 9999'] as $tel) {
            $this->postJson('/api/transportadoras', ['telefone' => $tel] + $base)->assertStatus(422)->assertJsonValidationErrors('telefone');
        }
        $this->postJson('/api/produtos', ['nome' => 'AB', 'embalagens' => ['SACARIA']])->assertStatus(422)->assertJsonValidationErrors('nome');

        $this->postJson('/api/transportadoras', ['telefone' => '(19) 99999-0001'] + $base)->assertCreated();
        $this->postJson('/api/produtos', ['nome' => 'SODA', 'embalagens' => ['SACARIA']])->assertCreated();
    }


    public function test_t17_vendedor_ve_as_respostas_de_todas_as_transportadoras_da_propria_cotacao(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $marcos = $this->usuario('marcos', 'VENDEDOR');
        $ta = $this->transportadora('TRANSLOG');
        $tb = $this->transportadora('ROTASUL');
        $ua = $this->usuario('translog', 'TRANSPORTADORA', $ta);
        $ub = $this->usuario('rotasul', 'TRANSPORTADORA', $tb);
        $id = $this->como($marcos)->postJson('/api/cotacoes', $this->corpoCotacao($this->cliente(), $this->produto()))->assertCreated()['data']['id'];
        $e = $this->como($admin)->postJson("/api/cotacoes/{$id}/encaminhar", ['transportadora_ids' => [$ta->id, $tb->id]])->assertOk();
        foreach ([[$ua, $ta, '180.00'], [$ub, $tb, '170.00']] as [$u, $t, $valor]) {
            $resp = collect($e['data']['negociacao']['respostas'])->firstWhere('transportadora.id', $t->id)['id'];
            $this->como($u)->postJson("/api/cotacoes/{$id}/respostas/{$resp}/responder", ['valor_tonelada' => $valor, 'prazo_dias' => 2])->assertOk();
        }

        $respostas = $this->como($marcos)->getJson("/api/cotacoes/{$id}")['data']['negociacao']['respostas'];
        $this->assertEqualsCanonicalizing(['180.00', '170.00'], array_column($respostas, 'valor_tonelada'));

        $dela = $this->como($ua)->getJson("/api/cotacoes/{$id}")['data']['negociacao']['respostas'];
        $this->assertCount(1, $dela);
    }

    public function test_t17_vendedor_nao_ve_cotacao_de_outro_vendedor_mas_o_reaproveitamento_traz_as_respostas(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $marcos = $this->usuario('marcos', 'VENDEDOR');
        $lucia = $this->usuario('lucia', 'VENDEDOR');
        $t = $this->transportadora('TRANSLOG');
        $ut = $this->usuario('translog', 'TRANSPORTADORA', $t);
        $produto = $this->produto('GL'.uniqid(), ['BOMBONA', 'GRANEL_LIQUIDO']);
        $cliente = $this->cliente();
        $corpo = $this->corpoCotacao($cliente, $produto);
        $corpo['itens'] = [['produto_id' => $produto->id, 'embalagem_codigo' => 'GRANEL_LIQUIDO', 'quantidade_kg' => '10000']];

        $idMarcos = $this->como($marcos)->postJson('/api/cotacoes', $corpo)->assertCreated()['data']['id'];
        $e = $this->como($admin)->postJson("/api/cotacoes/{$idMarcos}/encaminhar", ['transportadora_ids' => [$t->id]])->assertOk();
        $resp = $e['data']['negociacao']['respostas'][0]['id'];
        $this->como($ut)->postJson("/api/cotacoes/{$idMarcos}/respostas/{$resp}/responder", ['valor_tonelada' => '90.00', 'prazo_dias' => 2])->assertOk();

        $this->como($lucia)->getJson("/api/cotacoes/{$idMarcos}")->assertNotFound();
        $this->getJson('/api/cotacoes')->assertJsonPath('meta.total', 0);

        $nova = $this->postJson('/api/cotacoes', $corpo)->assertCreated();
        $this->assertSame('REFERENCIADA', $nova['data']['negociacao']['respostas'][0]['origem']);
        $this->assertSame('90.00', $nova['data']['negociacao']['respostas'][0]['valor_tonelada']);
    }
}
