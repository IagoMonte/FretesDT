<?php

namespace Tests\Feature;

use App\Models\Produto;
use App\Models\TipoVeiculo;
use Tests\TestCase;

class CadastrosTest extends TestCase
{
    /** Tipos de veículo novos (carga inicial da migration 2026_10_28_000031). */
    private const TIPOS_NOVOS = [
        'PICAPE' => 'Picape',
        'VUC_VAN' => 'Vuc/Van',
        'TOCO_34' => 'Toco 3/4',
        'CARRETA_LS' => 'Carreta LS',
        'RODOTREM' => 'Rodotrem',
        'PRANCHA' => 'Prancha',
    ];

    public function test_dominios_seguem_as_constantes_do_front(): void
    {
        $u = $this->usuario('marcos', 'VENDEDOR');
        $d = $this->como($u)->getJson('/api/dominios')->assertOk()['data'];

        $this->assertSame(['BOMBONA', 'CONTAINER', 'GRANEL_LIQUIDO', 'GRANEL_SOLIDO', 'SACARIA'], array_column($d['embalagens'], 'codigo'));
        $codigos = array_column($d['tipos_veiculo'], 'codigo');
        $this->assertEqualsCanonicalizing(
            ['PICAPE', 'VUC_VAN', 'TOCO', 'TOCO_34', 'TRUCK', 'BITRUCK', 'CARRETA', 'CARRETA_LS', 'BITREM', 'RODOTREM', 'PRANCHA'],
            $codigos,
        );
        $this->assertContains('TOCO', $codigos);
        $this->assertContains('TOCO_34', $codigos);
        $this->assertArrayNotHasKey('capacidade_kg', $d['tipos_veiculo'][0]);
    }

    public function test_descricoes_dos_tipos_de_veiculo_novos_e_do_toco(): void
    {
        foreach (self::TIPOS_NOVOS + ['TOCO' => 'Toco'] as $codigo => $descricao) {
            $this->assertSame($descricao, TipoVeiculo::find($codigo)?->descricao, "descrição de {$codigo}");
            $this->assertTrue((bool) TipoVeiculo::find($codigo)->ativo, "{$codigo} ativo");
        }
    }

    public function test_migration_dos_tipos_novos_e_idempotente(): void
    {
        TipoVeiculo::whereIn('codigo', array_keys(self::TIPOS_NOVOS))->delete();
        $this->assertSame(0, TipoVeiculo::whereIn('codigo', array_keys(self::TIPOS_NOVOS))->count());

        $migration = require base_path('database/migrations/2026_10_28_000031_tipos_veiculo_novos.php');
        $migration->up();
        $migration->up();

        $this->assertSame(6, TipoVeiculo::whereIn('codigo', array_keys(self::TIPOS_NOVOS))->where('ativo', true)->count());
        foreach (self::TIPOS_NOVOS as $codigo => $descricao) {
            $this->assertSame($descricao, TipoVeiculo::find($codigo)?->descricao);
        }
        $this->assertSame('Truck', TipoVeiculo::find('TRUCK')->descricao);
        $this->assertTrue((bool) TipoVeiculo::find('TRUCK')->ativo);
        $this->assertSame(11, TipoVeiculo::count());
    }

    public function test_transportadora_crud_inativar_e_lock_otimista(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $r = $this->como($admin)->postJson('/api/transportadoras', [
            'razao_social' => 'Trans Log Ltda', 'nome_fantasia' => 'TRANSLOG', 'cnpj' => '11.222.333/0001-81', 'email' => 'a@b.com',
        ])->assertCreated();
        $id = $r['data']['id'];
        $this->assertSame('11222333000181', $r['data']['cnpj']);
        $this->assertTrue($r['data']['ativo']);

        $this->postJson('/api/transportadoras', ['razao_social' => 'X', 'nome_fantasia' => 'Y', 'cnpj' => '11222333000181'])
            ->assertStatus(422)->assertJsonValidationErrors('cnpj');

        $this->patchJson("/api/transportadoras/{$id}", ['versao' => 0, 'telefone' => '19999990001'])->assertOk()->assertJsonPath('data.versao', 1);
        $this->patchJson("/api/transportadoras/{$id}", ['versao' => 0, 'telefone' => '19999990002'])->assertStatus(409);

        $this->postJson("/api/transportadoras/{$id}/inativar")->assertOk()->assertJsonPath('data.ativo', false);
        $this->getJson('/api/transportadoras?ativo=1')->assertJsonPath('meta.total', 0);
        $this->postJson("/api/transportadoras/{$id}/ativar")->assertOk()->assertJsonPath('data.ativo', true);
        $this->assertDatabaseHas('log_operacao', ['evento' => 'CADASTRO_INATIVADO']);
        $this->assertSame(404, $this->getJson('/api/transportadoras/inexistente')->status());
    }

    public function test_produto_nome_em_maiusculas_e_embalagens(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $r = $this->como($admin)->postJson('/api/produtos', ['nome' => 'soda cáustica', 'densidade' => '1.52', 'embalagens' => ['SACARIA', 'BOMBONA']])->assertCreated();
        $this->assertSame('SODA CÁUSTICA', $r['data']['nome']);
        $this->assertSame('1.520', $r['data']['densidade']);
        $this->assertSame(['BOMBONA', 'SACARIA'], $r['data']['embalagens']);
        $this->assertNull($r['data']['codigo_erp']);
        $this->assertSame('MANUAL', $r['data']['origem_cadastro']);

        $this->postJson('/api/produtos', ['nome' => 'X', 'embalagens' => []])->assertStatus(422);
        $this->postJson('/api/produtos', ['nome' => 'X', 'embalagens' => ['INEXISTENTE']])->assertStatus(422);
    }

    public function test_produto_descricao_onu_e_risco(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $r = $this->como($admin)->postJson('/api/produtos', ['nome' => 'hcl', 'descricao' => ' Ácido líquido ', 'onu' => '1789', 'risco' => 'r80', 'embalagens' => ['BOMBONA']])->assertCreated();
        $this->assertSame('Ácido líquido', $r['data']['descricao']);
        $this->assertSame('1789', $r['data']['onu']);
        $this->assertSame('R80', $r['data']['risco']);

        $sem = $this->postJson('/api/produtos', ['nome' => 'UREIA', 'descricao' => '', 'onu' => '', 'embalagens' => ['SACARIA']])->assertCreated();
        $this->assertNull($sem['data']['descricao']);
        $this->assertNull($sem['data']['onu']);

        $this->postJson('/api/produtos', ['nome' => 'X', 'onu' => '12', 'embalagens' => ['SACARIA']])->assertStatus(422);
        $this->postJson('/api/produtos', ['nome' => 'X', 'onu' => '12ab', 'embalagens' => ['SACARIA']])->assertStatus(422);
        $this->postJson('/api/produtos', ['nome' => 'X', 'risco' => str_repeat('A', 11), 'embalagens' => ['SACARIA']])->assertStatus(422);
    }

    public function test_transportadora_ve_descricao_no_lugar_do_nome(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $t = $this->transportadora('TRANSLOG');
        $ut = $this->usuario('translog', 'TRANSPORTADORA', $t);
        $cliente = $this->cliente();
        $com = $this->produto('SODA');
        $com->update(['descricao' => 'Líquido alcalino', 'onu' => '1824', 'risco' => '80']);
        $sem = $this->produto('HCL');

        $corpo = $this->corpoCotacao($cliente, $com);
        $corpo['itens'][] = ['produto_id' => $sem->id, 'embalagem_codigo' => 'BOMBONA', 'quantidade_kg' => '1000', 'volumes' => 1];
        $id = $this->como($vendedor)->postJson('/api/cotacoes', $corpo)->assertCreated()->json('data.id');
        $this->como($admin)->postJson("/api/cotacoes/{$id}/encaminhar", ['transportadora_ids' => [$t->id]])->assertOk();

        $interno = $this->como($vendedor)->getJson("/api/cotacoes/{$id}")['data']['itens'][0]['produto'];
        $this->assertSame('SODA', $interno['nome']);
        $this->assertSame('Líquido alcalino', $interno['descricao']);

        $itens = $this->como($ut)->getJson("/api/cotacoes/{$id}")['data']['itens'];
        $this->assertSame('Líquido alcalino', $itens[0]['produto']['nome']);
        $this->assertNull($itens[0]['produto']['descricao']);
        $this->assertSame('1824', $itens[0]['produto']['onu']);
        $this->assertSame('80', $itens[0]['produto']['risco']);
        $this->assertSame('HCL', $itens[1]['produto']['nome']);

        $this->assertCount(1, $this->getJson('/api/cotacoes?termo=alcalino')['data']);
        $this->assertCount(0, $this->getJson('/api/cotacoes?termo=SODA')['data']);
    }

    public function test_produto_fatores_kg_por_volume(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $r = $this->como($admin)->postJson('/api/produtos', ['nome' => 'HCL', 'embalagens' => ['BOMBONA', 'SACARIA'], 'fatores' => ['BOMBONA' => 50]])->assertCreated();
        $this->assertEquals(['BOMBONA' => '50.000'], (array) $r['data']['fatores']);
        $this->assertSame(['BOMBONA', 'SACARIA'], $r['data']['embalagens']);
        $id = $r['data']['id'];
        $this->assertSame('50.000', $this->getJson("/api/produtos/{$id}")['data']['fatores']['BOMBONA']);

        $r2 = $this->patchJson("/api/produtos/{$id}", ['versao' => 0, 'fatores' => ['SACARIA' => 25.5]])->assertOk();
        $this->assertEquals(['BOMBONA' => '50.000', 'SACARIA' => '25.500'], (array) $r2['data']['fatores']);

        $r3 = $this->patchJson("/api/produtos/{$id}", ['versao' => 1, 'embalagens' => ['BOMBONA']])->assertOk();
        $this->assertEquals(['BOMBONA' => '50.000'], (array) $r3['data']['fatores']);

        $this->postJson('/api/produtos', ['nome' => 'Y', 'embalagens' => ['SACARIA'], 'fatores' => ['BOMBONA' => 50]])->assertStatus(422);
        $this->postJson('/api/produtos', ['nome' => 'Y', 'embalagens' => ['SACARIA'], 'fatores' => ['SACARIA' => 0]])->assertStatus(422);
        $this->postJson('/api/produtos', ['nome' => 'Y', 'embalagens' => ['SACARIA'], 'fatores' => ['SACARIA' => -3]])->assertStatus(422);
        $this->patchJson("/api/produtos/{$id}", ['versao' => 2, 'fatores' => ['SACARIA' => 10]])->assertStatus(422);
    }

    public function test_cliente_duplicado_e_recusado_e_busca_de_partes(): void
    {
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $corpo = ['razao_social' => 'Beta Paulínia', 'cnpj' => '11.444.777/0001-61', 'cidade' => 'Paulínia', 'uf' => 'SP'];

        $primeiro = $this->como($vendedor)->postJson('/api/clientes', $corpo)->assertCreated();
        $this->assertSame([], $primeiro['meta']['alertas']);
        $this->assertSame('CLIENTE', $primeiro['data']['tipo']);

        $this->postJson('/api/clientes', $corpo)->assertStatus(409);

        $this->postJson('/api/fornecedores', ['razao_social' => 'Fornecedor X', 'cnpj' => '11222333000181'])->assertCreated();

        $this->getJson('/api/partes?termo=beta')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/partes?termo=11444777')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/partes?tipo=FORNECEDOR')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.tipo', 'FORNECEDOR');
    }

    public function test_usuarios_grupos_nao_se_misturam_e_transportadora_exige_vinculo(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $t = $this->transportadora('TRANSLOG');

        $this->como($admin)->postJson('/api/usuarios', ['nome' => 'A', 'login' => 'abc', 'senha' => 'abcd1234', 'grupos' => ['VENDEDOR', 'TRANSPORTADORA']])
            ->assertStatus(422)->assertJsonValidationErrors('grupos');
        $this->postJson('/api/usuarios', ['nome' => 'A', 'login' => 'abc', 'senha' => 'abcd1234', 'grupos' => ['TRANSPORTADORA']])
            ->assertStatus(422)->assertJsonValidationErrors('transportadora_id');

        $r = $this->postJson('/api/usuarios', ['nome' => 'Trans', 'login' => 'trans', 'senha' => 'abcd1234', 'grupos' => ['TRANSPORTADORA'], 'transportadora_id' => $t->id])->assertCreated();
        $this->assertSame('TRANSPORTADORA', $r['data']['tipo_usuario']);
        $this->assertSame(['TRANSPORTADORA'], $r['data']['grupos']);
        $this->assertArrayNotHasKey('senha_hash', $r['data']);

        $this->postJson('/api/auth/login', ['login' => 'trans', 'senha' => 'abcd1234'])->assertOk();
        $this->como($admin)->postJson("/api/usuarios/{$r['data']['id']}/bloquear")->assertOk()->assertJsonPath('data.situacao', 'BLOQUEADO');
        $this->postJson('/api/auth/login', ['login' => 'trans', 'senha' => 'abcd1234'])->assertStatus(403);

        $this->postJson('/api/usuarios/'.$admin->id.'/bloquear')->assertStatus(422);
        $this->getJson('/api/grupos')->assertOk()->assertJsonPath('meta.total', 4);
        $this->getJson('/api/vendedores')->assertOk();
    }

    public function test_embalagem_crud_codigo_minusculo_para_maiusculo(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $r = $this->como($admin)->postJson('/api/embalagens', [
            'codigo' => 'caixeta', 'descricao' => 'Caixeta', 'permite_paletizar' => true, 'permite_remontar' => false,
        ])->assertCreated();
        $this->assertSame('CAIXETA', $r['data']['codigo']);
        $this->assertTrue($r['data']['permite_paletizar']);
        $this->assertFalse($r['data']['permite_remontar']);
        $this->assertTrue($r['data']['ativo']);
    }

    public function test_embalagem_duplicada_retorna_422(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $this->como($admin)->postJson('/api/embalagens', ['codigo' => 'CAIXA', 'descricao' => 'Caixa'])->assertCreated();
        $this->postJson('/api/embalagens', ['codigo' => 'CAIXA', 'descricao' => 'Caixa de madeira'])->assertStatus(422);
    }

    public function test_embalagem_patch_com_versao_e_lock_otimista(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $r = $this->como($admin)->postJson('/api/embalagens', [
            'codigo' => 'PALLET', 'descricao' => 'Palete', 'permite_paletizar' => true, 'permite_remontar' => true,
        ])->assertCreated();
        $this->assertSame(0, $r['data']['versao']);

        $ok = $this->patchJson("/api/embalagens/PALLET", [
            'versao' => 0, 'descricao' => 'Palete padrão',
        ])->assertOk();
        $this->assertSame(1, $ok['data']['versao']);
        $this->assertSame('Palete padrão', $ok['data']['descricao']);

        $this->patchJson("/api/embalagens/PALLET", ['versao' => 0, 'descricao' => 'X'])->assertStatus(409);
    }

    public function test_embalagem_inativar_e_ativar(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $this->como($admin)->postJson('/api/embalagens', ['codigo' => 'TUBO', 'descricao' => 'Tubo'])->assertCreated();

        $this->postJson('/api/embalagens/TUBO/inativar')->assertOk()->assertJsonPath('data.ativo', false);
        $this->getJson('/api/embalagens?ativo=1')->assertJsonPath('meta.total', 5);
        $this->postJson('/api/embalagens/TUBO/ativar')->assertOk()->assertJsonPath('data.ativo', true);
        $this->assertDatabaseHas('log_operacao', ['evento' => 'CADASTRO_REATIVADO']);
    }

    public function test_embalagem_vendedor_sem_permissao_recebe_403(): void
    {
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $this->como($vendedor)->postJson('/api/embalagens', [
            'codigo' => 'BOX', 'descricao' => 'Box'
        ])->assertForbidden();
    }

    public function test_cotacao_item_paletizado_em_granel_liquido_valida_422(): void
    {
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $cliente = $this->cliente();
        $produto = Produto::create(['nome' => 'ACIDO', 'origem_cadastro' => 'MANUAL', 'ativo' => true]);
        $produto->embalagens()->sync(['GRANEL_LIQUIDO']);

        $corpo = $this->corpoCotacao($cliente, $produto);
        $corpo['itens'][0]['embalagem_codigo'] = 'GRANEL_LIQUIDO';
        $corpo['itens'][0]['paletizado'] = true;
        unset($corpo['itens'][0]['volumes']);

        $this->como($vendedor)->postJson('/api/cotacoes', $corpo)->assertStatus(422)
            ->assertJsonValidationErrors('itens.0.paletizado');
    }

    public function test_cotacao_item_paletizado_em_sacaria_aceita_e_retorna_verdadeiro(): void
    {
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $cliente = $this->cliente();
        $produto = Produto::create(['nome' => 'MILHO', 'origem_cadastro' => 'MANUAL', 'ativo' => true]);
        $produto->embalagens()->sync(['SACARIA']);

        $corpo = $this->corpoCotacao($cliente, $produto);
        $corpo['itens'][0]['embalagem_codigo'] = 'SACARIA';
        $corpo['itens'][0]['paletizado'] = true;

        $c = $this->como($vendedor)->postJson('/api/cotacoes', $corpo)->assertCreated();
        $this->assertSame('COT-000001', $c['data']['codigo']);
        $this->assertTrue($c['data']['itens'][0]['paletizado']);

        $id = $c['data']['id'];
        $ta = $this->transportadora('TRANSLOG');
        $f = $this->como($admin)->postJson("/api/cotacoes/{$id}/fechar", [
            'transportadora_id' => $ta->id, 'valor_tonelada' => '175.00', 'prazo_dias' => 3, 'negociado_por_fora' => true,
            'programacao' => ['deposito' => 'VIRTUAL', 'modalidade_frete' => 'CIF'],
        ])->assertOk();
        $this->assertTrue($f['data']['itens'][0]['paletizado']);
    }

    public function test_embalagem_permissoes_por_grupo(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $t = $this->transportadora('TRANSLOG');
        $trans = $this->usuario('translog', 'TRANSPORTADORA', $t);
        $this->como($admin)->postJson('/api/embalagens', ['codigo' => 'TUBO', 'descricao' => 'Tubo'])->assertCreated();

        $this->como($vendedor)->getJson('/api/embalagens')->assertOk();
        $this->postJson('/api/embalagens', ['codigo' => 'BOX', 'descricao' => 'Box'])->assertForbidden();
        $this->patchJson('/api/embalagens/TUBO', ['versao' => 0, 'descricao' => 'X'])->assertForbidden();
        $this->postJson('/api/embalagens/TUBO/inativar')->assertForbidden();
        $this->postJson('/api/embalagens/TUBO/ativar')->assertForbidden();

        $this->como($trans)->getJson('/api/embalagens')->assertForbidden();
        $this->postJson('/api/embalagens', ['codigo' => 'BOX', 'descricao' => 'Box'])->assertForbidden();
        $this->patchJson('/api/embalagens/TUBO', ['versao' => 0, 'descricao' => 'X'])->assertForbidden();
        $this->postJson('/api/embalagens/TUBO/inativar')->assertForbidden();

        $this->assertDatabaseHas('embalagem', ['codigo' => 'TUBO', 'ativo' => true, 'descricao' => 'Tubo']);
    }

    public function test_embalagem_patch_nao_altera_codigo_e_valida_campos(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $this->como($admin)->postJson('/api/embalagens', ['codigo' => 'TUBO', 'descricao' => 'Tubo'])->assertCreated();
        $this->patchJson('/api/embalagens/TUBO', ['versao' => 0, 'codigo' => 'OUTRO'])->assertStatus(422)->assertJsonValidationErrors('codigo');
        $this->patchJson('/api/embalagens/TUBO', ['descricao' => 'X'])->assertStatus(422)->assertJsonValidationErrors('versao');
        $this->postJson('/api/embalagens', ['codigo' => 'com espaco', 'descricao' => 'X'])->assertStatus(422)->assertJsonValidationErrors('codigo');
        $this->postJson('/api/embalagens', ['codigo' => 'SEM_DESC'])->assertStatus(422)->assertJsonValidationErrors('descricao');
        $this->patchJson('/api/embalagens/NAOEXISTE', ['versao' => 0, 'descricao' => 'X'])->assertNotFound();
    }

    public function test_embalagem_inativa_nao_e_aceita_em_item_de_cotacao(): void
    {
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $corpo = $this->corpoCotacao($this->cliente(), $this->produto());

        $this->como($admin)->postJson('/api/embalagens/BOMBONA/inativar')->assertOk();
        $this->como($vendedor)->postJson('/api/cotacoes', $corpo)->assertStatus(422)->assertJsonValidationErrors('itens.0.embalagem_codigo');

        $this->como($admin)->postJson('/api/embalagens/BOMBONA/ativar')->assertOk();
        $this->como($vendedor)->postJson('/api/cotacoes', $corpo)->assertCreated();
    }

    public function test_cotacao_remontado_exige_permissao_da_embalagem(): void
    {
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $this->como($admin)->postJson('/api/embalagens', ['codigo' => 'CX', 'descricao' => 'Caixa', 'permite_paletizar' => true, 'permite_remontar' => false])->assertCreated();
        $corpo = $this->corpoCotacao($this->cliente(), $this->produto('CAIXADO', ['CX']));
        $corpo['itens'][0]['embalagem_codigo'] = 'CX';

        $ruim = $corpo;
        $ruim['itens'][0]['remontado'] = true;
        $this->como($vendedor)->postJson('/api/cotacoes', $ruim)->assertStatus(422)->assertJsonValidationErrors('itens.0.remontado');

        $bom = $corpo;
        $bom['itens'][0]['paletizado'] = true;
        $bom['itens'][0]['remontado'] = false;
        $c = $this->postJson('/api/cotacoes', $bom)->assertCreated();
        $this->assertTrue($c['data']['itens'][0]['paletizado']);
        $this->assertFalse($c['data']['itens'][0]['remontado']);
    }

    public function test_produto_patch_so_com_nome_preserva_fatores(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $r = $this->como($admin)->postJson('/api/produtos', ['nome' => 'HCL', 'embalagens' => ['BOMBONA', 'SACARIA'], 'fatores' => ['BOMBONA' => 50, 'SACARIA' => 25]])->assertCreated();
        $id = $r['data']['id'];
        $r2 = $this->patchJson("/api/produtos/{$id}", ['versao' => 0, 'nome' => 'hcl novo'])->assertOk();
        $this->assertEquals(['BOMBONA' => '50.000', 'SACARIA' => '25.000'], (array) $r2['data']['fatores']);

        $r3 = $this->patchJson("/api/produtos/{$id}", ['versao' => 1, 'fatores' => ['BOMBONA' => 60]])->assertOk();
        $this->assertEquals(['BOMBONA' => '60.000', 'SACARIA' => '25.000'], (array) $r3['data']['fatores']);
    }

    public function test_produto_granel_nunca_recebe_fator(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $r = $this->como($admin)->postJson('/api/produtos', [
            'nome' => 'ACIDO', 'embalagens' => ['GRANEL_LIQUIDO', 'GRANEL_SOLIDO', 'BOMBONA'],
            'fatores' => ['GRANEL_LIQUIDO' => 100, 'GRANEL_SOLIDO' => 5, 'BOMBONA' => 50],
        ])->assertCreated();
        $this->assertEquals(['BOMBONA' => '50.000'], (array) $r['data']['fatores']);
        $id = $r['data']['id'];

        $r2 = $this->patchJson("/api/produtos/{$id}", ['versao' => 0, 'fatores' => ['GRANEL_LIQUIDO' => 10]])->assertOk();
        $this->assertEquals(['BOMBONA' => '50.000'], (array) $r2['data']['fatores']);
        $this->assertSame(0, \DB::table('produto_embalagem')->where('produto_id', $id)->where('embalagem_codigo', 'like', 'GRANEL%')->whereNotNull('kg_por_volume')->count());
    }

    public function test_embalagem_criar_com_granel_true_e_permite_paletizar_true(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $r = $this->como($admin)->postJson('/api/embalagens', [
            'codigo' => 'GRANEL_TESTE', 'descricao' => 'Granel Teste', 'granel' => true, 'permite_paletizar' => true, 'permite_remontar' => true,
        ])->assertCreated();
        $this->assertTrue($r['data']['granel']);
        $this->assertFalse($r['data']['permite_paletizar']);
        $this->assertFalse($r['data']['permite_remontar']);
    }

    public function test_embalagem_patch_marcando_granel_remove_fatores(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $produto = Produto::create(['nome' => 'HCL', 'origem_cadastro' => 'MANUAL', 'ativo' => true]);

        $this->como($admin)->postJson('/api/embalagens', [
            'codigo' => 'GRANEL_PATCH', 'descricao' => 'Granel Patch', 'permite_paletizar' => true, 'permite_remontar' => false,
        ])->assertCreated();

        $produto->embalagens()->sync(['GRANEL_PATCH' => ['kg_por_volume' => 50]]);
        $p = $this->getJson("/api/produtos/{$produto->id}")['data'];
        $this->assertEquals(['GRANEL_PATCH' => '50.000'], (array) $p['fatores']);

        $emb = $this->patchJson('/api/embalagens/GRANEL_PATCH', [
            'versao' => 0, 'granel' => true,
        ])->assertOk();
        $this->assertTrue($emb['data']['granel']);

        $p2 = $this->getJson("/api/produtos/{$produto->id}")['data'];
        $this->assertNull($p2['fatores']['GRANEL_PATCH'] ?? null);
    }

    public function test_cotacao_item_granel_novo_com_volumes_retorna_422(): void
    {
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $cliente = $this->cliente();
        $produto = Produto::create(['nome' => 'ACIDO_GRANEL', 'origem_cadastro' => 'MANUAL', 'ativo' => true]);
        $produto->embalagens()->sync(['GRANEL_LIQUIDO']);

        $corpo = $this->corpoCotacao($cliente, $produto);
        $corpo['itens'][0]['embalagem_codigo'] = 'GRANEL_LIQUIDO';
        $corpo['itens'][0]['volumes'] = 5;
        unset($corpo['itens'][0]['paletizado'], $corpo['itens'][0]['remontado']);

        $this->como($vendedor)->postJson('/api/cotacoes', $corpo)->assertStatus(422)
            ->assertJsonValidationErrors('itens.0.volumes');
    }

    public function test_cotacao_item_granel_novo_sem_volumes_retorna_201(): void
    {
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $cliente = $this->cliente();
        $produto = Produto::create(['nome' => 'ACIDO_GRANEL2', 'origem_cadastro' => 'MANUAL', 'ativo' => true]);
        $produto->embalagens()->sync(['GRANEL_LIQUIDO']);

        $corpo = $this->corpoCotacao($cliente, $produto);
        $corpo['itens'][0]['embalagem_codigo'] = 'GRANEL_LIQUIDO';
        unset($corpo['itens'][0]['volumes'], $corpo['itens'][0]['paletizado'], $corpo['itens'][0]['remontado']);

        $c = $this->como($vendedor)->postJson('/api/cotacoes', $corpo)->assertCreated();
        $this->assertSame('COT-000001', $c['data']['codigo']);
        $this->assertNull($c['data']['itens'][0]['volumes']);
    }

    public function test_dominios_granel_true_para_granel_solido_liquido_false_para_sacaria(): void
    {
        $u = $this->usuario('marcos', 'VENDEDOR');
        $d = $this->como($u)->getJson('/api/dominios')->assertOk()['data'];

        $embalagens = array_column($d['embalagens'], 'granel', 'codigo');
        $this->assertTrue($embalagens['GRANEL_LIQUIDO']);
        $this->assertTrue($embalagens['GRANEL_SOLIDO']);
        $this->assertFalse($embalagens['SACARIA']);
        $this->assertFalse($embalagens['BOMBONA']);
        $this->assertFalse($embalagens['CONTAINER']);
    }

    public function test_embalagem_fracionada_guarda_kg_por_volume_e_kg_por_palete(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $e = $this->como($admin)->postJson('/api/embalagens', ['codigo' => 'FRAC', 'descricao' => 'Fracionada', 'fracionada' => true])->assertCreated();
        $this->assertTrue($e['data']['fracionada']);

        $p = $this->postJson('/api/produtos', ['nome' => 'soja', 'embalagens' => ['FRAC', 'SACARIA'], 'fatores' => ['FRAC' => 60, 'SACARIA' => 25], 'fatores_palete' => ['FRAC' => 600, 'SACARIA' => 999]])->assertCreated();
        $this->assertEquals(60, $p['data']['fatores']['FRAC']);
        $this->assertEquals(600, $p['data']['fatores_palete']['FRAC']);
        $this->assertArrayNotHasKey('SACARIA', (array) $p['data']['fatores_palete']);
        $linha = \Illuminate\Support\Facades\DB::table('produto_embalagem')->where('produto_id', $p['data']['id']);
        $this->assertEquals(600, (clone $linha)->where('embalagem_codigo', 'FRAC')->value('kg_por_palete'));
        $this->assertEquals(60, (clone $linha)->where('embalagem_codigo', 'FRAC')->value('kg_por_volume'));
        $this->assertNull((clone $linha)->where('embalagem_codigo', 'SACARIA')->value('kg_por_palete'));
    }
}