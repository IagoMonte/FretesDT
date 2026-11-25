<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Embalagem;
use App\Models\Produto;
use App\Models\Transportadora;
use App\Services\Erp\SincronizaService;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SincronizaErpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.erp.url' => 'http://ponte.test', 'services.erp.chave' => 'k']);
    }

    /** Troca a ponte falsa (um Http::fake novo não substitui os anteriores). */
    private function ponte(array $respostas): void
    {
        Http::swap(new Factory);
        Http::fake($respostas);
    }

    private function pagina(array $itens, int $pagina = 0, int $paginas = 1): array
    {
        return ['itens' => $itens, 'pagina' => $pagina, 'tamanho' => 200, 'totalItens' => count($itens), 'totalPaginas' => $paginas];
    }

    private function transp(int $cod, string $nome, mixed $cnpj, string $sit = 'A'): array
    {
        return ['codigo' => $cod, 'razao_social' => $nome, 'nome_fantasia' => ' ', 'cnpj' => $cnpj, 'cidade' => 'SAO PAULO', 'uf' => 'SP', 'telefone' => '(11)4000-7100', 'email' => 'A@B.COM', 'situacao' => $sit];
    }

    /** @return array<int, Request> */
    private function chamadas(string $rota): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), "api/v1/{$rota}"))->map(fn ($par) => $par[0])->values()->all();
    }

    private function sync(string $entidade, bool $completa = false, array $registro = []): array
    {
        DB::table('sincronizacao')->where('entidade', $entidade)->update(['executando_desde' => null]);

        return app(SincronizaService::class)->executar($entidade, 'SOB_DEMANDA', null, $completa, $registro);
    }

    public function test_completa_cria_normaliza_e_cnpj_zerado_ou_repetido_entra_em_branco_para_revisao(): void
    {
        $this->ponte([
            '*api/v1/status' => Http::response([['entidade' => 'transportadoras', 'modo' => 'COMPLETA', 'status' => 'SUCESSO', 'ultimoSucessoEm' => now()->toIso8601String()]]),
            '*api/v1/transportadoras*' => Http::response($this->pagina([
                $this->transp(1, 'ALFA LTDA', 99887766000105),
                $this->transp(2, 'SEM CNPJ', 0),
                $this->transp(3, 'DUPLICADA', 99887766000105),
                $this->transp(4, 'INATIVA', 123456000149, 'I'),
            ])),
        ]);

        $r = $this->sync('TRANSPORTADORA');
        $this->assertSame('SUCESSO', $r['status']);
        $this->assertSame('COMPLETA', $r['totais']['modo']);
        $this->assertSame(4, $r['totais']['criados']);
        $this->assertSame(0, $r['totais']['pulados']);
        $this->assertSame(2, $r['totais']['avisos']);

        $t = Transportadora::where('codigo_erp', '1')->first();
        $this->assertSame('ALFA LTDA', $t->nome_fantasia);
        $this->assertSame('a@b.com', $t->email);
        $this->assertSame('1140007100', $t->telefone);
        $this->assertSame('ERP', $t->origem_cadastro);
        $this->assertFalse((bool) $t->revisao_pendente);
        $this->assertFalse((bool) Transportadora::where('codigo_erp', '4')->value('ativo'));
        $this->assertSame('00123456000149', Transportadora::where('codigo_erp', '4')->value('cnpj'));

        foreach (['2', '3'] as $codigo) {
            $x = Transportadora::where('codigo_erp', $codigo)->first();
            $this->assertNull($x->cnpj, "código {$codigo}");
            $this->assertTrue((bool) $x->revisao_pendente, "código {$codigo}");
        }

        $r2 = $this->sync('TRANSPORTADORA', true);
        $this->assertSame(0, $r2['totais']['criados']);
        $this->assertSame(0, $r2['totais']['atualizados']);
        $this->assertSame(4, $r2['totais']['inalterados']);
        $this->assertSame(0, Transportadora::where('codigo_erp', '1')->value('versao'));
    }

    public function test_cnpj_preenchido_no_painel_nao_e_apagado_pelo_erp_sem_cnpj(): void
    {
        $this->ponte(['*api/*' => Http::response($this->pagina([$this->transp(2, 'SEM CNPJ', 0)]))]);
        $this->sync('TRANSPORTADORA');
        $t = Transportadora::where('codigo_erp', '2')->first();
        $this->assertTrue((bool) $t->revisao_pendente);

        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $this->como($admin)->patchJson("/api/transportadoras/{$t->id}", ['versao' => $t->versao, 'cnpj' => '11.222.333/0001-81'])
            ->assertOk()->assertJsonPath('data.revisao_pendente', false)->assertJsonPath('data.cnpj', '11222333000181');
        $this->getJson('/api/transportadoras?revisao_pendente=1')->assertOk()->assertJsonPath('meta.total', 0);

        $this->ponte(['*api/*' => Http::response($this->pagina([$this->transp(2, 'SEM CNPJ RENOMEADA', 0)]))]);
        $this->sync('TRANSPORTADORA', true);
        $t->refresh();
        $this->assertSame('SEM CNPJ RENOMEADA', $t->razao_social);
        $this->assertSame('11222333000181', $t->cnpj);
        $this->assertFalse((bool) $t->revisao_pendente);
    }

    public function test_varios_codigos_do_erp_viram_um_produto(): void
    {
        $p = fn (string $cod, string $nome, string $sit = 'A') => ['codigo' => $cod, 'descricao' => $nome, 'situacao' => $sit];
        $this->ponte(['*api/*' => Http::response($this->pagina([
            $p('100', 'HIDROXIDO DE SODIO SOLUCAO 49% PORTO ALFA'),
            $p('101', 'HIDROXIDO DE SODIO SOLUCAO 49% PORTO BETA'),
            $p('102', 'STK HIDROXIDO DE SODIO SOLUCAO 49%'),
            $p('103', 'HIDROXIDO DE SODIO SOLUCAO ESTOQUE - T'),
            $p('104', 'HIDROXIDO DE SODIO SOLIDO ESCAMAS 99% IMP. ESTOQUE'),
            $p('200', 'ACIDO SULFURICO 98% PORTO GAMA (CLIENTE EXEMPLO)'),
            $p('201', 'ACIDO SULFURICO 98% ESTOQUE', 'I'),
        ]))]);

        $r = $this->sync('PRODUTO');

        $this->assertSame('SUCESSO', $r['status']);
        $this->assertSame(3, $r['totais']['criados']);
        $this->assertSame(4, $r['totais']['vinculados']);
        $this->assertSame(3, Produto::count());
        $this->assertSame(7, DB::table('produto_erp')->count());
        $soda = Produto::where('nome', 'HIDROXIDO DE SODIO SOLUCAO 49%')->first();
        $this->assertNotNull($soda);
        $this->assertSame(0, Produto::where('ativo', true)->count());
        $soda->update(['ativo' => true]);
        Produto::where('nome', 'ACIDO SULFURICO 98%')->update(['ativo' => true]);
        $this->assertSame(4, DB::table('produto_erp')->where('produto_id', $soda->id)->count());
        $this->assertNotNull(Produto::where('nome', 'HIDROXIDO DE SODIO SOLIDO ESCAMAS 99%')->first());

        $soda->update(['densidade' => '1.520', 'onu' => '1824']);
        $r2 = $this->sync('PRODUTO', true);
        $this->assertSame(7, $r2['totais']['inalterados']);
        $this->assertSame(0, $r2['totais']['criados'] + $r2['totais']['vinculados'] + $r2['totais']['atualizados']);
        $this->assertSame('1824', $soda->refresh()->onu);

        $this->ponte(['*api/*' => Http::response($this->pagina([
            $p('100', 'HIDROXIDO DE SODIO SOLUCAO 49% PORTO ALFA'),
            $p('101', 'HIDROXIDO DE SODIO SOLUCAO 49% PORTO BETA'),
            $p('104', 'HIDROXIDO DE SODIO SOLIDO ESCAMAS 99% IMP. ESTOQUE'),
            $p('300', 'ACIDO NITRICO 53% ESTOQUE'),
            $p('301', 'UREIA 46% AGRICOLA', 'I'),
        ]))]);
        $r3 = $this->sync('PRODUTO', true);
        $this->assertFalse((bool) DB::table('produto_erp')->where('codigo_erp', '102')->value('ativo'));
        $this->assertFalse((bool) DB::table('produto_erp')->where('codigo_erp', '200')->value('ativo'));
        $this->assertTrue($soda->refresh()->ativo);
        $this->assertTrue((bool) Produto::where('nome', 'ACIDO SULFURICO 98%')->value('ativo'));
        $this->assertSame(0, $r3['totais']['inativados']);
        $this->assertSame(2, $r3['totais']['criados']);
        $this->assertTrue((bool) Produto::where('nome', 'ACIDO NITRICO 53%')->value('ativo'));
        $this->assertFalse((bool) Produto::where('nome', 'UREIA 46% AGRICOLA')->value('ativo'));
    }

    public function test_produtos_ja_importados_um_por_codigo_sao_reagrupados(): void
    {
        $agora = now()->format('Y-m-d H:i:s.v');
        foreach (['300' => 'HIDROXIDO DE SODIO SOLUCAO 49% PORTO ALFA', '301' => 'HIDROXIDO DE SODIO SOLUCAO 49% PORTO BETA'] as $cod => $nome) {
            $pr = Produto::create(['nome' => $nome, 'codigo_erp' => $cod, 'origem_cadastro' => 'ERP', 'ativo' => true]);
            DB::table('produto_erp')->insert(['codigo_erp' => $cod, 'produto_id' => $pr->id, 'descricao_erp' => $nome, 'ativo' => true, 'criado_em' => $agora, 'atualizado_em' => $agora]);
        }
        $this->ponte(['*api/*' => Http::response($this->pagina([
            ['codigo' => '300', 'descricao' => 'HIDROXIDO DE SODIO SOLUCAO 49% PORTO ALFA', 'situacao' => 'A'],
            ['codigo' => '301', 'descricao' => 'HIDROXIDO DE SODIO SOLUCAO 49% PORTO BETA', 'situacao' => 'A'],
        ]))]);

        $this->sync('PRODUTO', true);

        $unico = Produto::where('nome', 'HIDROXIDO DE SODIO SOLUCAO 49%')->first();
        $this->assertNotNull($unico);
        $this->assertSame(2, DB::table('produto_erp')->where('produto_id', $unico->id)->count());
        $sobrou = Produto::where('id', '<>', $unico->id)->first();
        $this->assertSame(0, DB::table('produto_erp')->where('produto_id', $sobrou->id)->count());
        $this->assertTrue($sobrou->ativo);
        $this->assertTrue($unico->ativo);
    }

    public function test_cadastro_manual_e_vinculado_pelo_cnpj_sem_trocar_o_id(): void
    {
        $c = $this->cliente('Usina Exemplo (manual)');
        $c->update(['cnpj' => '00200001000180']);
        $this->ponte(['*api/*' => Http::response($this->pagina([
            ['codigo' => 1, 'razao_social' => 'USINA EXEMPLO S/A', 'nome_fantasia' => 'USINA EXEMPLO', 'cnpj' => 200001000180, 'cidade' => 'VALPARAISO', 'uf' => 'SP', 'situacao' => 'A'],
        ]))]);

        $r = $this->sync('CLIENTE');

        $this->assertSame(1, $r['totais']['vinculados']);
        $this->assertSame(0, $r['totais']['criados']);
        $c->refresh();
        $this->assertSame('1', $c->codigo_erp);
        $this->assertSame('ERP', $c->origem_cadastro);
        $this->assertSame('USINA EXEMPLO S/A', $c->razao_social);
        $this->assertSame(1, Cliente::count());
    }

    public function test_incremental_pede_alterado_desde_e_so_a_completa_inativa(): void
    {
        $itens = [$this->transp(1, 'A', 11222333000181), $this->transp(2, 'B', 99887766000105)];
        $this->ponte(['*api/*' => Http::response($this->pagina($itens))]);
        $this->sync('TRANSPORTADORA');
        $this->assertTrue((bool) DB::table('sincronizacao')->where('entidade', 'TRANSPORTADORA')->value('marca_dagua'));

        $this->ponte(['*api/*' => Http::response($this->pagina([$itens[0]]))]);
        $r = $this->sync('TRANSPORTADORA');

        $this->assertSame('INCREMENTAL', $r['totais']['modo']);
        $this->assertSame(0, $r['totais']['inativados']);
        $this->assertTrue((bool) Transportadora::where('codigo_erp', '2')->value('ativo'));
        $ultima = collect($this->chamadas('transportadoras'))->last();
        $this->assertMatchesRegularExpression('/alteradoDesde=\d{4}-\d{2}-\d{2}T00%3A00%3A00|alteradoDesde=\d{4}-\d{2}-\d{2}T00:00:00/', $ultima->url());

        $r = $this->sync('TRANSPORTADORA', true);
        $this->assertSame('COMPLETA', $r['totais']['modo']);
        $this->assertSame(1, $r['totais']['inativados']);
        $this->assertFalse((bool) Transportadora::where('codigo_erp', '2')->value('ativo'));
    }

    public function test_falha_no_meio_da_leitura_nao_inativa_nada(): void
    {
        $this->ponte(['*api/*' => Http::response($this->pagina([$this->transp(1, 'A', 11222333000181)]))]);
        $this->sync('TRANSPORTADORA');

        $this->ponte(['*api/*' => Http::sequence()
            ->push($this->pagina([], 0, 2))
            ->push(['erro' => 'x'], 500)
            ->push(['erro' => 'x'], 500)
            ->push(['erro' => 'x'], 500)]);
        $r = $this->sync('TRANSPORTADORA', true);

        $this->assertSame('ERRO', $r['status']);
        $this->assertTrue((bool) Transportadora::where('codigo_erp', '1')->value('ativo'));
        $this->assertSame('ERRO', DB::table('sincronizacao')->where('entidade', 'TRANSPORTADORA')->value('ultima_execucao_status'));
    }

    public function test_embalagem_casa_pelo_nome_e_cria_nova_para_revisar(): void
    {
        $this->ponte(['*api/*' => Http::response($this->pagina([
            ['codigo' => 4, 'descricao' => 'CONTAINER(S)'],
            ['codigo' => 8, 'descricao' => 'GRANEL'],
            ['codigo' => 16, 'descricao' => 'BIG-BAG'],
        ]))]);

        $r = $this->sync('EMBALAGEM');

        $this->assertSame('SUCESSO', $r['status']);
        $this->assertSame(1, $r['totais']['vinculados']);
        $this->assertSame('4', Embalagem::find('CONTAINER')->codigo_erp);
        $this->assertFalse(Embalagem::find('CONTAINER')->revisao_pendente);
        $granel = Embalagem::find('GRANEL');
        $this->assertTrue($granel->granel);
        $this->assertTrue($granel->revisao_pendente);
        $this->assertFalse($granel->permite_paletizar);
        $this->assertTrue(Embalagem::find('BIG_BAG')->revisao_pendente);
        $this->assertNull($this->chamadas('embalagens')[0]->data()['alteradoDesde'] ?? null);
    }

    public function test_produto_novo_entra_inativo_e_campos_nossos_nao_sao_sobrescritos(): void
    {
        $this->ponte(['*api/*' => Http::response($this->pagina([['codigo' => '0000007', 'descricao' => 'SODA CAUSTICA', 'situacao' => 'A']]))]);
        $this->sync('PRODUTO');
        $p = Produto::where('codigo_erp', '0000007')->first();
        $this->assertFalse($p->ativo);
        $p->update(['densidade' => '1.530', 'onu' => '1824', 'ativo' => true]);

        $this->ponte(['*api/*' => Http::response($this->pagina([['codigo' => '0000007', 'descricao' => 'SODA CÁUSTICA 50%', 'situacao' => 'A']]))]);
        $this->sync('PRODUTO', true);

        $p->refresh();
        $this->assertSame('SODA CÁUSTICA 50%', $p->nome);
        $this->assertSame('1.530', (string) $p->densidade);
        $this->assertSame('1824', $p->onu);
        $this->assertTrue($p->ativo);
    }

    public function test_registro_unico_filtra_por_cnpj_ou_codigo_na_ponte(): void
    {
        $this->ponte(['*api/*' => Http::response($this->pagina([$this->transp(9, 'NOVA', 99887766000105)]))]);

        $r = $this->sync('TRANSPORTADORA', false, ['cnpj' => '99887766000105']);

        $this->assertSame('REGISTRO', $r['totais']['modo']);
        $this->assertSame(1, $r['totais']['criados']);
        $this->assertStringContainsString('cnpj=99887766000105', $this->chamadas('transportadoras')[0]->url());
        $this->assertNull(DB::table('sincronizacao')->where('entidade', 'TRANSPORTADORA')->value('marca_dagua'));
    }

    public function test_trava_impede_duas_execucoes_ao_mesmo_tempo(): void
    {
        $s = app(SincronizaService::class);
        $this->assertTrue($s->reservar('CLIENTE', 'AGENDADA'));
        $this->assertFalse($s->reservar('CLIENTE', 'SOB_DEMANDA'));
        $this->assertNull($s->executar('CLIENTE', 'SOB_DEMANDA'));
    }

    public function test_endpoints_do_painel(): void
    {
        $this->ponte(['*api/*' => Http::response($this->pagina([['codigo' => 8, 'descricao' => 'GRANEL']]))]);
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $vendedor = $this->usuario('marcos', 'VENDEDOR');

        $this->como($vendedor)->postJson('/api/sincronizacao/EMBALAGEM/executar')->assertForbidden();

        $this->como($admin)->postJson('/api/sincronizacao/EMBALAGEM/executar')->assertStatus(202)->assertJsonPath('data.situacao', 'EM_EXECUCAO');
        $this->getJson('/api/sincronizacao')->assertOk()->assertJsonFragment(['entidade' => 'EMBALAGEM', 'executando' => false, 'ultima_execucao_status' => 'SUCESSO']);
        $this->getJson('/api/sincronizacao/pendencias')->assertOk()->assertJsonPath('data.embalagens_a_revisar', 1);
        $this->getJson('/api/painel')->assertOk()->assertJsonPath('data.pendencias_erp.embalagens_a_revisar', 1);
        $this->getJson('/api/embalagens?revisao_pendente=1')->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson('/api/sincronizacao/INEXISTENTE/executar')->assertNotFound();
        $this->postJson('/api/sincronizacao/PRODUTO/registro', ['cnpj' => '123'])->assertStatus(422);

        $this->patchJson('/api/embalagens/GRANEL', ['versao' => 0, 'descricao' => 'Granel'])->assertOk()->assertJsonPath('data.revisao_pendente', false);
    }
}
