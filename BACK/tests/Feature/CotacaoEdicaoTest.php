<?php

namespace Tests\Feature;

use App\Models\LogOperacao;
use Tests\TestCase;

class CotacaoEdicaoTest extends TestCase
{
    private function aberta(): array
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $ta = $this->transportadora('TRANSLOG');
        $ua = $this->usuario('translog', 'TRANSPORTADORA', $ta);
        $cliente = $this->cliente();
        $produto = $this->produto();
        $corpo = $this->corpoCotacao($cliente, $produto);
        $c = $this->como($vendedor)->postJson('/api/cotacoes', $corpo)->assertCreated();

        return [$admin, $vendedor, $ta, $ua, $corpo, $c['data']['id']];
    }

    public function test_edicao_grava_antes_e_depois_de_cada_campo_alterado(): void
    {
        [, $vendedor, , , $corpo, $id] = $this->aberta();
        $corpo['observacao'] = 'urgente';
        $corpo['valor_nf'] = '20000.00';
        $corpo['destino'] = ['cidade' => 'Santos', 'uf' => 'SP'];
        $corpo['itens'][0]['quantidade_kg'] = '9000';

        $this->como($vendedor)->patchJson("/api/cotacoes/{$id}", $corpo + ['versao' => 0])->assertOk();

        $log = LogOperacao::where('evento', 'COTACAO_EDITADA')->firstOrFail();
        $d = json_decode($log->detalhe, true);
        $this->assertSame('ABERTA', $log->status_de);
        $this->assertSame('ABERTA', $log->status_para);
        $this->assertSame([null, 'urgente'], [$d['observacao']['de'], $d['observacao']['para']]);
        $this->assertEquals(15000, $d['valor_nf']['de']);
        $this->assertEquals(20000, $d['valor_nf']['para']);
        $this->assertSame('CAMPINAS', $d['destino_cidade']['de']);
        $this->assertSame('SANTOS', $d['destino_cidade']['para']);
        $this->assertArrayHasKey('itens', $d);
        $this->assertArrayNotHasKey('origem_cidade', $d);
    }

    public function test_edicao_sem_mudanca_real_nao_polui_o_detalhe(): void
    {
        [, $vendedor, , , $corpo, $id] = $this->aberta();

        $this->como($vendedor)->patchJson("/api/cotacoes/{$id}", $corpo + ['versao' => 0])->assertOk();

        $log = LogOperacao::where('evento', 'COTACAO_EDITADA')->firstOrFail();
        $this->assertNull($log->detalhe);
    }

    public function test_diff_grande_de_itens_nao_e_truncado(): void
    {
        [, $vendedor, , , $corpo, $id] = $this->aberta();
        $produto = $this->produto();
        $corpo['itens'] = [];
        for ($i = 1; $i <= 12; $i++) {
            $corpo['itens'][] = ['produto_id' => $produto->id, 'embalagem_codigo' => 'BOMBONA', 'quantidade_kg' => (string) (1000 * $i), 'volumes' => $i];
        }
        $corpo['observacao'] = str_repeat('x', 1500);

        $this->como($vendedor)->patchJson("/api/cotacoes/{$id}", $corpo + ['versao' => 0])->assertOk();

        $log = LogOperacao::where('evento', 'COTACAO_EDITADA')->firstOrFail();
        $this->assertIsArray(json_decode($log->detalhe, true));
    }

    public function test_edicao_nao_notifica_ninguem(): void
    {
        [, $vendedor, , , $corpo, $id] = $this->aberta();
        $antes = \DB::table('notificacao')->count();

        $this->como($vendedor)->patchJson("/api/cotacoes/{$id}", $corpo + ['versao' => 0, 'observacao' => 'x'])->assertOk();

        $this->assertSame($antes, \DB::table('notificacao')->count());
    }

    public function test_depois_de_encaminhada_nao_pode_mais_editar(): void
    {
        [$admin, , $ta, , $corpo, $id] = $this->aberta();
        $this->como($admin)->postJson("/api/cotacoes/{$id}/encaminhar", ['transportadora_ids' => [$ta->id]])->assertOk();

        $this->como($admin)->patchJson("/api/cotacoes/{$id}", $corpo + ['versao' => 99])->assertStatus(409);
        $v = $this->como($admin)->getJson("/api/cotacoes/{$id}")['data']['versao'];
        $this->como($admin)->patchJson("/api/cotacoes/{$id}", $corpo + ['versao' => $v])->assertStatus(422);
    }

    public function test_cancelada_nao_edita(): void
    {
        [$admin, , , , $corpo, $id] = $this->aberta();
        $this->como($admin)->postJson("/api/cotacoes/{$id}/cancelar", ['motivo' => 'teste'])->assertOk();
        $v = $this->como($admin)->getJson("/api/cotacoes/{$id}")['data']['versao'];

        $this->como($admin)->patchJson("/api/cotacoes/{$id}", $corpo + ['versao' => $v])->assertStatus(422);
    }

    public function test_transportadora_nao_edita(): void
    {
        [, , , $ua, $corpo, $id] = $this->aberta();

        $this->como($ua)->patchJson("/api/cotacoes/{$id}", $corpo + ['versao' => 0])->assertForbidden();
    }

    public function test_vendedor_nao_edita_cotacao_de_outro_vendedor(): void
    {
        [, , , , $corpo, $id] = $this->aberta();
        $outro = $this->usuario('maria', 'VENDEDOR');

        $this->como($outro)->patchJson("/api/cotacoes/{$id}", $corpo + ['versao' => 0])->assertNotFound();
    }

    public function test_edita_todos_os_campos_da_criacao(): void
    {
        [, $vendedor, , , $corpo, $id] = $this->aberta();
        $corpo['parte'] = ['tipo' => 'CLIENTE', 'id' => $this->cliente('Outro Cliente')->id];
        $corpo['tipo_veiculo_codigo'] = 'TRUCK';
        $corpo['distancia_km'] = 321;
        $corpo['origem'] = ['cidade' => 'Santos', 'uf' => 'SP'];
        $corpo['destino'] = ['cidade' => 'Curitiba', 'uf' => 'PR'];
        $corpo['observacao'] = 'tudo novo';

        $r = $this->como($vendedor)->patchJson("/api/cotacoes/{$id}", $corpo + ['versao' => 0])->assertOk();

                $this->assertSame(1, $r['data']['versao']);
        $d = json_decode(LogOperacao::where('evento', 'COTACAO_EDITADA')->firstOrFail()->detalhe, true);
        $this->assertArrayHasKey('cliente_id', $d);
        $this->assertArrayHasKey('tipo_veiculo_codigo', $d);
        $this->assertArrayHasKey('distancia_km', $d);
    }
}
