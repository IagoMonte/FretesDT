<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Services\JwtService;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/** T24: criação e edição de grupos de permissão (recurso GRUPO, só administrador por padrão). */
class GruposTest extends TestCase
{
    private function consulta(array $extra = []): array
    {
        return $extra + [
            'codigo' => 'CONSULTA_COTACAO', 'nome' => 'Consulta de cotações', 'descricao' => 'Só enxerga cotações.', 'tipo_usuario' => 'INTERNO',
            'permissoes' => ['COTACAO' => ['ver' => true, 'inserir' => false, 'alterar' => false, 'cancelar' => false, 'escopo' => 'TODOS']],
            'acoes' => [],
        ];
    }

    public function test_catalogo_lista_recursos_e_acoes_so_para_quem_gerencia_grupos(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $marcos = $this->usuario('marcos', 'VENDEDOR');

        $c = $this->como($admin)->getJson('/api/grupos/catalogo')->assertOk()['data'];
        $cotacao = collect($c['recursos'])->firstWhere('codigo', 'COTACAO');
        $this->assertTrue($cotacao['usa_escopo']);
        $negociacao = collect($c['recursos'])->firstWhere('codigo', 'NEGOCIACAO');
        $this->assertContains('FECHAR', array_column($negociacao['acoes'], 'codigo'));
        $this->assertSame(['INTERNO', 'TRANSPORTADORA'], $c['tipos_usuario']);

        $this->como($marcos)->getJson('/api/grupos/catalogo')->assertForbidden();
        $this->postJson('/api/grupos', $this->consulta())->assertForbidden();
    }

    public function test_detalhes_traz_grupos_de_sistema_com_matriz_e_contagem_de_usuarios(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $this->usuario('marcos', 'VENDEDOR');

        $g = collect($this->como($admin)->getJson('/api/grupos/detalhes')->assertOk()['data']);
        $vend = $g->firstWhere('codigo', 'VENDEDOR');
        $this->assertTrue($vend['sistema']);
        $this->assertSame(1, $vend['usuarios']);
        $this->assertTrue($vend['permissoes']['COTACAO']['inserir']);
        $this->assertSame('PROPRIOS', $vend['permissoes']['COTACAO']['escopo']);
        $this->assertFalse(isset($vend['permissoes']['USUARIO']));
        $this->assertSame(4, $g->count());
    }

    public function test_cria_grupo_com_permissao_minima_e_o_usuario_so_faz_o_que_o_grupo_permite(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $g = $this->como($admin)->postJson('/api/grupos', $this->consulta())->assertCreated();
        $this->assertSame('CONSULTA_COTACAO', $g['data']['codigo']);
        $this->assertFalse($g['data']['sistema']);
        $this->assertTrue($g['data']['ativo']);
        $this->assertTrue($g['data']['permissoes']['COTACAO']['ver']);

        $this->postJson('/api/usuarios', ['nome' => 'Leitor', 'login' => 'leitor', 'senha' => 'senha1234', 'grupos' => ['CONSULTA_COTACAO']])->assertCreated();
        $leitor = Usuario::where('login', 'leitor')->first();

        $this->como($leitor)->getJson('/api/cotacoes')->assertOk();
        $this->postJson('/api/cotacoes', $this->corpoCotacao($this->cliente(), $this->produto()))->assertForbidden();
        $this->getJson('/api/produtos')->assertForbidden();
        $this->getJson('/api/usuarios')->assertForbidden();
        $this->getJson('/api/grupos/catalogo')->assertForbidden();

        $perm = $this->getJson('/api/me')->assertOk()->json('data.permissoes');
        $this->assertTrue($perm['COTACAO']['ver']);
        $this->assertFalse($perm['COTACAO']['inserir']);
        $this->assertFalse($perm['PRODUTO']['ver']);
    }

    public function test_validacoes_de_criacao(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $this->como($admin);

        foreach (['AB', 'COM ESPACO', 'minusculo', str_repeat('A', 31), 'ADMINISTRADOR'] as $codigo) {
            $this->postJson('/api/grupos', $this->consulta(['codigo' => $codigo]))->assertStatus(422)->assertJsonValidationErrors('codigo');
        }
        $this->postJson('/api/grupos', $this->consulta(['tipo_usuario' => 'OUTRO']))->assertStatus(422)->assertJsonValidationErrors('tipo_usuario');
        $this->postJson('/api/grupos', $this->consulta(['nome' => '']))->assertStatus(422)->assertJsonValidationErrors('nome');
        $this->postJson('/api/grupos', $this->consulta(['permissoes' => ['NAO_EXISTE' => ['ver' => true]]]))->assertStatus(422)->assertJsonValidationErrors('permissoes');
        $this->postJson('/api/grupos', $this->consulta(['acoes' => ['NEGOCIACAO.INEXISTENTE']]))->assertStatus(422)->assertJsonValidationErrors('acoes');
        $this->postJson('/api/grupos', $this->consulta(['permissoes' => ['COTACAO' => ['ver' => true, 'escopo' => 'TRANSPORTADORA']]]))->assertStatus(422);
        $this->postJson('/api/grupos', $this->consulta(['permissoes' => [], 'acoes' => []]))->assertStatus(422)->assertJsonValidationErrors('permissoes');
        $this->assertDatabaseMissing('grupo', ['codigo' => 'CONSULTA_COTACAO']);

        $this->postJson('/api/grupos', $this->consulta())->assertCreated();
        $this->postJson('/api/grupos', $this->consulta())->assertStatus(422)->assertJsonValidationErrors('codigo');
    }

    public function test_grupo_de_transportadora_so_recebe_recursos_e_escopo_de_transportadora(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $base = ['codigo' => 'TRANSP_LEITURA', 'nome' => 'Transportadora só leitura', 'tipo_usuario' => 'TRANSPORTADORA'];
        $this->como($admin);

        $this->postJson('/api/grupos', $base + ['permissoes' => ['USUARIO' => ['ver' => true, 'escopo' => 'TODOS']]])->assertStatus(422)->assertJsonValidationErrors('permissoes');
        $this->postJson('/api/grupos', $base + ['permissoes' => ['COTACAO' => ['ver' => true, 'escopo' => 'TODOS']]])->assertStatus(422);
        $this->postJson('/api/grupos', $base + ['permissoes' => ['PROGRAMACAO' => ['ver' => true, 'escopo' => 'TRANSPORTADORA']], 'acoes' => ['NEGOCIACAO.FECHAR']])
            ->assertStatus(422)->assertJsonValidationErrors('acoes');

        $this->postJson('/api/grupos', $base + [
            'permissoes' => ['PROGRAMACAO' => ['ver' => true, 'escopo' => 'TRANSPORTADORA']], 'acoes' => ['PROGRAMACAO.CONFIRMAR_ENTREGA'],
        ])->assertCreated();
    }

    public function test_edicao_muda_a_matriz_na_hora_e_derruba_tokens_dos_usuarios_do_grupo(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $g = $this->como($admin)->postJson('/api/grupos', $this->consulta())->assertCreated()['data'];
        $this->postJson('/api/usuarios', ['nome' => 'Leitor', 'login' => 'leitor', 'senha' => 'senha1234', 'grupos' => ['CONSULTA_COTACAO']])->assertCreated();
        $leitor = Usuario::where('login', 'leitor')->first();
        $tokenAntigo = app(JwtService::class)->emitir($leitor);

        $novo = $this->patchJson("/api/grupos/{$g['id']}", [
            'versao' => $g['versao'], 'nome' => 'Consulta e produtos',
            'permissoes' => [
                'COTACAO' => ['ver' => true, 'inserir' => true, 'alterar' => false, 'cancelar' => false, 'escopo' => 'PROPRIOS'],
                'PRODUTO' => ['ver' => true],
            ],
        ])->assertOk();
        $this->assertSame('Consulta e produtos', $novo['data']['nome']);
        $this->assertTrue($novo['data']['permissoes']['PRODUTO']['ver']);
        $this->assertSame($g['versao'] + 1, $novo['data']['versao']);

        Auth::forgetGuards();
        $this->withToken($tokenAntigo)->getJson('/api/cotacoes')->assertStatus(401);
        $leitor->refresh();
        $this->como($leitor)->getJson('/api/produtos')->assertOk();
        $this->postJson('/api/cotacoes', $this->corpoCotacao($this->cliente(), $this->produto()))->assertCreated();

        $this->como($admin)->patchJson("/api/grupos/{$g['id']}", ['versao' => $g['versao'], 'nome' => 'Outra'])->assertStatus(409);
    }

    public function test_grupos_de_sistema_sao_protegidos(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $vend = collect($this->como($admin)->getJson('/api/grupos/detalhes')['data'])->firstWhere('codigo', 'VENDEDOR');

        $this->patchJson("/api/grupos/{$vend['id']}", ['versao' => $vend['versao'], 'nome' => 'Hackeado'])->assertStatus(422);
        $this->postJson("/api/grupos/{$vend['id']}/inativar")->assertStatus(422);
        $this->assertDatabaseHas('grupo', ['codigo' => 'VENDEDOR', 'nome' => 'Vendedor', 'ativo' => true]);
    }

    public function test_inativar_exige_grupo_sem_usuarios_e_sai_da_lista_de_atribuicao(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $g = $this->como($admin)->postJson('/api/grupos', $this->consulta())->assertCreated()['data'];
        $this->postJson('/api/usuarios', ['nome' => 'Leitor', 'login' => 'leitor', 'senha' => 'senha1234', 'grupos' => ['CONSULTA_COTACAO']])->assertCreated();

        $r = $this->postJson("/api/grupos/{$g['id']}/inativar")->assertStatus(422);
        $this->assertStringContainsString('1 usuário', $r['message']);

        $vazio = $this->postJson('/api/grupos', $this->consulta(['codigo' => 'VAZIO', 'nome' => 'Vazio']))->assertCreated()['data'];
        $this->postJson("/api/grupos/{$vazio['id']}/inativar")->assertOk()->assertJsonPath('data.ativo', false);
        $this->assertNotContains('VAZIO', array_column($this->getJson('/api/grupos')['data'], 'codigo'));
        $this->postJson('/api/usuarios', ['nome' => 'X', 'login' => 'xis', 'senha' => 'senha1234', 'grupos' => ['VAZIO']])->assertStatus(422)->assertJsonValidationErrors('grupos');

        $this->postJson("/api/grupos/{$vazio['id']}/ativar")->assertOk()->assertJsonPath('data.ativo', true);
    }
}
