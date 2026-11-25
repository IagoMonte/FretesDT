<?php

namespace Tests\Feature;

use App\Models\AnttCoeficiente;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Ajustes de perfis (gestão e vendedor) e do frete mínimo ANTT visível só a gestão e admin. */
class AjustesPerfisTest extends TestCase
{
    private function coeficiente(array $extra = []): AnttCoeficiente
    {
        return AnttCoeficiente::create($extra + [
            'tipo_carga_codigo' => 'CARGA_GERAL', 'eixos' => 5, 'ccd' => '5.0000', 'cc' => '500.00',
            'vigencia_inicio' => '2026-01-01', 'ativo' => true,
        ]);
    }

    private function distancia(string $km = '100.0'): void
    {
        DB::table('municipio')->insert([
            ['ibge' => '3536505', 'nome' => 'Paulínia', 'nome_busca' => 'PAULINIA', 'uf' => 'SP'],
            ['ibge' => '3509502', 'nome' => 'Campinas', 'nome_busca' => 'CAMPINAS', 'uf' => 'SP'],
        ]);
        DB::table('distancia')->insert(['origem_ibge' => '3509502', 'destino_ibge' => '3536505', 'km' => $km]);
    }

    private function consulta(array $extra = []): string
    {
        return '/api/antt/frete-minimo?'.http_build_query($extra + [
            'origem_cidade' => 'Paulínia', 'origem_uf' => 'SP', 'destino_cidade' => 'Campinas', 'destino_uf' => 'SP',
        ]);
    }

    public function test_gestao_nao_cadastra_transportadora_nem_usuario(): void
    {
        $gestao = $this->usuario('gestao', 'GESTAO_ADMIN');

        $this->como($gestao)->postJson('/api/transportadoras', ['razao_social' => 'X Ltda', 'nome_fantasia' => 'X'])->assertForbidden();
        $this->como($gestao)->getJson('/api/usuarios')->assertForbidden();
        $permissoes = $this->como($gestao)->getJson('/api/me')->assertOk()->json('data.permissoes');
        $this->assertFalse($permissoes['TRANSPORTADORA']['ver']);
        $this->assertFalse($permissoes['USUARIO']['ver']);
    }

    public function test_gestao_lista_transportadoras_para_encaminhar_e_fechar_mas_vendedor_nao(): void
    {
        $this->transportadora('TRANSLOG');

        $this->como($this->usuario('gestao', 'GESTAO_ADMIN'))->getJson('/api/transportadoras')->assertOk()->assertJsonPath('meta.total', 1);
        $this->como($this->usuario('marcos', 'VENDEDOR'))->getJson('/api/transportadoras')->assertForbidden();
    }

    public function test_gestao_cria_e_edita_cotacao_e_programacao(): void
    {
        $gestao = $this->usuario('gestao', 'GESTAO_ADMIN');

        $p = $this->como($gestao)->getJson('/api/me')->assertOk()->json('data.permissoes');
        foreach (['COTACAO', 'PROGRAMACAO'] as $recurso) {
            $this->assertTrue($p[$recurso]['ver'], "{$recurso}.ver");
            $this->assertTrue($p[$recurso]['inserir'], "{$recurso}.inserir");
            $this->assertTrue($p[$recurso]['alterar'], "{$recurso}.alterar");
            $this->assertFalse($p[$recurso]['cancelar'], "{$recurso}.cancelar");
        }

        $corpo = $this->corpoCotacao($this->cliente(), $this->produto());
        $c = $this->como($gestao)->postJson('/api/cotacoes', $corpo)->assertCreated();
        $this->como($gestao)->patchJson('/api/cotacoes/'.$c['data']['id'], ['valor_nf' => '16000.00', 'versao' => $c['data']['versao']] + $corpo)->assertOk();
    }

    public function test_vendedor_sem_cadastro_de_embalagens_mas_ainda_cota(): void
    {
        $vendedor = $this->usuario('marcos', 'VENDEDOR');

        $p = $this->como($vendedor)->getJson('/api/me')->assertOk()->json('data.permissoes');
        $this->assertFalse($p['EMBALAGEM']['ver'] ?? false);
        $this->como($vendedor)->getJson('/api/embalagens')->assertOk();
    }

    public function test_frete_minimo_da_resposta_so_para_gestao_e_admin(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $gestao = $this->usuario('gestao', 'GESTAO_ADMIN');
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $ta = $this->transportadora('TRANSLOG');
        $ua = $this->usuario('translog', 'TRANSPORTADORA', $ta);
        $this->coeficiente();
        $this->distancia();

        $c = $this->como($vendedor)->postJson('/api/cotacoes', $this->corpoCotacao($this->cliente(), $this->produto(), ['tipo_carga_antt_codigo' => 'CARGA_GERAL']))->assertCreated();
        $id = $c['data']['id'];
        $e = $this->como($admin)->postJson("/api/cotacoes/{$id}/encaminhar", ['transportadora_ids' => [$ta->id]])->assertOk();
        $resp = $e['data']['negociacao']['respostas'][0]['id'];
        $r = $this->como($ua)->postJson("/api/cotacoes/{$id}/respostas/{$resp}/responder", ['valor_tonelada' => '80.00', 'prazo_dias' => 3, 'eixos' => 5])->assertOk();
        $this->assertNull($r['data']['negociacao']['respostas'][0]['antt'], 'a transportadora não vê o piso');

        $esperado = ['piso' => '1000.00', 'km' => '100.0', 'eixos' => 5, 'diferenca' => '-40.00', 'abaixo' => true];
        foreach ([$admin, $gestao] as $u) {
            $v = $this->como($u)->getJson("/api/cotacoes/{$id}")->assertOk();
            $this->assertSame($esperado, $v['data']['negociacao']['respostas'][0]['antt']);
        }
        $v = $this->como($vendedor)->getJson("/api/cotacoes/{$id}")->assertOk();
        $this->assertNull($v['data']['negociacao']['respostas'][0]['antt'], 'o vendedor não vê o frete mínimo');
    }

    public function test_gestao_encaminha_e_fecha_a_cotacao(): void
    {
        $gestao = $this->usuario('gestao', 'GESTAO_ADMIN');
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $ta = $this->transportadora('TRANSLOG');
        $ua = $this->usuario('translog', 'TRANSPORTADORA', $ta);

        $acoes = $this->como($gestao)->getJson('/api/me')->assertOk()->json('data.acoes');
        $this->assertContains('NEGOCIACAO.ENCAMINHAR', $acoes);
        $this->assertContains('NEGOCIACAO.FECHAR', $acoes);

        $c = $this->como($vendedor)->postJson('/api/cotacoes', $this->corpoCotacao($this->cliente(), $this->produto()))->assertCreated();
        $id = $c['data']['id'];
        $e = $this->como($gestao)->postJson("/api/cotacoes/{$id}/encaminhar", ['transportadora_ids' => [$ta->id]])->assertOk();
        $resp = $e['data']['negociacao']['respostas'][0]['id'];
        $this->como($ua)->postJson("/api/cotacoes/{$id}/respostas/{$resp}/responder", ['valor_tonelada' => '80.00', 'prazo_dias' => 3])->assertOk();
        $this->como($gestao)->postJson("/api/cotacoes/{$id}/fechar", ['transportadora_id' => $ta->id, 'valor_tonelada' => '80.00', 'prazo_dias' => 3])->assertOk();
    }

    public function test_frete_minimo_por_origem_destino_eixos_e_tipo_de_carga(): void
    {
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $this->coeficiente();
        $this->distancia();

        $this->como($vendedor)->getJson($this->consulta(['eixos' => 5, 'tipo_carga_codigo' => 'CARGA_GERAL']))
            ->assertOk()
            ->assertJsonPath('data.km', '100.0')
            ->assertJsonPath('data.ccd', '5.0000')
            ->assertJsonPath('data.cc', '500.00')
            ->assertJsonPath('data.eixos', 5)
            ->assertJsonPath('data.frete_minimo', '1000.00');
    }

    public function test_frete_minimo_com_ccd_e_cc_informados(): void
    {
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $this->distancia('23.4');

        $this->como($vendedor)->getJson($this->consulta(['eixos' => 5, 'ccd' => '3.9826', 'cc' => '451.84']))
            ->assertOk()
            ->assertJsonPath('data.frete_minimo', '545.03')
            ->assertJsonPath('data.ccd', '3.9826')
            ->assertJsonPath('data.cc', '451.84');
    }

    public function test_frete_minimo_sem_par_de_cidades_ou_sem_coeficiente(): void
    {
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $this->distancia();

        $this->como($vendedor)->getJson($this->consulta(['eixos' => 5, 'tipo_carga_codigo' => 'CARGA_GERAL']))
            ->assertOk()->assertJsonPath('data', null);
        $this->coeficiente();
        $this->como($vendedor)->getJson($this->consulta(['destino_cidade' => 'Outra', 'eixos' => 5, 'tipo_carga_codigo' => 'CARGA_GERAL']))
            ->assertOk()->assertJsonPath('data', null);
    }

    public function test_frete_minimo_exige_eixos_e_coeficiente_ou_ccd_cc(): void
    {
        $vendedor = $this->usuario('marcos', 'VENDEDOR');

        $this->como($vendedor)->getJson($this->consulta())->assertUnprocessable();
        $this->como($vendedor)->getJson($this->consulta(['eixos' => 5]))->assertUnprocessable();
        $this->como($vendedor)->getJson($this->consulta(['eixos' => 12, 'ccd' => '1', 'cc' => '1']))->assertUnprocessable();
    }

    public function test_frete_minimo_so_para_quem_cota(): void
    {
        $ta = $this->transportadora('TRANSLOG');
        $ua = $this->usuario('translog', 'TRANSPORTADORA', $ta);

        $this->como($ua)->getJson($this->consulta(['eixos' => 5, 'ccd' => '1', 'cc' => '1']))->assertForbidden();
    }
}
