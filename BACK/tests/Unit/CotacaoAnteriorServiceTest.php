<?php

namespace Tests\Unit;

use App\Enums\Operacao;
use App\Enums\StatusCotacao;
use App\Enums\StatusNegociacao;
use App\Enums\StatusResposta;
use App\Models\Cotacao;
use App\Models\Produto;
use App\Models\Transportadora;
use App\Models\Usuario;
use App\Services\CotacaoAnteriorService;
use Carbon\Carbon;
use Tests\TestCase;

/** v1.1 item 5: busca da cotação original para a cotação repetida. */
class CotacaoAnteriorServiceTest extends TestCase
{
    private CotacaoAnteriorService $svc;

    private Usuario $vendedor;

    private Produto $produto;

    private Transportadora $transp;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new CotacaoAnteriorService;
        $this->vendedor = $this->usuario('marcos', 'VENDEDOR');
        $this->produto = $this->produto('SODA', ['BOMBONA', 'GRANEL_LIQUIDO', 'GRANEL_SOLIDO']);
        $this->transp = $this->transportadora('TRANSLOG');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Cria cotação direto no banco; $resposta = status da resposta da TRANSLOG (null = sem resposta). */
    private function cotacao(array $o = []): Cotacao
    {
        $o += [
            'data' => now()->toDateString(), 'status' => StatusCotacao::EmNegociacao, 'veiculo' => 'CARRETA',
            'origem' => ['PAULÍNIA', 'SP'], 'destino' => ['CAMPINAS', 'SP'],
            'embalagens' => ['GRANEL_LIQUIDO'], 'produtos' => null, 'resposta' => StatusResposta::Respondida, 'origem_id' => null, 'validade' => null,
        ];
        $c = Cotacao::create([
            'codigo' => 'COT-'.str_pad((string) ++$this->n, 6, '0', STR_PAD_LEFT),
            'data_solicitacao' => $o['data'],
            'vendedor_id' => $this->vendedor->id,
            'operacao' => Operacao::Carregamento,
            'cliente_id' => $this->cliente()->id,
            'tipo_veiculo_codigo' => $o['veiculo'],
            'origem_cidade' => $o['origem'][0], 'origem_uf' => $o['origem'][1],
            'destino_cidade' => $o['destino'][0], 'destino_uf' => $o['destino'][1],
            'status' => $o['status'],
            'cotacao_origem_id' => $o['origem_id'],
        ]);
        foreach ($o['embalagens'] as $i => $emb) {
            $c->itens()->create(['sequencia' => $i + 1, 'produto_id' => ($o['produtos'][$i] ?? $this->produto)->id, 'embalagem_codigo' => $emb, 'quantidade_kg' => '1000', 'volumes' => null]);
        }
        $neg = $c->negociacao()->create(['status' => StatusNegociacao::Aberta]);
        if ($o['resposta'] !== null) {
            $dados = ['transportadora_id' => $this->transp->id, 'status' => $o['resposta'], 'encaminhado_em' => now(), 'encaminhado_por_id' => $this->vendedor->id];
            if ($o['resposta'] === StatusResposta::Respondida) {
                $dados += ['valor_tonelada' => '90', 'valor_frete' => '90', 'prazo_dias' => 2, 'validade' => $o['validade'], 'respondido_em' => now(), 'respondido_por_id' => $this->vendedor->id];
            }
            $neg->respostas()->create($dados);
        }

        return $c;
    }

    /** @param  array<Produto>|null  $produtos  produtos da nova cotação (padrão: o produto padrão) */
    private function buscar(string $oc = 'Paulinia', string $ouf = 'SP', string $dc = 'Campinas', string $duf = 'SP', string $veiculo = 'CARRETA', ?array $produtos = null): ?Cotacao
    {
        $ids = array_map(fn (Produto $p) => $p->id, $produtos ?? [$this->produto]);

        return $this->svc->buscar($veiculo, $oc, $ouf, $dc, $duf, $ids);
    }

    public function test_normalizar_cidade_remove_acento_caixa_e_espacos(): void
    {
        $this->assertSame('SAO JOSE DOS CAMPOS', CotacaoAnteriorService::normalizarCidade('  são   José  dos Campos '));
        $this->assertSame('PAULINIA', CotacaoAnteriorService::normalizarCidade('Paulínia'));
        $this->assertSame('PAULINIA', CotacaoAnteriorService::normalizarCidade('PAULINIA'));
        $this->assertSame('', CotacaoAnteriorService::normalizarCidade('   '));
    }

    public function test_granel_liquido_exige_todos_os_itens_e_ao_menos_um(): void
    {
        $this->assertTrue(CotacaoAnteriorService::granelLiquido($this->cotacao(['embalagens' => ['GRANEL_LIQUIDO', 'GRANEL_LIQUIDO']])));
        $this->assertFalse(CotacaoAnteriorService::granelLiquido($this->cotacao(['embalagens' => ['GRANEL_LIQUIDO', 'BOMBONA']])));
        $this->assertFalse(CotacaoAnteriorService::granelLiquido($this->cotacao(['embalagens' => ['GRANEL_SOLIDO']])));
        $this->assertFalse(CotacaoAnteriorService::granelLiquido($this->cotacao(['embalagens' => []])));
    }

    public function test_pode_so_para_usuario_interno(): void
    {
        $this->assertTrue(CotacaoAnteriorService::pode($this->vendedor));
        $this->assertFalse(CotacaoAnteriorService::pode($this->usuario('t1', 'TRANSPORTADORA', $this->transp)));
        $this->assertFalse(CotacaoAnteriorService::pode(null));
    }

    public function test_acha_por_rota_ignorando_acento_caixa_e_uf_minuscula(): void
    {
        $c = $this->cotacao();

        $this->assertSame($c->id, $this->buscar('paulinia', 'sp', 'CAMPINAS', 'sp')?->id);
        $this->assertSame($c->id, $this->buscar('PAULÍNIA', 'SP', 'Campinas', 'SP')?->id);
    }

    public function test_rota_ou_veiculo_diferente_nao_casa(): void
    {
        $this->cotacao();

        $this->assertNull($this->buscar(oc: 'Santos'));
        $this->assertNull($this->buscar(dc: 'Sorocaba'));
        $this->assertNull($this->buscar(ouf: 'RJ'));
        $this->assertNull($this->buscar(duf: 'MG'));
        $this->assertNull($this->buscar(veiculo: 'TRUCK'));
        $this->assertNull($this->buscar('Campinas', 'SP', 'Paulinia', 'SP'));
    }

    public function test_pega_a_mais_recente(): void
    {
        $this->cotacao(['data' => now()->subDays(5)->toDateString()]);
        $nova = $this->cotacao(['data' => now()->subDays(2)->toDateString()]);

        $this->assertSame($nova->id, $this->buscar()->id);
    }

    public function test_janela_de_dias_inclui_o_limite_e_exclui_alem(): void
    {
        $dias = (int) config('frete.cotacao_repetida_dias');
        $limite = $this->cotacao(['data' => now()->subDays($dias)->toDateString()]);

        $this->assertSame($limite->id, $this->buscar()->id);

        $limite->forceFill(['data_solicitacao' => now()->subDays($dias + 1)->toDateString()])->saveQuietly();
        $this->assertNull($this->buscar());
    }

    public function test_janela_usa_o_fuso_de_sao_paulo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-05 02:00:00', 'UTC'));
        $dentro = $this->cotacao(['data' => '2026-09-05']);
        $this->assertSame($dentro->id, $this->buscar()->id);

        $dentro->forceFill(['data_solicitacao' => '2026-09-04'])->saveQuietly();
        $this->assertNull($this->buscar());
    }

    public function test_sem_validade_reaproveita_por_60_dias(): void
    {
        $this->assertSame(60, (int) config('frete.cotacao_repetida_dias'));
    }

    public function test_resposta_com_validade_vale_ate_a_validade_mesmo_fora_da_janela(): void
    {
        $c = $this->cotacao(['data' => now()->subDays(100)->toDateString(), 'validade' => now()->addDays(5)->toDateString()]);

        $this->assertSame($c->id, $this->buscar()->id);

        $c->negociacao->respostas()->update(['validade' => now()->toDateString()]);
        $this->assertSame($c->id, $this->buscar()->id);
        $c->negociacao->respostas()->update(['validade' => now()->subDay()->toDateString()]);
        $this->assertNull($this->buscar());
    }

    public function test_resposta_vencida_nao_vale_mesmo_dentro_da_janela(): void
    {
        $this->cotacao(['data' => now()->subDays(2)->toDateString(), 'validade' => now()->subDay()->toDateString()]);

        $this->assertNull($this->buscar());
    }

    public function test_exige_resposta_respondida(): void
    {
        $this->cotacao(['resposta' => null]);
        $this->cotacao(['resposta' => StatusResposta::Aguardando]);
        $this->cotacao(['resposta' => StatusResposta::Declinada]);

        $this->assertNull($this->buscar());

        $ok = $this->cotacao(['data' => now()->subDays(10)->toDateString()]);
        $this->assertSame($ok->id, $this->buscar()->id);
    }

    public function test_ignora_cancelada_e_cai_para_a_proxima(): void
    {
        $antiga = $this->cotacao(['data' => now()->subDays(8)->toDateString()]);
        $this->cotacao(['status' => StatusCotacao::Cancelada]);

        $this->assertSame($antiga->id, $this->buscar()->id);
    }

    public function test_exige_todos_os_itens_granel_liquido(): void
    {
        $this->cotacao(['embalagens' => ['GRANEL_LIQUIDO', 'BOMBONA']]);
        $this->cotacao(['embalagens' => ['GRANEL_SOLIDO']]);

        $this->assertNull($this->buscar());
    }

    public function test_resolve_ate_a_original_sem_encadear(): void
    {
        $original = $this->cotacao(['data' => now()->subDays(6)->toDateString()]);
        $vinculada = $this->cotacao(['data' => now()->subDays(2)->toDateString(), 'origem_id' => $original->id, 'resposta' => null]);

        $achada = $this->buscar();

        $this->assertSame($original->id, $achada->id);
        $this->assertNull($achada->cotacao_origem_id);
        $this->assertNotSame($vinculada->id, $achada->id);
    }

    public function test_vinculada_recente_com_original_fora_da_janela_nao_vale(): void
    {
        $dias = (int) config('frete.cotacao_repetida_dias');
        $original = $this->cotacao(['data' => now()->subDays($dias + 5)->toDateString()]);
        $this->cotacao(['data' => now()->subDay()->toDateString(), 'origem_id' => $original->id, 'resposta' => null]);

        $this->assertNull($this->buscar());
    }

    public function test_vinculada_cuja_original_foi_cancelada_nao_vale_e_segue_para_a_proxima(): void
    {
        $outra = $this->cotacao(['data' => now()->subDays(20)->toDateString()]);
        $original = $this->cotacao(['data' => now()->subDays(10)->toDateString()]);
        $this->cotacao(['data' => now()->subDay()->toDateString(), 'origem_id' => $original->id, 'resposta' => null]);
        $original->forceFill(['status' => StatusCotacao::Cancelada])->saveQuietly();

        $this->assertSame($outra->id, $this->buscar()->id);
    }

    public function test_vinculada_sem_resposta_viva_na_original_nao_vale(): void
    {
        $original = $this->cotacao(['data' => now()->subDays(4)->toDateString()]);
        $this->cotacao(['data' => now()->subDay()->toDateString(), 'origem_id' => $original->id, 'resposta' => null]);
        $original->negociacao->respostas()->first()->forceFill(['status' => StatusResposta::Declinada])->saveQuietly();

        $this->assertNull($this->buscar());
    }

    public function test_produto_de_outra_descricao_nao_casa(): void
    {
        $this->cotacao();
        $outro = $this->produto('ACIDO', ['GRANEL_LIQUIDO']);

        $this->assertNull($this->buscar(produtos: [$outro]));
    }

    public function test_mesma_descricao_em_produtos_distintos_casa_ignorando_acento_caixa_e_espacos(): void
    {
        $this->produto->update(['descricao' => 'Soda Cáustica']);
        $c = $this->cotacao();
        $gemeo = $this->produto('OUTRO', ['GRANEL_LIQUIDO']);
        $gemeo->update(['descricao' => '  SODA   CAUSTICA ']);

        $this->assertSame($c->id, $this->buscar(produtos: [$gemeo])?->id);
    }

    public function test_descricao_vazia_usa_o_nome(): void
    {
        $c = $this->cotacao();
        $mesmoNome = $this->produto('soda', ['GRANEL_LIQUIDO']);
        $nomeDiferente = $this->produto('ACIDO', ['GRANEL_LIQUIDO']);
        $mesmoNome->update(['descricao' => 'OUTRA COISA']);

        $this->assertNull($this->buscar(produtos: [$mesmoNome]));
        $mesmoNome->update(['descricao' => '']);
        $this->assertSame($c->id, $this->buscar(produtos: [$mesmoNome])?->id);
        $this->assertNull($this->buscar(produtos: [$nomeDiferente]));
    }

    public function test_varios_itens_exigem_o_mesmo_conjunto_de_descricoes(): void
    {
        $acido = $this->produto('ACIDO', ['GRANEL_LIQUIDO']);
        $c = $this->cotacao(['embalagens' => ['GRANEL_LIQUIDO', 'GRANEL_LIQUIDO'], 'produtos' => [$this->produto, $acido]]);

        $this->assertSame($c->id, $this->buscar(produtos: [$acido, $this->produto])?->id);
        $this->assertSame($c->id, $this->buscar(produtos: [$this->produto, $acido, $acido])?->id);
        $this->assertNull($this->buscar(produtos: [$this->produto]));
        $this->assertNull($this->buscar(produtos: [$this->produto, $acido, $this->produto('BASE', ['GRANEL_LIQUIDO'])]));
        $this->assertNull($this->buscar(produtos: []));
    }

    public function test_compara_com_o_produto_da_original_e_nao_da_vinculada(): void
    {
        $acido = $this->produto('ACIDO', ['GRANEL_LIQUIDO']);
        $original = $this->cotacao(['data' => now()->subDays(6)->toDateString()]);
        $this->cotacao(['data' => now()->subDay()->toDateString(), 'origem_id' => $original->id, 'resposta' => null, 'produtos' => [$acido]]);

        $this->assertSame($original->id, $this->buscar()?->id);
        $this->assertNull($this->buscar(produtos: [$acido]));
    }
}
