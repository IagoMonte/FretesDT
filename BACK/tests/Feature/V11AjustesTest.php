<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Tests\TestCase;

/** Contrato v1.1: granel sem volumes, frete por tonelada x kg, login case-insensitive, validade e cotação repetida. */
class V11AjustesTest extends TestCase
{
    private function granel(string $embalagem = 'GRANEL_LIQUIDO', array $item = []): array
    {
        return array_merge(['produto_id' => $this->produtoGranel()->id, 'embalagem_codigo' => $embalagem, 'quantidade_kg' => '10000'], $item);
    }

    private function produtoGranel()
    {
        static $p = [];

        return $this->produto('GRAN'.count($p).uniqid(), ['BOMBONA', 'GRANEL_LIQUIDO', 'GRANEL_SOLIDO']);
    }


    public function test_granel_com_volumes_da_422_e_zero_ou_ausente_grava_null_na_cotacao(): void
    {
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $corpo = $this->corpoCotacao($this->cliente(), $this->produto());

        foreach (['GRANEL_LIQUIDO', 'GRANEL_SOLIDO'] as $emb) {
            $comVolumes = $corpo;
            $comVolumes['itens'] = [$this->granel($emb, ['volumes' => 3])];
            $this->como($vendedor)->postJson('/api/cotacoes', $comVolumes)->assertStatus(422)->assertJsonValidationErrors('itens.0.volumes');

            foreach ([['volumes' => 0], ['volumes' => null], []] as $extra) {
                $ok = $corpo;
                $ok['itens'] = [$this->granel($emb, $extra)];
                $this->como($vendedor)->postJson('/api/cotacoes', $ok)->assertCreated()->assertJsonPath('data.itens.0.volumes', null);
            }
        }

        $this->como($vendedor)->postJson('/api/cotacoes', $corpo)->assertCreated()->assertJsonPath('data.itens.0.volumes', 10);
    }

    public function test_granel_com_volumes_na_programacao_e_no_patch_da_cotacao(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $cliente = $this->cliente();
        $base = [
            'operacao' => 'CARREGAMENTO', 'parte' => ['tipo' => 'CLIENTE', 'id' => $cliente->id],
            'origem' => ['cidade' => 'Santos', 'uf' => 'SP'], 'destino' => ['cidade' => 'Campinas', 'uf' => 'SP'],
        ];

        $this->como($admin)->postJson('/api/programacoes', $base + ['itens' => [$this->granel('GRANEL_SOLIDO', ['volumes' => 2])]])
            ->assertStatus(422)->assertJsonValidationErrors('itens.0.volumes');
        $r = $this->postJson('/api/programacoes', $base + ['itens' => [$this->granel('GRANEL_LIQUIDO', ['volumes' => 0])]])
            ->assertCreated()->assertJsonPath('data.itens.0.volumes', null);

        $this->patchJson('/api/programacoes/'.$r['data']['id'], ['versao' => 0, 'itens' => [$this->granel('GRANEL_LIQUIDO', ['volumes' => 4])]])
            ->assertStatus(422)->assertJsonValidationErrors('itens.0.volumes');

        $c = $this->postJson('/api/cotacoes', $this->corpoCotacao($cliente, $this->produto()))->assertCreated();
        $corpo = $this->corpoCotacao($cliente, $this->produto());
        $corpo['itens'] = [$this->granel('GRANEL_LIQUIDO', ['volumes' => 5])];
        $this->patchJson('/api/cotacoes/'.$c['data']['id'], $corpo + ['versao' => 0])->assertStatus(422)->assertJsonValidationErrors('itens.0.volumes');
    }


    public function test_frete_usa_soma_dos_itens_e_nao_a_capacidade_do_veiculo(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $ta = $this->transportadora('TRANSLOG');
        $ua = $this->usuario('translog', 'TRANSPORTADORA', $ta);

        $corpo = $this->corpoCotacao($this->cliente(), $this->produto(), ['tipo_veiculo_codigo' => 'BITREM']);
        $corpo['itens'] = [
            ['produto_id' => $this->produto('A')->id, 'embalagem_codigo' => 'BOMBONA', 'quantidade_kg' => '4000.500', 'volumes' => 1],
            ['produto_id' => $this->produto('B')->id, 'embalagem_codigo' => 'BOMBONA', 'quantidade_kg' => '6500', 'volumes' => 1],
        ];
        $id = $this->como($vendedor)->postJson('/api/cotacoes', $corpo)->assertCreated()->json('data.id');
        $resp = $this->como($admin)->postJson("/api/cotacoes/{$id}/encaminhar", ['transportadora_ids' => [$ta->id]])->json('data.negociacao.respostas.0.id');

        $r = $this->como($ua)->postJson("/api/cotacoes/{$id}/respostas/{$resp}/responder", ['valor_tonelada' => '123.45', 'prazo_dias' => 2])->assertOk();
        $this->assertSame('1296.29', $r['data']['negociacao']['respostas'][0]['valor_frete']);

        $f = $this->como($admin)->postJson("/api/cotacoes/{$id}/fechar", ['transportadora_id' => $ta->id, 'valor_tonelada' => '100.00', 'prazo_dias' => 2])->assertOk();
        $this->assertSame('1050.05', $f['data']['negociacao']['fechamento']['valor_frete']);
        $this->assertSame('1050.05', $f['meta']['programacao']['valor_total']);
    }

    public function test_programacao_avulsa_valor_total_pela_soma_dos_kg(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $corpo = [
            'operacao' => 'CARREGAMENTO', 'parte' => ['tipo' => 'CLIENTE', 'id' => $this->cliente()->id],
            'tipo_veiculo_codigo' => 'BITREM', 'valor_tonelada' => '200.00',
            'origem' => ['cidade' => 'Santos', 'uf' => 'SP'], 'destino' => ['cidade' => 'Campinas', 'uf' => 'SP'],
            'itens' => [$this->granel('GRANEL_LIQUIDO', ['quantidade_kg' => '5000'])],
        ];
        $this->como($admin)->postJson('/api/programacoes', $corpo)->assertCreated()->assertJsonPath('data.valor_total', '1000.00');
    }


    public function test_login_ignora_caixa_e_espacos_mas_senha_e_case_sensitive(): void
    {
        $this->usuario('marcos', 'VENDEDOR');

        $this->postJson('/api/auth/login', ['login' => '  MaRcOs ', 'senha' => 'senha1'])->assertOk()->assertJsonPath('usuario.login', 'marcos');
        $this->postJson('/api/auth/login', ['login' => 'marcos', 'senha' => 'SENHA1'])->assertStatus(401);
    }

    public function test_usuarios_gravam_login_minusculo_e_unicidade_e_case_insensitive(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $corpo = ['nome' => 'Rafael', 'login' => ' Rafael ', 'senha' => 'abcd1234', 'grupos' => ['VENDEDOR']];

        $r = $this->como($admin)->postJson('/api/usuarios', $corpo)->assertCreated();
        $this->assertSame('rafael', $r['data']['login']);
        $this->assertSame('rafael', Usuario::find($r['data']['id'])->login);

        $post = $this->postJson('/api/usuarios', ['login' => 'RAFAEL'] + $corpo)->assertStatus(422)->assertJsonValidationErrors('login');
        $this->assertSame('Login indisponível.', $post['errors']['login'][0]);

        $outro = $this->postJson('/api/usuarios', ['login' => 'Lucia', 'nome' => 'Lucia'] + $corpo)->assertCreated();
        $patch = $this->patchJson('/api/usuarios/'.$outro['data']['id'], ['versao' => 0, 'login' => 'RAFAEL'])->assertStatus(422)->assertJsonValidationErrors('login');
        $this->assertSame('Login indisponível.', $patch['errors']['login'][0]);
        $p = $this->patchJson('/api/usuarios/'.$outro['data']['id'], ['versao' => 0, 'login' => 'LUCIA2'])->assertOk();
        $this->assertSame('lucia2', $p['data']['login']);
        $this->patchJson('/api/usuarios/'.$outro['data']['id'], ['versao' => 1, 'login' => 'Lucia2'])->assertOk()->assertJsonPath('data.login', 'lucia2');

        $this->postJson('/api/auth/login', ['login' => 'RAFAEL', 'senha' => 'abcd1234'])->assertOk();
    }


    private function respostaAberta(): array
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $ta = $this->transportadora('TRANSLOG');
        $ua = $this->usuario('translog', 'TRANSPORTADORA', $ta);
        $id = $this->como($vendedor)->postJson('/api/cotacoes', $this->corpoCotacao($this->cliente(), $this->produto()))->json('data.id');
        $resp = $this->como($admin)->postJson("/api/cotacoes/{$id}/encaminhar", ['transportadora_ids' => [$ta->id]])->json('data.negociacao.respostas.0.id');

        return [$id, $resp, $ua, $admin];
    }

    public function test_validade_vai_e_volta_em_yyyy_mm_dd_para_todos_os_perfis(): void
    {
        [$id, $resp, $ua, $admin] = $this->respostaAberta();
        $hoje = now('America/Sao_Paulo')->toDateString();
        $futuro = now('America/Sao_Paulo')->addDays(10)->toDateString();

        $r = $this->como($ua)->postJson("/api/cotacoes/{$id}/respostas/{$resp}/responder", ['valor_tonelada' => '100', 'prazo_dias' => 2, 'validade' => $futuro])->assertOk();
        $this->assertSame($futuro, $r['data']['negociacao']['respostas'][0]['validade']);
        $this->como($ua)->getJson("/api/cotacoes/{$id}")->assertJsonPath('data.negociacao.respostas.0.validade', $futuro);
        $this->como($admin)->getJson("/api/cotacoes/{$id}")->assertJsonPath('data.negociacao.respostas.0.validade', $futuro);
        $this->assertSame($futuro, \App\Models\NegociacaoResposta::first()->validade->format('Y-m-d'));

        $this->como($ua)->postJson("/api/cotacoes/{$id}/respostas/{$resp}/responder", ['valor_tonelada' => '100', 'prazo_dias' => 2, 'validade' => $hoje])
            ->assertOk()->assertJsonPath('data.negociacao.respostas.0.validade', $hoje);
        $this->postJson("/api/cotacoes/{$id}/respostas/{$resp}/responder", ['valor_tonelada' => '100', 'prazo_dias' => 2])
            ->assertOk()->assertJsonPath('data.negociacao.respostas.0.validade', null);
    }

    public function test_validade_passada_ou_em_formato_invalido_da_422(): void
    {
        [$id, $resp, $ua] = $this->respostaAberta();
        $ontem = now('America/Sao_Paulo')->subDay()->toDateString();

        foreach ([$ontem, '2030-02-30', '10/12/2030', now()->addDay()->toIso8601String()] as $invalida) {
            $this->como($ua)->postJson("/api/cotacoes/{$id}/respostas/{$resp}/responder", ['valor_tonelada' => '100', 'prazo_dias' => 2, 'validade' => $invalida])
                ->assertStatus(422)->assertJsonValidationErrors('validade');
        }
    }
}
