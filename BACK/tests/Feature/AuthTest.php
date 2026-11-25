<?php

namespace Tests\Feature;

use App\Models\RefreshToken;
use App\Models\Usuario;
use Tests\TestCase;

class AuthTest extends TestCase
{
    public function test_login_devolve_tokens_e_usuario_com_permissoes(): void
    {
        $this->usuario('marcos', 'VENDEDOR');

        $r = $this->postJson('/api/auth/login', ['login' => 'marcos', 'senha' => 'senha1'])->assertOk();

        $r->assertJsonStructure(['access_token', 'token_type', 'expires_in', 'refresh_token', 'usuario' => ['id', 'nome', 'login', 'tipo_usuario', 'grupos', 'permissoes', 'acoes']]);
        $this->assertSame('Bearer', $r['token_type']);
        $this->assertSame(900, $r['expires_in']);
        $this->assertSame(['VENDEDOR'], $r['usuario']['grupos']);
        $this->assertTrue($r['usuario']['permissoes']['COTACAO']['inserir']);
        $this->assertSame('PROPRIOS', $r['usuario']['permissoes']['COTACAO']['escopo']);
        $this->assertFalse($r['usuario']['permissoes']['NEGOCIACAO']['inserir']);
        $this->assertDatabaseCount('log_acesso', 1);
    }

    public function test_sem_token_devolve_401_json_mesmo_sem_header_accept(): void
    {
        $this->get('/api/me')->assertStatus(401)->assertJson(['message' => 'Não autenticado ou sessão expirada.']);
    }

    public function test_login_com_senha_errada_ou_bloqueado(): void
    {
        $u = $this->usuario('marcos', 'VENDEDOR');

        $this->postJson('/api/auth/login', ['login' => 'marcos', 'senha' => 'errada'])
            ->assertStatus(401)->assertJson(['message' => 'Usuário ou senha inválidos.']);

        $u->forceFill(['situacao' => 'BLOQUEADO'])->saveQuietly();
        $this->postJson('/api/auth/login', ['login' => 'marcos', 'senha' => 'senha1'])->assertStatus(403);
    }

    public function test_login_tem_rate_limit_por_ip_e_login(): void
    {
        $this->usuario('marcos', 'VENDEDOR');
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['login' => 'marcos', 'senha' => 'x'])->assertStatus(401);
        }
        $this->postJson('/api/auth/login', ['login' => 'marcos', 'senha' => 'x'])->assertStatus(429);
    }

    public function test_me_exige_token_e_devolve_permissoes_efetivas(): void
    {
        $t = $this->transportadora('TRANSLOG');
        $u = $this->usuario('translog', 'TRANSPORTADORA', $t);

        $this->getJson('/api/me')->assertStatus(401);
        $this->getJson('/api/me', ['Authorization' => 'Bearer lixo'])->assertStatus(401);

        $r = $this->como($u)->getJson('/api/me')->assertOk();
        $this->assertSame('TRANSPORTADORA', $r['data']['tipo_usuario']);
        $this->assertSame(['id' => $t->id, 'nome_fantasia' => 'TRANSLOG'], $r['data']['transportadora']);
        $this->assertSame('TRANSPORTADORA', $r['data']['permissoes']['COTACAO']['escopo']);
        $this->assertFalse($r['data']['permissoes']['PRODUTO']['ver']);
        $this->assertContains('NEGOCIACAO.RESPONDER', $r['data']['acoes']);
        $this->assertNotContains('NEGOCIACAO.FECHAR', $r['data']['acoes']);
    }

    public function test_refresh_rotaciona_e_reuso_revoga_a_familia(): void
    {
        $this->usuario('marcos', 'VENDEDOR');
        $login = $this->postJson('/api/auth/login', ['login' => 'marcos', 'senha' => 'senha1'])->assertOk();
        $primeiro = $login['refresh_token'];

        $segundo = $this->postJson('/api/auth/refresh', ['refresh_token' => $primeiro])->assertOk()['refresh_token'];
        $this->assertNotSame($primeiro, $segundo);
        $this->assertSame(RefreshToken::first()->familia_id, RefreshToken::latest('emitido_em')->first()->familia_id);

        $this->travel(config('frete.jwt.refresh_graca_segundos') + 5)->seconds();
        $this->postJson('/api/auth/refresh', ['refresh_token' => $primeiro])->assertStatus(401);
        $this->postJson('/api/auth/refresh', ['refresh_token' => $segundo])->assertStatus(401);
        $this->assertSame(0, RefreshToken::whereNull('revogado_em')->count());
        $this->assertSame('REUSO_DETECTADO', RefreshToken::whereNotNull('revogado_em')->value('motivo_revogacao'));
    }

    public function test_logout_revoga_o_refresh(): void
    {
        $u = $this->usuario('marcos', 'VENDEDOR');
        $login = $this->postJson('/api/auth/login', ['login' => 'marcos', 'senha' => 'senha1'])->assertOk();

        $this->withToken($login['access_token'])->postJson('/api/auth/logout', ['refresh_token' => $login['refresh_token']])->assertNoContent();
        $this->postJson('/api/auth/refresh', ['refresh_token' => $login['refresh_token']])->assertStatus(401);
    }

    public function test_token_versao_invalida_access_token_na_hora(): void
    {
        $u = $this->usuario('marcos', 'VENDEDOR');
        $this->como($u)->getJson('/api/me')->assertOk();

        $u->forceFill(['token_versao' => 1])->saveQuietly();
        $this->como(Usuario::find($u->id)->forceFill(['token_versao' => 0]))->getJson('/api/me')->assertStatus(401);
    }

    public function test_token_malformado_e_401_e_nao_500(): void
    {
        foreach (['xxx.yyy.zzz', 'lixo', 'a.b', '...'] as $token) {
            $this->withToken($token)->getJson('/api/me')->assertStatus(401);
        }
    }
}
