<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Correções P0 da validação do MVP. */
class ValidacaoP0Test extends TestCase
{

    public function test_t01_cadastro_manual_recusa_cnpj_invalido(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');

        foreach (['11111111111111', '99.999.999/9999-99', '12345678000100'] as $cnpj) {
            $this->como($admin)->postJson('/api/transportadoras', ['razao_social' => 'X Ltda', 'nome_fantasia' => 'X', 'cnpj' => $cnpj])
                ->assertStatus(422)->assertJsonPath('errors.cnpj.0', 'CNPJ inválido.');
            $this->postJson('/api/clientes', ['razao_social' => 'X Ltda', 'cnpj' => $cnpj])
                ->assertStatus(422)->assertJsonPath('errors.cnpj.0', 'CNPJ inválido.');
            $this->postJson('/api/fornecedores', ['razao_social' => 'X Ltda', 'cnpj' => $cnpj])
                ->assertStatus(422)->assertJsonPath('errors.cnpj.0', 'CNPJ inválido.');
        }
        $this->assertDatabaseCount('transportadora', 0);
    }

    public function test_t01_cnpj_valido_com_e_sem_mascara_e_edicao(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');

        $a = $this->como($admin)->postJson('/api/transportadoras', ['razao_social' => 'A Ltda', 'nome_fantasia' => 'AA', 'cnpj' => '11.222.333/0001-81'])->assertCreated();
        $this->postJson('/api/transportadoras', ['razao_social' => 'B Ltda', 'nome_fantasia' => 'BB', 'cnpj' => '11444777000161'])->assertCreated();
        $this->postJson('/api/transportadoras', ['razao_social' => 'CC', 'nome_fantasia' => 'CC', 'cnpj' => '11222333000181'])->assertStatus(422)->assertJsonValidationErrors('cnpj');
        $this->patchJson("/api/transportadoras/{$a['data']['id']}", ['versao' => 0, 'cnpj' => '12345678000100'])
            ->assertStatus(422)->assertJsonPath('errors.cnpj.0', 'CNPJ inválido.');
    }

    public function test_t01_auto_cadastro_recusa_cnpj_invalido(): void
    {
        $this->postJson('/api/auth/cadastro', [
            'nome' => 'Fulano', 'login' => 'fulano', 'email' => 'f@x.com', 'senha' => 'senha1234', 'senha_confirmation' => 'senha1234',
            'tipo' => 'TRANSPORTADORA', 'razao_social' => 'Fulano Ltda', 'cnpj' => '12345678000100',
        ])->assertStatus(422)->assertJsonPath('errors.cnpj.0', 'CNPJ inválido.');
    }


    private function cargaEmTransito(): array
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $cliente = $this->cliente();
        $produto = $this->produto();
        $id = $this->como($admin)->postJson('/api/programacoes', [
            'operacao' => 'CARREGAMENTO', 'parte' => ['tipo' => 'CLIENTE', 'id' => $cliente->id],
            'deposito' => 'FISICO', 'modalidade_frete' => 'FOB', 'tipo_veiculo_codigo' => 'TRUCK',
            'origem' => ['cidade' => 'Santos', 'uf' => 'SP'], 'destino' => ['cidade' => 'Campinas', 'uf' => 'SP'],
            'valor_tonelada' => '240.00', 'data_entrega_prevista' => now()->addDays(5)->toDateString(),
            'itens' => [['produto_id' => $produto->id, 'embalagem_codigo' => 'BOMBONA', 'quantidade_kg' => '27000']],
        ])->assertCreated()['data']['id'];
        $this->postJson("/api/programacoes/{$id}/status", ['status' => 'EM_TRANSITO', 'data' => now()->subDays(3)->toDateString()])->assertOk();

        return [$admin, $id];
    }

    public function test_t02_t20_entrega_e_o_momento_do_clique_e_ignora_data_enviada(): void
    {
        [, $id] = $this->cargaEmTransito();

        foreach (['9999-12-31', now()->addDay()->toDateString(), now()->subDays(10)->toDateString()] as $data) {
            $this->postJson("/api/programacoes/{$id}/status", ['status' => 'EM_TRANSITO', 'data' => $data])->assertStatus(422);
        }
        $r = $this->postJson("/api/programacoes/{$id}/entregar", ['data_entrega_realizada' => '2020-01-01'])->assertOk();
        $this->assertSame('ENTREGUE', $r['data']['status']);
        $this->assertSame(\App\Support\Fmt::hoje(), $r['data']['data_entrega_realizada']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $r['data']['entregue_em']);
        $this->assertSame(\App\Support\Fmt::hoje(), substr($r['data']['entregue_em'], 0, 10));
    }

    public function test_t02_coleta_realizada_nao_pode_ser_futura(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $cliente = $this->cliente();
        $produto = $this->produto();
        $id = $this->como($admin)->postJson('/api/programacoes', [
            'operacao' => 'CARREGAMENTO', 'parte' => ['tipo' => 'CLIENTE', 'id' => $cliente->id],
            'deposito' => 'FISICO', 'modalidade_frete' => 'FOB', 'tipo_veiculo_codigo' => 'TRUCK',
            'origem' => ['cidade' => 'Santos', 'uf' => 'SP'], 'destino' => ['cidade' => 'Campinas', 'uf' => 'SP'],
            'itens' => [['produto_id' => $produto->id, 'embalagem_codigo' => 'BOMBONA', 'quantidade_kg' => '27000']],
        ])->assertCreated()['data']['id'];

        $this->postJson("/api/programacoes/{$id}/status", ['status' => 'EM_TRANSITO', 'data' => '2030-01-01'])
            ->assertStatus(422)->assertJsonValidationErrors('data');
        $this->postJson("/api/programacoes/{$id}/status", ['status' => 'EM_TRANSITO', 'data' => now()->toDateString()])->assertOk();
    }

    public function test_t02_fechar_exige_entrega_prevista_depois_da_coleta_prevista(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $t = $this->transportadora('TRANSLOG');
        $id = $this->como($vendedor)->postJson('/api/cotacoes', $this->corpoCotacao($this->cliente(), $this->produto()))->json('data.id');
        $base = ['transportadora_id' => $t->id, 'valor_tonelada' => '175.00', 'prazo_dias' => 3, 'negociado_por_fora' => true];

        $this->como($admin)->postJson("/api/cotacoes/{$id}/fechar", $base + ['programacao' => [
            'data_coleta_prevista' => '2026-12-10', 'data_entrega_prevista' => '2026-12-01',
        ]])->assertStatus(422)->assertJsonValidationErrors('programacao.data_entrega_prevista');
        $this->postJson("/api/cotacoes/{$id}/fechar", $base + ['programacao' => ['data_entrega_prevista' => '9999-12-31']])
            ->assertStatus(422)->assertJsonValidationErrors('programacao.data_entrega_prevista');

        $this->postJson("/api/cotacoes/{$id}/fechar", $base + ['programacao' => [
            'data_coleta_prevista' => '2026-12-01', 'data_entrega_prevista' => '2026-12-01',
        ]])->assertOk();
    }


    public function test_t03_login_malformado_devolve_422_e_nunca_5xx(): void
    {
        foreach ([
            ['login' => [], 'senha' => new \stdClass],
            ['login' => ['a' => 1], 'senha' => []],
            ['login' => 123, 'senha' => true],
        ] as $corpo) {
            $this->postJson('/api/auth/login', $corpo)->assertStatus(422);
        }
        $this->usuario('marcos', 'VENDEDOR');
        $this->postJson('/api/auth/login', ['login' => 'marcos', 'senha' => 'senha1'])->assertOk();
    }
}
