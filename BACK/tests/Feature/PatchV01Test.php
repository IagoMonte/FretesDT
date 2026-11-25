<?php

namespace Tests\Feature;

use App\Models\RefreshToken;
use App\Models\Usuario;
use Tests\TestCase;

/** patchv01 (revalidação no staging, 22/10/2026): entradas não escalares, escalada de privilégio, filtros e limites. */
class PatchV01Test extends TestCase
{
    /** Valores que não são texto: lista, objeto e lista com texto. */
    private const NAO_TEXTO = [[], ['a' => 1], ['x', 'y']];


    public function test_p0_2_cadastro_publico_recusa_campos_que_nao_sao_texto(): void
    {
        config(['frete.cadastro_tentativas_por_hora' => 1000]);
        $base = [
            'nome' => 'Fulano', 'login' => 'fulano', 'email' => 'f@x.com', 'senha' => 'senha1234', 'senha_confirmation' => 'senha1234',
            'tipo' => 'TRANSPORTADORA', 'razao_social' => 'Fulano Ltda', 'cnpj' => '11222333000181',
        ];
        foreach (['login', 'email', 'cnpj', 'nome', 'razao_social', 'nome_fantasia'] as $campo) {
            foreach (self::NAO_TEXTO as $valor) {
                $this->postJson('/api/auth/cadastro', [$campo => $valor] + $base)
                    ->assertStatus(422)->assertJsonValidationErrors($campo);
            }
        }
        $this->assertDatabaseCount('usuario', 0);
    }


    public function test_p0_3_cotacao_recusa_uf_que_nao_e_texto(): void
    {
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $corpo = $this->corpoCotacao($this->cliente(), $this->produto());
        foreach (['origem', 'destino'] as $lado) {
            foreach (self::NAO_TEXTO as $valor) {
                $this->como($vendedor)->postJson('/api/cotacoes', array_replace_recursive($corpo, [$lado => ['uf' => $valor]]))
                    ->assertStatus(422)->assertJsonValidationErrors("{$lado}.uf");
            }
        }
    }


    public function test_p0_4_cadastros_recusam_campos_que_nao_sao_texto(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $this->como($admin);
        foreach (self::NAO_TEXTO as $valor) {
            foreach (['cnpj', 'razao_social', 'uf', 'cidade'] as $campo) {
                $this->postJson('/api/clientes', [$campo => $valor] + ['razao_social' => 'X Ltda', 'cnpj' => '11222333000181'])
                    ->assertStatus(422)->assertJsonValidationErrors($campo);
            }
            $this->postJson('/api/produtos', ['nome' => $valor, 'embalagens' => ['BOMBONA']])->assertStatus(422)->assertJsonValidationErrors('nome');
            $this->postJson('/api/embalagens', ['codigo' => $valor, 'descricao' => 'X'])->assertStatus(422)->assertJsonValidationErrors('codigo');
            $this->postJson('/api/transportadoras', ['razao_social' => 'X', 'nome_fantasia' => 'X', 'cnpj' => $valor])->assertStatus(422)->assertJsonValidationErrors('cnpj');
        }
    }


    public function test_p1_1_filtros_de_listagem_recusam_lista_e_objeto(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $this->como($admin);
        foreach (['cotacoes' => ['status', 'termo', 'codigo'], 'programacoes' => ['status', 'termo', 'codigo'],
            'embalagens' => ['ativo', 'termo'], 'transportadoras' => ['ativo', 'termo'], 'partes' => ['termo', 'tipo']] as $rota => $filtros) {
            foreach ($filtros as $filtro) {
                $this->getJson("/api/{$rota}?{$filtro}[]=x")->assertStatus(422)->assertJsonValidationErrors($filtro);
                $this->getJson("/api/{$rota}?{$filtro}[a]=x")->assertStatus(422)->assertJsonValidationErrors($filtro);
            }
        }
    }


    private function atorSo(string $recurso, string $codigo): Usuario
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $this->como($admin)->postJson('/api/grupos', [
            'codigo' => $codigo, 'nome' => "Só {$recurso}", 'tipo_usuario' => 'INTERNO',
            'permissoes' => [$recurso => ['ver' => true, 'inserir' => true, 'alterar' => true, 'cancelar' => false, 'escopo' => 'TODOS']],
        ])->assertCreated();

        return $this->usuario('ator_'.strtolower($recurso), $codigo);
    }

    public function test_p0_5a_quem_so_gerencia_usuarios_nao_atribui_grupo_que_nao_tem(): void
    {
        $ator = $this->atorSo('USUARIO', 'SO_USUARIOS');
        $outro = $this->usuario('outro', 'VENDEDOR');
        $corpo = ['nome' => 'Novo', 'login' => 'novo', 'senha' => 'senha1234', 'grupos' => ['ADMINISTRADOR']];

        $this->como($ator)->postJson('/api/usuarios', $corpo)->assertForbidden();
        $this->postJson('/api/usuarios', ['grupos' => ['VENDEDOR']] + $corpo)->assertForbidden();
        $this->patchJson("/api/usuarios/{$outro->id}", ['versao' => 0, 'grupos' => ['ADMINISTRADOR']])->assertForbidden();
        $this->patchJson("/api/usuarios/{$ator->id}", ['versao' => 0, 'grupos' => ['ADMINISTRADOR']])->assertForbidden();
        $admin = Usuario::where('login', 'admin')->first();
        $this->patchJson("/api/usuarios/{$admin->id}", ['versao' => 0, 'senha' => 'trocada1234'])->assertForbidden();
        $this->postJson("/api/usuarios/{$admin->id}/bloquear")->assertForbidden();
        $this->assertFalse(Usuario::where('login', 'novo')->exists());
        $this->assertSame(['SO_USUARIOS'], $ator->refresh()->grupos->pluck('codigo')->all());

        $this->postJson('/api/usuarios', ['grupos' => ['SO_USUARIOS']] + $corpo)->assertCreated();
    }

    public function test_p0_5a_administrador_continua_atribuindo_qualquer_grupo(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $this->como($admin)->postJson('/api/usuarios', ['nome' => 'Novo', 'login' => 'novo', 'senha' => 'senha1234', 'grupos' => ['ADMINISTRADOR']])->assertCreated();
        $outro = $this->usuario('outro', 'VENDEDOR');
        $this->patchJson("/api/usuarios/{$outro->id}", ['versao' => 0, 'grupos' => ['GESTAO_ADMIN']])->assertOk();
    }

    public function test_p0_5b_quem_so_gerencia_grupos_nao_amplia_o_proprio_grupo_nem_cria_grupo_maior(): void
    {
        $ator = $this->atorSo('GRUPO', 'SO_GRUPOS');
        $grupoId = \App\Models\Grupo::where('codigo', 'SO_GRUPOS')->value('id');
        $ampliada = ['GRUPO' => ['ver' => true, 'inserir' => true, 'alterar' => true, 'cancelar' => false, 'escopo' => 'TODOS'],
            'USUARIO' => ['ver' => true, 'inserir' => true, 'alterar' => true, 'cancelar' => false, 'escopo' => 'TODOS']];

        $this->como($ator)->patchJson("/api/grupos/{$grupoId}", ['versao' => 0, 'permissoes' => $ampliada])->assertForbidden();
        $this->patchJson("/api/grupos/{$grupoId}", ['versao' => 0, 'permissoes' => $ampliada['GRUPO'] ? ['GRUPO' => $ampliada['GRUPO']] : [], 'acoes' => ['USUARIO.LIBERAR_ACESSO']])->assertStatus(422);
        $this->postJson('/api/grupos', ['codigo' => 'NOVO_MAIOR', 'nome' => 'Novo maior', 'tipo_usuario' => 'INTERNO', 'permissoes' => $ampliada])->assertForbidden();
        $this->assertFalse(\App\Models\Grupo::where('codigo', 'NOVO_MAIOR')->exists());
        $this->assertFalse(\DB::table('grupo_permissao')->where('grupo_id', $grupoId)->where('recurso_codigo', 'USUARIO')->exists());

        $this->postJson('/api/grupos', ['codigo' => 'MENOR', 'nome' => 'Menor', 'tipo_usuario' => 'INTERNO',
            'permissoes' => ['GRUPO' => ['ver' => true, 'inserir' => false, 'alterar' => false, 'cancelar' => false, 'escopo' => 'TODOS']]])->assertCreated();
    }


    private function cotacaoEncaminhada(): array
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $t = $this->transportadora('TRANSLOG');
        $u = $this->usuario('translog', 'TRANSPORTADORA', $t);
        $id = $this->como($admin)->postJson('/api/cotacoes', $this->corpoCotacao($this->cliente(), $this->produto()))->assertCreated()['data']['id'];
        $e = $this->postJson("/api/cotacoes/{$id}/encaminhar", ['transportadora_ids' => [$t->id]])->assertOk();

        return [$u, $id, $e['data']['negociacao']['respostas'][0]['id']];
    }

    public function test_p1_2_valor_por_tonelada_que_arredonda_para_zero_e_recusado(): void
    {
        [$u, $id, $resp] = $this->cotacaoEncaminhada();
        foreach (['0.001', '0.004', 0.0049] as $v) {
            $this->como($u)->postJson("/api/cotacoes/{$id}/respostas/{$resp}/responder", ['valor_tonelada' => $v, 'prazo_dias' => 3])
                ->assertStatus(422)->assertJsonValidationErrors('valor_tonelada');
        }
        $this->postJson("/api/cotacoes/{$id}/respostas/{$resp}/responder", ['valor_tonelada' => '0.005', 'prazo_dias' => 3])->assertOk();
    }

    public function test_p1_3_programacao_tem_os_mesmos_tetos_da_cotacao(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $cliente = $this->cliente();
        $produto = $this->produto();
        $corpo = [
            'operacao' => 'CARREGAMENTO', 'parte' => ['tipo' => 'CLIENTE', 'id' => $cliente->id], 'deposito' => 'FISICO', 'modalidade_frete' => 'FOB',
            'tipo_veiculo_codigo' => 'TRUCK', 'origem' => ['cidade' => 'Santos', 'uf' => 'SP'], 'destino' => ['cidade' => 'Campinas', 'uf' => 'SP'],
            'itens' => [['produto_id' => $produto->id, 'embalagem_codigo' => 'BOMBONA', 'quantidade_kg' => '1000']],
        ];
        foreach ([['valor_tonelada' => '9999999999999'], ['valor_tonelada' => '100000.01'], ['prazo_dias' => 32767], ['prazo_dias' => 91], ['valor_tonelada' => '0.004']] as $ruim) {
            $this->como($admin)->postJson('/api/programacoes', $ruim + $corpo)->assertStatus(422);
        }
        $id = $this->postJson('/api/programacoes', ['valor_tonelada' => '240.00', 'prazo_dias' => 5] + $corpo)->assertCreated()['data']['id'];
        $this->patchJson("/api/programacoes/{$id}", ['versao' => 0, 'valor_tonelada' => '9999999999999'])->assertStatus(422);
        $this->patchJson("/api/programacoes/{$id}", ['versao' => 0, 'prazo_dias' => 32767])->assertStatus(422);
    }


    public function test_p1_4_cliente_com_cnpj_ativo_existente_e_recusado_com_ou_sem_mascara(): void
    {
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $primeiro = $this->como($vendedor)->postJson('/api/clientes', ['razao_social' => 'Beta', 'cnpj' => '11.444.777/0001-61'])->assertCreated();

        foreach (['11.444.777/0001-61', '11444777000161'] as $cnpj) {
            $r = $this->postJson('/api/clientes', ['razao_social' => 'Outra', 'cnpj' => $cnpj])->assertStatus(409);
            $r->assertJsonPath('existente.id', $primeiro['data']['id']);
            $this->assertStringNotContainsString($primeiro['data']['id'], $r['message']);
        }
        $this->assertSame(1, \App\Models\Cliente::count());
    }


    public function test_p1_11_transportadora_sem_nenhum_contato_gera_um_unico_aviso_claro(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $semContato = \App\Models\Transportadora::create(['razao_social' => 'Sem Contato Ltda', 'nome_fantasia' => 'SEMCONTATO', 'cnpj' => '11222333000181', 'ativo' => true]);
        $soEmail = \App\Models\Transportadora::create(['razao_social' => 'So Email Ltda', 'nome_fantasia' => 'SOEMAIL', 'cnpj' => '11444777000161', 'ativo' => true, 'email' => 'so@email.com']);
        $id = $this->como($admin)->postJson('/api/cotacoes', $this->corpoCotacao($this->cliente(), $this->produto()))->assertCreated()['data']['id'];

        $alertas = $this->postJson("/api/cotacoes/{$id}/encaminhar", ['transportadora_ids' => [$semContato->id, $soEmail->id]])->assertOk()['meta']['alertas'];

        $this->assertCount(2, $alertas);
        $semContatoMsg = collect($alertas)->first(fn ($a) => str_contains($a, 'SEMCONTATO'));
        $this->assertStringContainsString('sem WhatsApp nem e-mail', $semContatoMsg);
        $this->assertStringNotContainsString('avisada somente por e-mail', $semContatoMsg);
        $this->assertStringContainsString('avisada somente por e-mail', collect($alertas)->first(fn ($a) => str_contains($a, 'SOEMAIL')));
    }

    public function test_p1_11_transportadora_nao_ve_avisos_internos_de_contato(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $t = $this->transportadora('TRANSLOG');
        $u = $this->usuario('translog', 'TRANSPORTADORA', $t);
        $id = $this->como($admin)->postJson('/api/cotacoes', $this->corpoCotacao($this->cliente(), $this->produto()))->assertCreated()['data']['id'];
        $this->postJson("/api/cotacoes/{$id}/encaminhar", ['transportadora_ids' => [$t->id]])->assertOk();

        app(\App\Services\Notificacao\NotificacaoAlertas::class)->add('Transportadora X sem WhatsApp nem e-mail: não foi avisada.');
        $this->assertNotEmpty($this->como($admin)->getJson("/api/cotacoes/{$id}")->assertOk()['meta']['alertas']);
        $this->assertSame([], $this->como($u)->getJson("/api/cotacoes/{$id}")->assertOk()['meta']['alertas']);
    }


    public function test_t05_violacao_de_unicidade_no_banco_responde_422(): void
    {
        \Illuminate\Support\Facades\Route::post('api/_teste_duplicidade', function () {
            \App\Models\Embalagem::create(['codigo' => 'DUP', 'descricao' => 'A', 'ativo' => true]);
            \App\Models\Embalagem::create(['codigo' => 'DUP', 'descricao' => 'B', 'ativo' => true]);
        });
        $this->postJson('/api/_teste_duplicidade')->assertStatus(422)->assertJsonPath('message', 'Já existe um registro com estes dados.');
    }


    public function test_p1_7_refresh_repetido_dentro_da_janela_nao_e_reuso(): void
    {
        $this->usuario('marcos', 'VENDEDOR');
        $login = $this->postJson('/api/auth/login', ['login' => 'marcos', 'senha' => 'senha1'])->assertOk();
        $primeiro = $login['refresh_token'];

        $this->postJson('/api/auth/refresh', ['refresh_token' => $primeiro])->assertOk();
        $segundo = $this->postJson('/api/auth/refresh', ['refresh_token' => $primeiro])->assertOk()['refresh_token'];
        $this->postJson('/api/auth/refresh', ['refresh_token' => $segundo])->assertOk();
        $this->assertNull(RefreshToken::where('motivo_revogacao', 'REUSO_DETECTADO')->first());
    }

    public function test_p1_7_reuso_depois_da_janela_continua_derrubando_a_familia(): void
    {
        $this->usuario('marcos', 'VENDEDOR');
        $primeiro = $this->postJson('/api/auth/login', ['login' => 'marcos', 'senha' => 'senha1'])->assertOk()['refresh_token'];
        $this->postJson('/api/auth/refresh', ['refresh_token' => $primeiro])->assertOk();

        $this->travel(config('frete.jwt.refresh_graca_segundos') + 5)->seconds();
        $this->postJson('/api/auth/refresh', ['refresh_token' => $primeiro])->assertStatus(401);
        $this->assertSame(0, RefreshToken::whereNull('revogado_em')->count());
    }


    public function test_p2_cliente_recusa_uf_inexistente(): void
    {
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $this->como($vendedor)->postJson('/api/clientes', ['razao_social' => 'X', 'cnpj' => '11444777000161', 'uf' => 'ZZ'])
            ->assertStatus(422)->assertJsonValidationErrors('uf');
    }

    public function test_p2_declinar_duas_vezes_nao_mexe_na_versao(): void
    {
        [$u, $id, $resp] = $this->cotacaoEncaminhada();
        $this->como($u)->postJson("/api/cotacoes/{$id}/respostas/{$resp}/declinar", ['observacao' => 'sem frota'])->assertOk();
        $versao = \DB::table('cotacao')->where('id', $id)->value('versao');
        $this->postJson("/api/cotacoes/{$id}/respostas/{$resp}/declinar", ['observacao' => 'sem frota'])->assertStatus(422);
        $this->assertSame($versao, \DB::table('cotacao')->where('id', $id)->value('versao'));
    }

    public function test_p2_mensagens_sem_chave_de_traducao_crua(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $this->como($admin);
        $r = $this->getJson('/api/antt/frete-minimo')->assertStatus(422);
        $this->assertStringNotContainsString('validation.', $r->getContent());
        $r = $this->postJson('/api/usuarios', ['nome' => 'X', 'login' => "a' OR 1=1--", 'senha' => 'senha1234', 'grupos' => ['VENDEDOR']])->assertStatus(422);
        $this->assertStringNotContainsString('validation.', $r->getContent());
        $r = $this->postJson('/api/embalagens', ['codigo' => 'a b!', 'descricao' => 'X'])->assertStatus(422);
        $this->assertStringNotContainsString('validation.', $r->getContent());
        $r = $this->postJson('/api/programacoes', ['data_pedido' => '22/10/2026'])->assertStatus(422);
        $this->assertStringNotContainsString('Y-m-d', $r->getContent());
    }
}
