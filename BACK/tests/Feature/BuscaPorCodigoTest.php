<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** F6: o link `?abrir=<código>` resolve o registro por `GET ...?codigo=` (exato), respeitando o escopo de cada perfil. */
class BuscaPorCodigoTest extends TestCase
{
    private $admin;

    private $marcos;

    private $lucia;

    private string $codigo;

    private string $cotacaoId;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->admin = $this->usuario('admin', 'ADMINISTRADOR');
        $this->marcos = $this->usuario('marcos', 'VENDEDOR');
        $this->lucia = $this->usuario('lucia', 'VENDEDOR');
        $ta = $this->transportadora('TRANSLOG');
        $this->usuario('translog', 'TRANSPORTADORA', $ta);
        $this->usuario('rotasul', 'TRANSPORTADORA', $this->transportadora('ROTA SUL'));
        $r = $this->como($this->marcos)->postJson('/api/cotacoes', $this->corpoCotacao($this->cliente(), $this->produto()))->assertCreated();
        $this->codigo = $r['data']['codigo'];
        $this->cotacaoId = $r['data']['id'];
        $this->como($this->admin)->postJson("/api/cotacoes/{$this->cotacaoId}/encaminhar", ['transportadora_ids' => [$ta->id]])->assertOk();
    }

    public function test_dono_e_admin_encontram_a_cotacao_pelo_codigo_exato(): void
    {
        foreach ([$this->marcos, $this->admin] as $u) {
            $this->como($u)->getJson('/api/cotacoes?codigo='.$this->codigo)->assertOk()
                ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->cotacaoId);
        }
    }

    public function test_codigo_e_exato_e_nao_parcial(): void
    {
        $this->como($this->admin)->getJson('/api/cotacoes?codigo='.substr($this->codigo, 0, 6))->assertOk()->assertJsonCount(0, 'data');
        $this->como($this->admin)->getJson('/api/cotacoes?codigo=COT-999999')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_quem_nao_tem_acesso_recebe_lista_vazia(): void
    {
        $this->como($this->lucia)->getJson('/api/cotacoes?codigo='.$this->codigo)->assertOk()->assertJsonCount(0, 'data');
        $via = Usuario::where('login', 'rotasul')->first();
        $this->como($via)->getJson('/api/cotacoes?codigo='.$this->codigo)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_transportadora_destinataria_encontra(): void
    {
        $translog = Usuario::where('login', 'translog')->first();
        $this->como($translog)->getJson('/api/cotacoes?codigo='.$this->codigo)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_programacao_por_codigo_inexistente_vem_vazia(): void
    {
        $this->como($this->admin)->getJson('/api/programacoes?codigo=CRG-999999')->assertOk()->assertJsonCount(0, 'data');
    }
}
