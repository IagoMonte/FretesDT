<?php

namespace Tests\Feature\Admin;

use App\Services\Admin\AuditoriaService;
use App\Support\Periodo;
use Illuminate\Support\Carbon;
use Tests\Support\CenarioAdmin;
use Tests\TestCase;

class AuditoriaServiceTest extends TestCase
{
    use CenarioAdmin;

    private AuditoriaService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $this->svc = app(AuditoriaService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_operacao_lista_do_mais_recente_para_o_mais_antigo_com_nome_do_usuario(): void
    {
        $pag = $this->svc->operacao([], 10);
        $this->assertSame(4, $pag->total());
        $this->assertSame('COTACAO_CRIADA', $pag->items()[0]['evento']);
        $this->assertSame('Lucia', $pag->items()[0]['usuario']);
        $this->assertSame('COT-000003', $pag->items()[0]['codigo']);
    }

    public function test_filtros_da_operacao(): void
    {
        $this->assertSame(1, $this->svc->operacao(['evento' => 'NEGOCIACAO_FECHADA'], 10)->total());
        $this->assertSame(2, $this->svc->operacao(['usuario_id' => $this->admin->id], 10)->total());
        $this->assertSame(1, $this->svc->operacao(['entidade' => 'PROGRAMACAO'], 10)->total());
        $this->assertSame(3, $this->svc->operacao(['q' => 'COT-000001'], 10)->total());
        $this->assertSame(1, $this->svc->operacao(['correlacao_id' => 'corr-1'], 10)->total());
        $this->assertSame(2, $this->svc->operacao(['de' => '2026-10-02', 'ate' => '2026-10-03'], 10)->total());
        $this->assertSame(0, $this->svc->operacao(['evento' => 'NAO_EXISTE'], 10)->total());
    }

    public function test_paginacao(): void
    {
        $p1 = $this->svc->operacao([], 3);
        $this->assertCount(3, $p1->items());
        $this->assertSame(2, $p1->lastPage());
        $this->assertCount(1, $this->svc->operacao(['pagina' => 2], 3)->items());
    }

    public function test_sistema_filtra_por_nivel_categoria_e_texto(): void
    {
        $this->assertSame(3, $this->svc->sistema([], 10)->total());
        $this->assertSame(1, $this->svc->sistema(['nivel' => 'ERROR'], 10)->total());
        $this->assertSame(1, $this->svc->sistema(['categoria' => 'AUTENTICACAO'], 10)->total());
        $this->assertSame(1, $this->svc->sistema(['q' => 'ERP'], 10)->total());
        $this->assertSame('intruso', $this->svc->sistema(['categoria' => 'AUTENTICACAO'], 10)->items()[0]['usuario']);
    }

    public function test_acessos(): void
    {
        $this->assertSame(3, $this->svc->acessos([], 10)->total());
        $this->assertSame(2, $this->svc->acessos(['usuario_id' => $this->marcos->id], 10)->total());
        $this->assertSame('Admin', $this->svc->acessos([], 10)->items()[0]['usuario']);
    }

    public function test_resumo_de_atividade(): void
    {
        $r = $this->svc->resumo(Periodo::entre('2026-10-01', '2026-10-31'));

        $this->assertSame(2, $this->linha($r['por_usuario'], 'usuario', 'Admin')['eventos']);
        $this->assertSame(1, $this->linha($r['por_evento'], 'evento', 'NEGOCIACAO_FECHADA')['total']);
        $this->assertSame(2, $this->linha($r['por_dia'], 'dia', '2026-10-01')['eventos']);
        $this->assertSame(2, $this->linha($r['acessos_por_usuario'], 'usuario', 'Marcos')['logins']);
        $this->assertSame(1, $r['falhas_login']);
        $this->assertSame(1, $r['erros']);
        $this->assertCount(7, $r['heatmap']);
        $this->assertCount(24, $r['heatmap'][0]);
        $this->assertSame(1, $r['heatmap'][3][6]);
    }

    public function test_linha_do_tempo_junta_operacao_e_sistema_pela_correlacao(): void
    {
        $t = $this->svc->linhaDoTempo('COTACAO', $this->cot[1]->id);

        $eventos = array_column($t, 'evento');
        $this->assertSame(['COTACAO_CRIADA', 'NEGOCIACAO_FECHADA', 'REGISTRO_ALTERADO', 'PROGRAMACAO_CRIADA'], $eventos);
        $this->assertSame('operacao', $t[0]['origem']);
        $this->assertSame('sistema', $t[2]['origem']);
        $this->assertSame('Marcos', $t[0]['usuario']);
    }
}
