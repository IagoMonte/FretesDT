<?php

namespace Tests\Feature\Admin;

use App\Models\Usuario;
use Database\Seeders\AdminSeeder;
use Illuminate\Support\Carbon;
use Tests\Support\CenarioAdmin;
use Tests\TestCase;

class AdminPaginasTest extends TestCase
{
    use CenarioAdmin;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function operador(): Usuario
    {
        $this->seed(AdminSeeder::class);

        return Usuario::where('login', 'admin')->firstOrFail();
    }


    public function test_visitante_vai_para_o_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/auditoria')->assertRedirect('/admin/login');
        $this->get('/admin/login')->assertOk()->assertSee('Entrar');
    }

    public function test_seeder_cria_admin_com_permissao_de_admin(): void
    {
        $m = $this->operador();
        $this->assertTrue($m->pode('ADMIN.ver'));
        $this->assertTrue($m->pode('LOG_OPERACAO.ver'));
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('senha-de-teste-123', $m->senha_hash));
        $this->seed(AdminSeeder::class);
        $this->assertSame(1, Usuario::where('login', 'admin')->count());
    }

    public function test_login_com_admin_entra_no_painel_e_registra_o_acesso(): void
    {
        $m = $this->operador();
        $this->post('/admin/login', ['login' => 'Admin', 'senha' => 'senha-de-teste-123'])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($m, 'admin');
        $this->assertDatabaseHas('log_acesso', ['usuario_id' => $m->id]);
        $this->get('/admin')->assertOk();
    }

    public function test_senha_errada_nao_entra(): void
    {
        $this->operador();
        $this->from('/admin/login')->post('/admin/login', ['login' => 'admin', 'senha' => 'errada1'])
            ->assertRedirect('/admin/login')->assertSessionHasErrors('login');
        $this->assertGuest('admin');
    }

    public function test_usuario_sem_permissao_nao_entra_mesmo_com_senha_certa(): void
    {
        $this->usuario('marcos', 'VENDEDOR', null, '12345678');
        $this->from('/admin/login')->post('/admin/login', ['login' => 'marcos', 'senha' => '12345678'])
            ->assertRedirect('/admin/login')->assertSessionHasErrors('login');
        $this->assertGuest('admin');
    }

    public function test_sessao_de_quem_perdeu_a_permissao_recebe_403(): void
    {
        $marcos = $this->usuario('marcos', 'VENDEDOR');
        $this->actingAs($marcos, 'admin')->get('/admin')->assertForbidden();
    }

    public function test_logout_encerra_a_sessao(): void
    {
        $this->actingAs($this->operador(), 'admin')->post('/admin/logout')->assertRedirect('/admin/login');
        $this->assertGuest('admin');
    }

    public function test_tentativas_de_login_sao_limitadas(): void
    {
        $this->operador();
        for ($i = 0; $i < 12; $i++) {
            $r = $this->post('/admin/login', ['login' => 'admin', 'senha' => 'errada'.$i]);
        }
        $r->assertStatus(429);
    }

    public function test_api_continua_em_json(): void
    {
        $this->getJson('/api/me')->assertUnauthorized()->assertJsonStructure(['message']);
        $this->get('/api/me')->assertUnauthorized()->assertJsonStructure(['message']);
        $this->get('/api/nao-existe')->assertNotFound()->assertJsonStructure(['message']);
    }


    public function test_visao_geral(): void
    {
        $this->montarCenario();
        $this->actingAs($this->operador(), 'admin')->get('/admin?de=2026-10-01&ate=2026-10-31')
            ->assertOk()->assertSee('Visão geral')->assertSee('Saving')->assertSee('sobre a menor resposta')
            ->assertSee('50%')->assertSee('SP → MG')->assertSee('<svg', false);
    }

    public function test_pagina_de_transportadoras_e_detalhe(): void
    {
        $this->montarCenario();
        $this->actingAs($this->operador(), 'admin');
        $this->get('/admin/transportadoras?de=2026-10-01&ate=2026-10-31')
            ->assertOk()->assertSee('TRANSLOG')->assertSee('ROTASUL')->assertSee('SEMPRE')->assertSee('Taxa de vitória')->assertSee('Oportunidades');
        $this->get('/admin/transportadoras/'.$this->tA->id.'?de=2026-10-01&ate=2026-10-31')
            ->assertOk()->assertSee('TRANSLOG')->assertSee('Onde ganha')->assertSee('Onde perde')->assertSee('SP → MG')->assertSee('SODA');
        $this->get('/admin/transportadoras/00000000-0000-0000-0000-000000000000')->assertNotFound();
    }

    public function test_pagina_de_vendedores_e_detalhe(): void
    {
        $this->montarCenario();
        $this->actingAs($this->operador(), 'admin');
        $this->get('/admin/vendedores?de=2026-10-01&ate=2026-10-31')->assertOk()->assertSee('Marcos')->assertSee('Lucia')->assertSee('Taxa de fechamento');
        $this->get('/admin/vendedores/'.$this->marcos->id.'?de=2026-10-01&ate=2026-10-31')->assertOk()->assertSee('Marcos')->assertSee('Cliente Um');
    }

    public function test_pagina_de_custos(): void
    {
        $this->montarCenario();
        $this->actingAs($this->operador(), 'admin')->get('/admin/custos?de=2026-10-01&ate=2026-10-31')
            ->assertOk()->assertSee('SODA')->assertSee('CARRETA')->assertSee('SP → SP');
    }

    public function test_pagina_de_auditoria_com_abas_e_filtros(): void
    {
        $this->montarCenario();
        $this->actingAs($this->operador(), 'admin');

        $this->get('/admin/auditoria')->assertOk()->assertSee('COTACAO_CRIADA')->assertSee('NEGOCIACAO_FECHADA')->assertSee('Marcos');
        $this->get('/admin/auditoria?evento=NEGOCIACAO_FECHADA')->assertOk()->assertSee('NEGOCIACAO_FECHADA')->assertDontSee('COTACAO_CRIADA');
        $this->get('/admin/auditoria?aba=sistema')->assertOk()->assertSee('falha no ERP')->assertSee('LOGIN_FALHA');
        $this->get('/admin/auditoria?aba=sistema&nivel=ERROR')->assertOk()->assertSee('falha no ERP')->assertDontSee('LOGIN_FALHA');
        $this->get('/admin/auditoria?aba=acessos')->assertOk()->assertSee('10.0.0.2');
        $this->get('/admin/auditoria?aba=resumo&de=2026-10-01&ate=2026-10-31')->assertOk()->assertSee('<svg', false);
        $this->get('/admin/auditoria/registro/COTACAO/'.$this->cot[1]->id)->assertOk()->assertSee('COT-000001')->assertSee('REGISTRO_ALTERADO');
    }

    public function test_paginas_abrem_com_banco_vazio(): void
    {
        $this->actingAs($this->operador(), 'admin');
        foreach (['/admin', '/admin/transportadoras', '/admin/vendedores', '/admin/custos', '/admin/auditoria', '/admin/auditoria?aba=sistema', '/admin/auditoria?aba=acessos', '/admin/auditoria?aba=resumo'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_entradas_invalidas_nao_geram_erro_500(): void
    {
        $this->montarCenario();
        $this->actingAs($this->operador(), 'admin');
        $this->get('/admin?de=lixo&ate=2026-99-99')->assertOk();
        $this->get('/admin/auditoria?aba=inexistente&nivel=XPTO&pagina=-4')->assertOk();
        $this->get('/admin/auditoria/registro/QUALQUER/nao-existe')->assertOk();
    }

    public function test_saida_e_escapada(): void
    {
        $this->montarCenario();
        \App\Models\LogSistema::create(['ocorrido_em' => '2026-10-07 10:00:00', 'nivel' => 'ERROR', 'categoria' => 'ERRO', 'evento' => 'EXCECAO', 'mensagem' => '<script>alert(1)</script>']);
        $this->actingAs($this->operador(), 'admin')->get('/admin/auditoria?aba=sistema')
            ->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    }
}
