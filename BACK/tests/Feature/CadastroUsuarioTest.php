<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Tests\TestCase;

class CadastroUsuarioTest extends TestCase
{
    private const CNPJ = '11222333000181';

    private function corpo(array $extra = []): array
    {
        return $extra + [
            'nome' => 'Maria Souza', 'login' => '  Maria.Souza ', 'email' => 'Maria@Exemplo.com',
            'senha' => 'segredo123', 'senha_confirmation' => 'segredo123', 'tipo' => 'VENDEDOR',
        ];
    }

    private function corpoTransportadora(array $extra = []): array
    {
        return $this->corpo($extra + [
            'tipo' => 'TRANSPORTADORA', 'razao_social' => 'Rodo Rapido Ltda', 'nome_fantasia' => 'Rodo Rapido', 'cnpj' => '11.222.333/0001-81',
        ]);
    }

    private function admin(): Usuario
    {
        return $this->usuario('admin', 'ADMINISTRADOR');
    }

    public function test_cadastro_publico_cria_usuario_pendente_normalizado(): void
    {
        $r = $this->postJson('/api/auth/cadastro', $this->corpo())->assertCreated();

        $r->assertJsonPath('message', 'Cadastro enviado. Aguarde a aprovação do administrador.')->assertJsonPath('data.situacao', 'PENDENTE');
        $u = Usuario::where('login', 'maria.souza')->firstOrFail();
        $this->assertSame('PENDENTE', $u->situacao);
        $this->assertSame('maria@exemplo.com', $u->email);
        $this->assertSame('VENDEDOR', $u->tipo_solicitado);
        $this->assertNull($u->cad_cnpj);
        $this->assertDatabaseHas('log_operacao', ['entidade' => 'USUARIO', 'registro_id' => $u->id, 'evento' => 'CADASTRO_SOLICITADO']);
    }

    public function test_transportadora_guarda_os_dados_informados_e_valida_cnpj(): void
    {
        $this->postJson('/api/auth/cadastro', $this->corpoTransportadora())->assertCreated();
        $u = Usuario::where('login', 'maria.souza')->firstOrFail();
        $this->assertSame(self::CNPJ, $u->cad_cnpj);
        $this->assertSame('Rodo Rapido Ltda', $u->cad_razao_social);

        $this->postJson('/api/auth/cadastro', $this->corpoTransportadora(['login' => 'outro', 'email' => 'o@x.com', 'cnpj' => '11222333000182']))
            ->assertStatus(422)->assertJsonPath('errors.cnpj.0', 'CNPJ inválido.');
        $this->postJson('/api/auth/cadastro', $this->corpoTransportadora(['login' => 'outro', 'email' => 'o@x.com', 'cnpj' => null, 'razao_social' => null]))
            ->assertStatus(422)->assertJsonValidationErrors(['cnpj', 'razao_social']);
    }

    public function test_login_duplicado_sem_diferenciar_caixa_e_validacoes_de_senha(): void
    {
        $this->usuario('Marcos', 'VENDEDOR');
        $this->postJson('/api/auth/cadastro', $this->corpo(['login' => 'MARCOS']))
            ->assertStatus(422)->assertJsonPath('errors.login.0', 'Login indisponível.');

        $this->postJson('/api/auth/cadastro', $this->corpo(['senha' => 'curta', 'senha_confirmation' => 'curta']))
            ->assertStatus(422)->assertJsonValidationErrors('senha');
        $this->postJson('/api/auth/cadastro', $this->corpo(['senha_confirmation' => 'diferente123']))
            ->assertStatus(422)->assertJsonValidationErrors('senha');
        $this->postJson('/api/auth/cadastro', $this->corpo(['tipo' => 'ADMINISTRADOR']))
            ->assertStatus(422)->assertJsonValidationErrors('tipo');

        $this->postJson('/api/auth/cadastro', $this->corpo())->assertCreated();
        $this->postJson('/api/auth/cadastro', $this->corpo(['login' => 'novo']))
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'E-mail já cadastrado.');
    }

    public function test_cadastro_tem_rate_limit_por_ip(): void
    {
        $limite = config('frete.cadastro_tentativas_por_hora');
        for ($i = 0; $i < $limite; $i++) {
            $this->postJson('/api/auth/cadastro', [])->assertStatus(422);
        }
        $this->postJson('/api/auth/cadastro', [])->assertStatus(429);
    }

    public function test_login_de_pendente_e_recusado_so_revela_com_senha_correta(): void
    {
        $this->postJson('/api/auth/cadastro', $this->corpo())->assertCreated();

        $this->postJson('/api/auth/login', ['login' => 'maria.souza', 'senha' => 'errada123'])
            ->assertStatus(401)->assertJson(['message' => 'Usuário ou senha inválidos.'])->assertJsonMissingPath('code');
        $this->postJson('/api/auth/login', ['login' => 'Maria.Souza', 'senha' => 'segredo123'])
            ->assertStatus(403)->assertJson(['message' => 'Seu cadastro está em análise.', 'code' => 'CADASTRO_PENDENTE']);

        Usuario::where('login', 'maria.souza')->update(['situacao' => 'RECUSADO', 'motivo_recusa' => 'CNPJ não confere']);
        $this->postJson('/api/auth/login', ['login' => 'maria.souza', 'senha' => 'segredo123'])
            ->assertStatus(403)->assertJson(['message' => 'Cadastro recusado: CNPJ não confere', 'code' => 'CADASTRO_RECUSADO']);
        $this->postJson('/api/auth/login', ['login' => 'maria.souza', 'senha' => 'errada123'])->assertStatus(401);
    }

    public function test_refresh_e_me_barram_quem_nao_esta_ativo(): void
    {
        $u = $this->usuario('marcos', 'VENDEDOR');
        $login = $this->postJson('/api/auth/login', ['login' => 'marcos', 'senha' => 'senha1'])->assertOk();
        $acesso = $login['access_token'];

        $u->forceFill(['situacao' => 'RECUSADO', 'motivo_recusa' => 'x'])->saveQuietly();

        $this->withToken($acesso)->getJson('/api/me')->assertStatus(401);
        $this->postJson('/api/auth/refresh', ['refresh_token' => $login['refresh_token']])->assertStatus(401);
    }

    public function test_so_o_administrador_ve_e_decide_cadastros(): void
    {
        $this->postJson('/api/auth/cadastro', $this->corpo())->assertCreated();
        $id = Usuario::where('login', 'maria.souza')->value('id');
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $transp = $this->usuario('alfa', 'TRANSPORTADORA', $this->transportadora('ALFA'));

        $this->getJson('/api/cadastros')->assertStatus(401);
        foreach ([$vendedor, $transp] as $u) {
            $this->como($u)->getJson('/api/cadastros')->assertForbidden();
            $this->como($u)->postJson("/api/cadastros/{$id}/aprovar", ['grupo' => 'VENDEDOR'])->assertForbidden();
            $this->como($u)->postJson("/api/cadastros/{$id}/recusar", ['motivo' => 'teste'])->assertForbidden();
        }
        $this->assertSame('PENDENTE', Usuario::find($id)->situacao);
    }

    public function test_admin_lista_pendentes_com_sugestao_por_cnpj(): void
    {
        $existente = $this->transportadora('RODO');
        $existente->update(['cnpj' => self::CNPJ]);
        $this->postJson('/api/auth/cadastro', $this->corpoTransportadora())->assertCreated();
        $this->postJson('/api/auth/cadastro', $this->corpo(['login' => 'joao', 'email' => 'j@x.com']))->assertCreated();
        $admin = $this->admin();

        $r = $this->como($admin)->getJson('/api/cadastros?status=PENDENTE')->assertOk();
        $this->assertSame(2, $r['meta']['total']);
        $transp = collect($r['data'])->firstWhere('login', 'maria.souza');
        $this->assertSame($existente->id, $transp['transportadora_sugerida']['id']);
        $this->assertSame(self::CNPJ, $transp['transportadora_dados']['cnpj']);
        $this->assertArrayNotHasKey('senha_hash', $transp);

        $this->como($admin)->getJson('/api/cadastros?status=INVALIDO')->assertStatus(422);
        $this->assertSame(0, $this->como($admin)->getJson('/api/cadastros?status=ATIVO')['meta']['total']);
    }

    public function test_aprovar_como_vendedor_libera_o_login(): void
    {
        $this->postJson('/api/auth/cadastro', $this->corpo())->assertCreated();
        $u = Usuario::where('login', 'maria.souza')->firstOrFail();
        $admin = $this->admin();

        $r = $this->como($admin)->postJson("/api/cadastros/{$u->id}/aprovar", ['grupo' => 'VENDEDOR'])->assertOk();
        $this->assertSame('ATIVO', $r['data']['situacao']);
        $this->assertSame(['VENDEDOR'], $r['data']['grupos']);
        $this->assertSame($admin->id, $r['data']['decidido_por']['id']);
        $this->assertDatabaseHas('log_operacao', ['registro_id' => $u->id, 'evento' => 'CADASTRO_APROVADO', 'usuario_id' => $admin->id]);

        $login = $this->postJson('/api/auth/login', ['login' => 'maria.souza', 'senha' => 'segredo123'])->assertOk();
        $this->assertSame('INTERNO', $login['usuario']['tipo_usuario']);
        $this->assertTrue($login['usuario']['permissoes']['COTACAO']['inserir']);

        $this->como($admin)->postJson("/api/cadastros/{$u->id}/aprovar", ['grupo' => 'VENDEDOR'])->assertStatus(409);
        $this->como($admin)->postJson("/api/cadastros/{$u->id}/recusar", ['motivo' => 'tarde'])->assertStatus(409);
    }

    public function test_aprovar_transportadora_vinculando_a_existente(): void
    {
        $alvo = $this->transportadora('ALFA');
        $this->postJson('/api/auth/cadastro', $this->corpoTransportadora())->assertCreated();
        $u = Usuario::where('login', 'maria.souza')->firstOrFail();
        $admin = $this->admin();

        $this->como($admin)->postJson("/api/cadastros/{$u->id}/aprovar", ['grupo' => 'TRANSPORTADORA'])
            ->assertStatus(422)->assertJsonValidationErrors('transportadora_id');

        $r = $this->como($admin)->postJson("/api/cadastros/{$u->id}/aprovar", ['grupo' => 'TRANSPORTADORA', 'transportadora_id' => $alvo->id])->assertOk();
        $this->assertSame($alvo->id, $r['data']['transportadora']['id']);
        $this->assertDatabaseCount('transportadora', 1);

        $login = $this->postJson('/api/auth/login', ['login' => 'maria.souza', 'senha' => 'segredo123'])->assertOk();
        $this->assertSame('TRANSPORTADORA', $login['usuario']['tipo_usuario']);
        $this->assertSame($alvo->id, $login['usuario']['transportadora']['id']);
    }

    public function test_aprovar_transportadora_criando_a_partir_do_cadastro(): void
    {
        $this->postJson('/api/auth/cadastro', $this->corpoTransportadora())->assertCreated();
        $u = Usuario::where('login', 'maria.souza')->firstOrFail();

        $r = $this->como($this->admin())->postJson("/api/cadastros/{$u->id}/aprovar", ['grupo' => 'TRANSPORTADORA', 'criar_transportadora' => true])->assertOk();

        $this->assertDatabaseHas('transportadora', ['cnpj' => self::CNPJ, 'razao_social' => 'Rodo Rapido Ltda', 'nome_fantasia' => 'Rodo Rapido', 'ativo' => true]);
        $this->assertSame('Rodo Rapido', $r['data']['transportadora']['nome_fantasia']);
        $this->postJson('/api/auth/login', ['login' => 'maria.souza', 'senha' => 'segredo123'])->assertOk();
    }

    public function test_criar_transportadora_com_cnpj_existente_exige_vincular(): void
    {
        $this->transportadora('RODO')->update(['cnpj' => self::CNPJ]);
        $this->postJson('/api/auth/cadastro', $this->corpoTransportadora())->assertCreated();
        $u = Usuario::where('login', 'maria.souza')->firstOrFail();

        $this->como($this->admin())->postJson("/api/cadastros/{$u->id}/aprovar", ['grupo' => 'TRANSPORTADORA', 'criar_transportadora' => true])
            ->assertStatus(422)->assertJsonPath('errors.transportadora_id.0', 'Já existe uma transportadora com este CNPJ. Vincule a ela.');
        $this->assertSame('PENDENTE', $u->fresh()->situacao);
        $this->assertDatabaseCount('transportadora', 1);
    }

    public function test_vendedor_nao_aceita_transportadora(): void
    {
        $alvo = $this->transportadora('ALFA');
        $this->postJson('/api/auth/cadastro', $this->corpo())->assertCreated();
        $u = Usuario::where('login', 'maria.souza')->firstOrFail();

        $this->como($this->admin())->postJson("/api/cadastros/{$u->id}/aprovar", ['grupo' => 'VENDEDOR', 'transportadora_id' => $alvo->id])
            ->assertStatus(422);
    }

    public function test_recusar_exige_motivo_e_bloqueia_o_login_com_a_mensagem(): void
    {
        $this->postJson('/api/auth/cadastro', $this->corpo())->assertCreated();
        $u = Usuario::where('login', 'maria.souza')->firstOrFail();
        $admin = $this->admin();

        $this->como($admin)->postJson("/api/cadastros/{$u->id}/recusar", [])->assertStatus(422)->assertJsonValidationErrors('motivo');

        $r = $this->como($admin)->postJson("/api/cadastros/{$u->id}/recusar", ['motivo' => 'Não conhecemos a empresa'])->assertOk();
        $this->assertSame('RECUSADO', $r['data']['situacao']);
        $this->assertSame('Não conhecemos a empresa', $r['data']['motivo_recusa']);
        $this->assertDatabaseHas('log_operacao', ['registro_id' => $u->id, 'evento' => 'CADASTRO_RECUSADO', 'status_para' => 'RECUSADO']);

        $this->postJson('/api/auth/login', ['login' => 'maria.souza', 'senha' => 'segredo123'])
            ->assertStatus(403)->assertJson(['message' => 'Cadastro recusado: Não conhecemos a empresa', 'code' => 'CADASTRO_RECUSADO']);

        $hist = $this->como($admin)->getJson('/api/cadastros?status=ATIVO,RECUSADO')->assertOk();
        $this->assertSame(1, $hist['meta']['total']);
        $this->assertSame(0, $this->como($admin)->getJson('/api/cadastros')['meta']['total']);
    }

    public function test_cadastro_inexistente_ou_de_usuario_comum_devolve_404(): void
    {
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $admin = $this->admin();

        $this->como($admin)->postJson('/api/cadastros/inexistente/aprovar', ['grupo' => 'VENDEDOR'])->assertNotFound();
        $this->como($admin)->postJson("/api/cadastros/{$vendedor->id}/recusar", ['motivo' => 'xxx'])->assertNotFound();
    }
}
