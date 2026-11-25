<?php

namespace Tests\Feature;

use App\Models\AnttCoeficiente;
use Illuminate\Support\Facades\DB;
use App\Services\AnttService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PisoAnttTest extends TestCase
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

    #[DataProvider('pisos')]
    public function test_calcula_piso_km_x_ccd_mais_cc(string $km, string $ccd, string $cc, string $esperado): void
    {
        $c = new AnttCoeficiente(['ccd' => $ccd, 'cc' => $cc]);
        $this->assertSame($esperado, (new AnttService)->calcular($km, $c));
    }

    public static function pisos(): array
    {
        return [
            'inteiro' => ['100', '5.0000', '500.00', '1000.00'],
            'arredonda HALF_UP' => ['10.5', '3.9826', '451.84', '493.66'],
            'km zero = só CC' => ['0', '9.2027', '903.32', '903.32'],
        ];
    }

    public function test_coeficiente_respeita_vigencia_e_ativo(): void
    {
        $antigo = $this->coeficiente(['vigencia_inicio' => '2026-01-01', 'vigencia_fim' => '2026-06-30', 'ccd' => '4.0000']);
        $novo = $this->coeficiente(['vigencia_inicio' => '2026-07-01', 'ccd' => '5.0000']);
        $s = new AnttService;

        $this->assertSame($antigo->id, $s->coeficiente('CARGA_GERAL', 5, '2026-03-10')->id);
        $this->assertSame($novo->id, $s->coeficiente('CARGA_GERAL', 5, '2026-07-01')->id);
        $this->assertNull($s->coeficiente('CARGA_GERAL', 5, '2025-12-31'));
        $this->assertNull($s->coeficiente('CARGA_GERAL', 6, '2026-07-01'));
        $novo->update(['ativo' => false]);
        $this->assertNull($s->coeficiente('CARGA_GERAL', 5, '2026-07-01'));
    }

    public function test_fluxo_grava_snapshot_e_so_o_admin_ve_o_piso(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $ta = $this->transportadora('TRANSLOG');
        $ua = $this->usuario('translog', 'TRANSPORTADORA', $ta);
        $this->coeficiente();
        $this->distancia();

        $c = $this->como($vendedor)->postJson('/api/cotacoes', $this->corpoCotacao($this->cliente(), $this->produto(), ['tipo_carga_antt_codigo' => 'CARGA_GERAL']))->assertCreated();
        $id = $c['data']['id'];
        $this->assertSame('100.0', $c['data']['distancia_km']);

        $e = $this->como($admin)->postJson("/api/cotacoes/{$id}/encaminhar", ['transportadora_ids' => [$ta->id]])->assertOk();
        $resp = $e['data']['negociacao']['respostas'][0]['id'];

        $r = $this->como($ua)->postJson("/api/cotacoes/{$id}/respostas/{$resp}/responder", ['valor_tonelada' => '80.00', 'prazo_dias' => 3, 'eixos' => 5])->assertOk();
        $this->assertSame(5, $r['data']['negociacao']['respostas'][0]['eixos']);
        $this->assertNull($r['data']['negociacao']['respostas'][0]['antt']);

        $v = $this->como($admin)->getJson("/api/cotacoes/{$id}")->assertOk();
        $this->assertSame(
            ['piso' => '1000.00', 'km' => '100.0', 'eixos' => 5, 'diferenca' => '-40.00', 'abaixo' => true],
            $v['data']['negociacao']['respostas'][0]['antt'],
        );

        AnttCoeficiente::query()->update(['ccd' => '9.0000']);
        $v = $this->como($admin)->getJson("/api/cotacoes/{$id}")->assertOk();
        $this->assertSame('1000.00', $v['data']['negociacao']['respostas'][0]['antt']['piso']);

        $f = $this->como($admin)->postJson("/api/cotacoes/{$id}/fechar", [
            'transportadora_id' => $ta->id, 'valor_tonelada' => '90.00', 'prazo_dias' => 3,
        ])->assertOk();
        $this->assertSame('1400.00', $f['data']['negociacao']['fechamento']['antt']['piso']);
        $this->assertTrue($f['data']['negociacao']['fechamento']['antt']['abaixo']);
    }

    public function test_sem_eixos_ou_km_nao_ha_piso(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $ta = $this->transportadora('TRANSLOG');
        $this->coeficiente();

        $c = $this->como($admin)->postJson('/api/cotacoes', $this->corpoCotacao($this->cliente(), $this->produto(), ['tipo_carga_antt_codigo' => 'CARGA_GERAL', 'distancia_km' => 100]))->assertCreated();
        $e = $this->como($admin)->postJson("/api/cotacoes/{$c['data']['id']}/encaminhar", ['transportadora_ids' => [$ta->id]]);
        $resp = $e['data']['negociacao']['respostas'][0]['id'];
        $r = $this->como($admin)->postJson("/api/cotacoes/{$c['data']['id']}/respostas/{$resp}/responder", ['valor_tonelada' => '80.00', 'prazo_dias' => 3])->assertOk();

        $this->assertNull($r['data']['negociacao']['respostas'][0]['antt']);
    }

    public function test_coeficientes_so_para_admin_e_sem_duplicar_vigencia(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $corpo = ['tipo_carga_codigo' => 'CARGA_GERAL', 'eixos' => 3, 'ccd' => '4.5', 'cc' => '400', 'vigencia_inicio' => '2026-01-01'];

        $this->como($vendedor)->getJson('/api/antt/coeficientes')->assertForbidden();
        $this->como($vendedor)->postJson('/api/antt/coeficientes', $corpo)->assertForbidden();
        $criado = $this->como($admin)->postJson('/api/antt/coeficientes', $corpo)->assertCreated();
        $this->como($admin)->postJson('/api/antt/coeficientes', $corpo)->assertUnprocessable();
        $this->como($admin)->patchJson('/api/antt/coeficientes/'.$criado['data']['id'], ['ccd' => '4.7', 'versao' => $criado['data']['versao']])->assertOk();
        $this->como($admin)->getJson('/api/antt/coeficientes')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_consulta_de_distancia(): void
    {
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $this->distancia('23.4');
        $q = ['origem_cidade' => 'Paulínia', 'origem_uf' => 'sp', 'destino_cidade' => 'Campinas', 'destino_uf' => 'SP'];

        $this->como($vendedor)->getJson('/api/distancias/consulta?'.http_build_query($q))->assertOk()->assertJsonPath('data.km', '23.4');
        $this->como($vendedor)->getJson('/api/distancias/consulta?'.http_build_query(['destino_cidade' => 'Outra'] + $q))->assertOk()->assertJsonPath('data', null);
    }

    public function test_baixa_distancias_importa_pares_nos_dois_sentidos_e_retoma(): void
    {
        $dir = sys_get_temp_dir().'/antt_'.uniqid();
        mkdir($dir);
        file_put_contents("{$dir}/mun.json", json_encode([
            ['id' => 3536505, 'nome' => 'Paulínia', 'microrregiao' => ['mesorregiao' => ['UF' => ['sigla' => 'SP']]]],
            ['id' => 3509502, 'nome' => 'Campinas', 'microrregiao' => ['mesorregiao' => ['UF' => ['sigla' => 'SP']]]],
            ['id' => 4106902, 'nome' => 'Curitiba', 'regiao-imediata' => ['regiao-intermediaria' => ['UF' => ['sigla' => 'PR']]]],
        ]));
        file_put_contents("{$dir}/dist.csv", "orig;dest;dist;dur
3536505;3509502;23400;30,1
3536505;4106902;420500;300,2
3536505;3536505;0;0
3509502;4106902;;
");
        $args = ['--csv' => "{$dir}/dist.csv", '--municipios' => "{$dir}/mun.json"];

        $this->artisan('antt:baixa-distancias', $args)->assertSuccessful();
        $s = new AnttService;
        $this->assertSame(2, DB::table('distancia')->count());
        $this->assertSame('23.4', $s->distancia('Paulínia', 'sp', 'Campinas', 'SP'));
        $this->assertSame('23.4', $s->distancia('CAMPINAS', 'SP', 'PAULINIA', 'SP'));
        $this->assertSame('420.5', $s->distancia('Curitiba', 'PR', 'Paulínia', 'SP'));
        $this->assertNull($s->distancia('Campinas', 'SP', 'Curitiba', 'PR'));

        $this->artisan('antt:baixa-distancias', $args + ['--force' => true])->assertSuccessful();
        $this->assertSame(2, DB::table('distancia')->count());
    }
}
