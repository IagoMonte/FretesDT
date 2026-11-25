<?php

namespace Tests\Feature;

use Tests\TestCase;

class EscopoTest extends TestCase
{
    public function test_transportadora_nao_ve_concorrente_e_id_fora_do_escopo_e_404(): void
    {
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $outro = $this->usuario('lucia', 'VENDEDOR');
        $ta = $this->transportadora('TRANSLOG');
        $tb = $this->transportadora('ROTA SUL');
        $tc = $this->transportadora('SEM CONVITE');
        $ua = $this->usuario('translog', 'TRANSPORTADORA', $ta);
        $ub = $this->usuario('rotasul', 'TRANSPORTADORA', $tb);
        $uc = $this->usuario('semconvite', 'TRANSPORTADORA', $tc);
        $cliente = $this->cliente();
        $produto = $this->produto();

        $id = $this->como($vendedor)->postJson('/api/cotacoes', $this->corpoCotacao($cliente, $produto))->json('data.id');
        $outroId = $this->como($outro)->postJson('/api/cotacoes', $this->corpoCotacao($cliente, $produto))->json('data.id');
        $enc = $this->como($admin)->postJson("/api/cotacoes/{$id}/encaminhar", ['transportadora_ids' => [$ta->id, $tb->id]])->json('data.negociacao.respostas');
        $respA = collect($enc)->firstWhere('transportadora.id', $ta->id)['id'];
        $respB = collect($enc)->firstWhere('transportadora.id', $tb->id)['id'];

        $this->como($ua)->postJson("/api/cotacoes/{$id}/respostas/{$respA}/responder", ['valor_tonelada' => '100', 'prazo_dias' => 2])->assertOk();
        $this->como($ub)->postJson("/api/cotacoes/{$id}/respostas/{$respB}/responder", ['valor_tonelada' => '90', 'prazo_dias' => 1])->assertOk();

        $vista = $this->como($ua)->getJson("/api/cotacoes/{$id}")->assertOk();
        $this->assertCount(1, $vista['data']['negociacao']['respostas']);
        $this->assertSame($ta->id, $vista['data']['negociacao']['respostas'][0]['transportadora']['id']);
        $this->assertStringNotContainsString($tb->id, $vista->getContent());
        $this->assertStringNotContainsString('ROTA SUL', $vista->getContent());

        $lista = $this->getJson('/api/cotacoes')->assertOk();
        $this->assertSame(1, $lista['meta']['total']);
        $this->assertCount(1, $lista['data'][0]['negociacao']['respostas']);

        $this->postJson("/api/cotacoes/{$id}/respostas/{$respB}/responder", ['valor_tonelada' => '1', 'prazo_dias' => 1])->assertNotFound();
        $this->como($uc)->getJson("/api/cotacoes/{$id}")->assertNotFound();
        $this->getJson('/api/cotacoes')->assertOk()->assertJsonPath('meta.total', 0);

        $this->como($vendedor)->getJson("/api/cotacoes/{$outroId}")->assertNotFound();
        $this->getJson('/api/cotacoes')->assertJsonPath('meta.total', 1);
        $this->assertCount(2, $this->getJson("/api/cotacoes/{$id}")['data']['negociacao']['respostas']);

        $this->como($admin)->postJson("/api/cotacoes/{$id}/fechar", ['transportadora_id' => $tb->id, 'valor_tonelada' => '90', 'prazo_dias' => 1])->assertOk();
        $this->assertNull($this->como($ua)->getJson("/api/cotacoes/{$id}")['data']['negociacao']['fechamento']);
        $this->assertNotNull($this->como($ub)->getJson("/api/cotacoes/{$id}")['data']['negociacao']['fechamento']);
        $this->como($ua)->getJson('/api/programacoes')->assertJsonPath('meta.total', 0);
        $this->como($ub)->getJson('/api/programacoes')->assertJsonPath('meta.total', 1);
    }

    public function test_permissoes_por_recurso_retornam_403(): void
    {
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $this->como($vendedor)->postJson('/api/transportadoras', [])->assertForbidden()->assertJsonStructure(['message']);
        $this->getJson('/api/usuarios')->assertForbidden();
        $this->getJson('/api/painel')->assertForbidden();
        $this->getJson('/api/produtos')->assertOk();
        $this->getJson('/api/dominios')->assertOk();
    }
}