<?php

namespace Tests\Feature;

use Tests\TestCase;

/** A transportadora vê só Em negociação, Respondido, Programado (vencedora) e Encerrado; nunca o status real. */
class StatusTransportadoraTest extends TestCase
{
    private function cenario(): array
    {
        $c = [
            'admin' => $this->usuario('admin', 'ADMINISTRADOR'),
            'vendedor' => $this->usuario('marcos', 'VENDEDOR'),
        ];
        foreach (['a' => 'TRANSLOG', 'b' => 'ROTA SUL', 'c' => 'SEM RESPOSTA'] as $k => $nome) {
            $c["t$k"] = $this->transportadora($nome);
            $c["u$k"] = $this->usuario(strtolower($k).'login', 'TRANSPORTADORA', $c["t$k"]);
        }
        $c['cliente'] = $this->cliente();
        $c['produto'] = $this->produto();

        return $c;
    }

    /** Cria a cotação, encaminha para A, B e C e devolve [id, respA, respB, respC]. */
    private function encaminhada(array $c): array
    {
        $id = $this->como($c['vendedor'])->postJson('/api/cotacoes', $this->corpoCotacao($c['cliente'], $c['produto']))->json('data.id');
        $enc = collect($this->como($c['admin'])->postJson("/api/cotacoes/{$id}/encaminhar", [
            'transportadora_ids' => [$c['ta']->id, $c['tb']->id, $c['tc']->id],
        ])->json('data.negociacao.respostas'));
        $resp = fn ($t) => $enc->firstWhere('transportadora.id', $t->id)['id'];

        return [$id, $resp($c['ta']), $resp($c['tb']), $resp($c['tc'])];
    }

    private function statusVisto(array $c, string $u, string $id): string
    {
        return $this->como($c[$u])->getJson("/api/cotacoes/{$id}")->assertOk()->json('data.status');
    }

    public function test_em_negociacao_sem_resposta_e_respondido_com_resposta(): void
    {
        $c = $this->cenario();
        [$id, $ra] = $this->encaminhada($c);

        $this->assertSame('EM_NEGOCIACAO', $this->statusVisto($c, 'ua', $id));
        $this->como($c['ua'])->postJson("/api/cotacoes/{$id}/respostas/{$ra}/responder", ['valor_tonelada' => '100', 'prazo_dias' => 2])->assertOk();

        $this->assertSame('RESPONDIDA', $this->statusVisto($c, 'ua', $id));
        $this->assertSame('EM_NEGOCIACAO', $this->statusVisto($c, 'uc', $id));
        $this->assertSame('EM_NEGOCIACAO', $this->statusVisto($c, 'admin', $id));
    }

    public function test_declinou_e_encerrado_para_ela(): void
    {
        $c = $this->cenario();
        [$id, , $rb] = $this->encaminhada($c);

        $this->como($c['ub'])->postJson("/api/cotacoes/{$id}/respostas/{$rb}/declinar")->assertOk();

        $this->assertSame('ENCERRADA', $this->statusVisto($c, 'ub', $id));
        $this->assertSame('EM_NEGOCIACAO', $this->statusVisto($c, 'admin', $id));
    }

    public function test_fechada_vencedora_programado_e_demais_encerrado(): void
    {
        $c = $this->cenario();
        [$id, $ra, $rb] = $this->encaminhada($c);
        $this->como($c['ua'])->postJson("/api/cotacoes/{$id}/respostas/{$ra}/responder", ['valor_tonelada' => '100', 'prazo_dias' => 2])->assertOk();
        $this->como($c['ub'])->postJson("/api/cotacoes/{$id}/respostas/{$rb}/responder", ['valor_tonelada' => '90', 'prazo_dias' => 1])->assertOk();

        $this->como($c['admin'])->postJson("/api/cotacoes/{$id}/fechar", ['transportadora_id' => $c['tb']->id, 'valor_tonelada' => '90', 'prazo_dias' => 1])->assertOk();

        $this->assertSame('PROGRAMADA', $this->statusVisto($c, 'ub', $id));
        $this->assertSame('ENCERRADA', $this->statusVisto($c, 'ua', $id));
        $this->assertSame('ENCERRADA', $this->statusVisto($c, 'uc', $id));
        $this->assertNotContains($this->statusVisto($c, 'admin', $id), ['EM_NEGOCIACAO']);
    }

    public function test_encerrada_sem_fechamento_e_cancelada_sao_encerrado(): void
    {
        $c = $this->cenario();
        [$id, $ra] = $this->encaminhada($c);
        $this->como($c['ua'])->postJson("/api/cotacoes/{$id}/respostas/{$ra}/responder", ['valor_tonelada' => '100', 'prazo_dias' => 2])->assertOk();
        $this->como($c['admin'])->postJson("/api/cotacoes/{$id}/encerrar")->assertOk();

        $this->assertSame('ENCERRADA', $this->statusVisto($c, 'ua', $id));
        $this->assertSame('ENCERRADA', $this->statusVisto($c, 'uc', $id));

        [$id2] = $this->encaminhada($c);
        $this->como($c['admin'])->postJson("/api/cotacoes/{$id2}/cancelar", ['motivo' => 'teste'])->assertOk();
        $this->assertSame('ENCERRADA', $this->statusVisto($c, 'ua', $id2));
    }

    public function test_lista_e_filtro_seguem_o_status_derivado(): void
    {
        $c = $this->cenario();
        [$id, $ra, $rb] = $this->encaminhada($c);
        [$aberta] = $this->encaminhada($c);
        $this->como($c['ua'])->postJson("/api/cotacoes/{$id}/respostas/{$ra}/responder", ['valor_tonelada' => '100', 'prazo_dias' => 2])->assertOk();
        $this->como($c['ub'])->postJson("/api/cotacoes/{$id}/respostas/{$rb}/responder", ['valor_tonelada' => '90', 'prazo_dias' => 1])->assertOk();
        $this->como($c['admin'])->postJson("/api/cotacoes/{$id}/fechar", ['transportadora_id' => $c['tb']->id, 'valor_tonelada' => '90', 'prazo_dias' => 1])->assertOk();

        $todas = $this->como($c['ua'])->getJson('/api/cotacoes')->assertOk();
        $this->assertEqualsCanonicalizing(['ENCERRADA', 'EM_NEGOCIACAO'], array_column($todas['data'], 'status'));
        $this->assertStringNotContainsString('"FECHADA"', json_encode(array_column($todas['data'], 'status')));

        $this->como($c['ua'])->getJson('/api/cotacoes?status=ENCERRADA')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
        $this->como($c['ua'])->getJson('/api/cotacoes?status=FECHADA')->assertOk()->assertJsonPath('meta.total', 0);
        $this->como($c['ub'])->getJson('/api/cotacoes?status=PROGRAMADA')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
        $this->assertNotEmpty($aberta);
    }
}
