<?php

namespace Tests;

use App\Models\Cliente;
use App\Models\Grupo;
use App\Models\Produto;
use App\Models\Transportadora;
use App\Models\Usuario;
use App\Services\JwtService;
use Database\Seeders\AcessoSeeder;
use Database\Seeders\DominiosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /** true nos testes que verificam a obrigatoriedade do pedido: aí nada é preenchido por baixo dos panos. */
    protected bool $semPedidoAutomatico = false;

    /**
     * O número do pedido é obrigatório no fechamento (`programacao.pedido_venda`) e na criação de carga (`pedido_venda`).
     * Os testes que não tratam disso recebem um pedido de fixture, para o corpo da requisição continuar válido.
     */
    public function postJson($uri, array $data = [], array $headers = [], $options = 0)
    {
        if (! $this->semPedidoAutomatico) {
            if (preg_match('#/api/cotacoes/[^/]+/fechar$#', (string) $uri) && empty($data['programacao']['pedido_venda'])) {
                $data['programacao'] = ($data['programacao'] ?? []) + ['pedido_venda' => 'PV-TESTE'];
                $data['programacao']['pedido_venda'] = $data['programacao']['pedido_venda'] ?: 'PV-TESTE';
            }
            if (rtrim((string) $uri, '/') === '/api/programacoes' && empty($data['pedido_venda'])) {
                $data['pedido_venda'] = 'PV-TESTE';
            }
        }

        return parent::postJson($uri, $data, $headers, $options);
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! is_file(base_path(config('frete.jwt.chave_privada')))) {
            Artisan::call('frete:jwt-keys');
        }
        $this->seed([DominiosSeeder::class, AcessoSeeder::class]);
        config(['frete.idempotencia_segundos' => 0]);
    }

    protected function usuario(string $login, string $grupo, ?Transportadora $transportadora = null, string $senha = 'senha1'): Usuario
    {
        $u = Usuario::create([
            'nome' => ucfirst($login), 'login' => $login, 'senha_hash' => Hash::make($senha),
            'transportadora_id' => $transportadora?->id, 'situacao' => 'ATIVO', 'token_versao' => 0,
        ]);
        DB::table('usuario_grupo')->insert(['usuario_id' => $u->id, 'grupo_id' => Grupo::where('codigo', $grupo)->value('id')]);

        return $u;
    }

    protected function transportadora(string $nome): Transportadora
    {
        static $n = 0;

        return Transportadora::create([
            'razao_social' => "{$nome} Ltda", 'nome_fantasia' => $nome,
            'cnpj' => str_pad((string) (++$n), 14, '0', STR_PAD_LEFT), 'ativo' => true,
            'email' => 'transportadora'.$n.'@teste.local', 'telefone' => '1999999'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
        ]);
    }

    protected function cliente(string $razao = 'Cliente Teste'): Cliente
    {
        static $n = 0;

        return Cliente::create([
            'razao_social' => $razao, 'cnpj' => str_pad((string) (++$n + 500), 14, '0', STR_PAD_LEFT),
            'origem_cadastro' => 'MANUAL', 'ativo' => true,
        ]);
    }

    protected function produto(string $nome = 'SODA', array $embalagens = ['BOMBONA', 'GRANEL_LIQUIDO']): Produto
    {
        $p = Produto::create(['nome' => $nome, 'origem_cadastro' => 'MANUAL', 'ativo' => true]);
        $p->embalagens()->sync($embalagens);

        return $p;
    }

    /** Autentica as próximas requisições como o usuário (token JWT real). */
    protected function como(Usuario $u): static
    {
        Auth::forgetGuards();

        return $this->withToken(app(JwtService::class)->emitir($u));
    }

    /** Corpo válido de cotação (1 item de 12 t, carreta). */
    protected function corpoCotacao(Cliente $c, Produto $p, array $extra = []): array
    {
        return $extra + [
            'operacao' => 'CARREGAMENTO',
            'parte' => ['tipo' => 'CLIENTE', 'id' => $c->id],
            'tipo_veiculo_codigo' => 'CARRETA',
            'origem' => ['cidade' => 'Paulínia', 'uf' => 'SP', 'ibge' => null],
            'destino' => ['cidade' => 'Campinas', 'uf' => 'SP'],
            'valor_nf' => '15000.00',
            'itens' => [['produto_id' => $p->id, 'embalagem_codigo' => 'BOMBONA', 'quantidade_kg' => '12000', 'volumes' => 10]],
        ];
    }
}