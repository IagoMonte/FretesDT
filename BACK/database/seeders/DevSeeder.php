<?php

namespace Database\Seeders;

use App\Enums\StatusProgramacao;
use App\Models\Cliente;
use App\Models\Cotacao;
use App\Models\Fornecedor;
use App\Models\Grupo;
use App\Models\Produto;
use App\Models\Transportadora;
use App\Models\Usuario;
use App\Services\CotacaoService;
use App\Services\ProgramacaoService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Dados equivalentes ao mock do front (FRONT/src/data): vendedores, transportadoras
 * (login = slug do nome; todos usam a senha de FRETE_DEV_SENHA), produtos, clientes, cotações e cargas.
 * Senhas apenas para desenvolvimento.
 */
class DevSeeder extends Seeder
{
    private const REPS = ['MARCOS', 'LUCIA', 'TIAGO', 'OTAVIO', 'FELIPE', 'NELSON', 'RAFAEL', 'HELENA', 'DENIS', 'CESAR'];

    private const TRANSPORTADORAS = ['ARCO', 'ROTA SUL', 'PLANALTO', 'TRANS NORTE', 'VX LOG', 'DELTA LOG', 'NOVA LOG', 'OMEGA TRANS', 'LINHA VERDE', 'VIA AZUL', 'SIGMA', 'MONTANA', 'TRX', 'ECO CARGO', 'ROTA 10', 'BRISA', 'TRANSPAC', 'CRX', 'KJ LOG', 'PAMPA', 'ALFA', 'LINKA', 'ORION'];

    /** nome => [densidade, embalagens] */
    private const PRODUTOS = [
        'HCL' => ['1.190', ['GRANEL_LIQUIDO', 'BOMBONA', 'CONTAINER']],
        'SODA' => ['1.530', ['GRANEL_LIQUIDO', 'BOMBONA', 'GRANEL_SOLIDO', 'SACARIA']],
        'SULFÚRICO' => ['1.840', ['GRANEL_LIQUIDO', 'CONTAINER']],
        'HIPO' => ['1.170', ['GRANEL_LIQUIDO', 'BOMBONA']],
        'FOSFÓRICO' => ['1.690', ['GRANEL_LIQUIDO', 'BOMBONA', 'CONTAINER']],
        'UREIA' => ['0.740', ['GRANEL_SOLIDO', 'SACARIA', 'CONTAINER']],
    ];

    /** razão social => [CNPJ, cidade, UF] */
    private const CLIENTES = [
        'Alfaquim Indústria Química' => ['12345678000190', 'Cubatão', 'SP'],
        'Beta Paulínia' => ['98765432000110', 'Paulínia', 'SP'],
        'Gama Brasil Fertilizantes' => ['11222333000144', 'Paranaguá', 'PR'],
        'Delta Betim' => ['22333444000155', 'Betim', 'MG'],
        'Campo Verde Insumos' => ['33444555000166', 'Dumont', 'SP'],
        'Maresia Química Ltda' => ['44555666000177', 'Recife', 'PE'],
        'Vale Fertilizantes' => ['55666777000188', 'Aracaju', 'SE'],
    ];

    public function run(): void
    {
        $grupos = Grupo::pluck('id', 'codigo');
        $admin = Usuario::where('login', mb_strtolower((string) env('FRETE_ADMIN_LOGIN')))->firstOrFail();

        $vendedores = [];
        foreach (self::REPS as $rep) {
            $vendedores[$rep] = $this->usuario(ucfirst(strtolower($rep)), strtolower($rep), $this->senhaDemo(), $grupos['VENDEDOR']);
        }

        $transportadoras = [];
        foreach (self::TRANSPORTADORAS as $i => $nome) {
            $t = Transportadora::firstOrCreate(['cnpj' => $this->cnpj(10000000 + $i + 1)], [
                'razao_social' => $nome.' Transportes Ltda', 'nome_fantasia' => $nome,
                'email' => 'comercial@'.$this->slug($nome).'.com.br', 'telefone' => '1140000'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'ativo' => true,
            ]);
            $transportadoras[$nome] = $t;
            $slug = $this->slug($nome);
            $this->usuario($nome, $slug, $this->senhaDemo(), $grupos['TRANSPORTADORA'], $t->id);
        }

        $produtos = [];
        foreach (self::PRODUTOS as $nome => [$densidade, $embalagens]) {
            $p = Produto::firstOrCreate(['nome' => $nome], ['densidade' => $densidade, 'origem_cadastro' => 'MANUAL', 'ativo' => true]);
            $p->embalagens()->sync($embalagens);
            $produtos[$nome] = $p;
        }

        $clientes = [];
        foreach (self::CLIENTES as $razao => [$cnpj, $cidade, $uf]) {
            $clientes[$razao] = Cliente::firstOrCreate(['cnpj' => $cnpj], ['razao_social' => $razao, 'nome_fantasia' => $razao, 'cidade' => mb_strtoupper($cidade), 'uf' => $uf, 'origem_cadastro' => 'MANUAL', 'ativo' => true]);
        }
        $fornecedor = Fornecedor::firstOrCreate(['cnpj' => '66777888000199'], ['razao_social' => 'Retorno de embalagens — RJ', 'nome_fantasia' => 'Retorno de embalagens', 'cidade' => 'RIO DE JANEIRO', 'uf' => 'RJ', 'origem_cadastro' => 'MANUAL', 'ativo' => true]);

        if (Cotacao::exists()) {
            return;
        }

        $cotacoes = app(CotacaoService::class);
        $cargas = app(ProgramacaoService::class);

        $local = fn (string $cidade, string $uf) => ['cidade' => $cidade, 'uf' => $uf, 'ibge' => null];
        $cotacao = fn (string $rep, string $cliente, string $prod, string $veiculo, string $emb, string $kg, ?int $vol, array $de, array $para, string $nf) => $cotacoes->criar($vendedores[$rep], [
            'operacao' => 'CARREGAMENTO',
            'parte' => ['tipo' => 'CLIENTE', 'id' => $clientes[$cliente]->id],
            'tipo_veiculo_codigo' => $veiculo, 'origem' => $de, 'destino' => $para, 'valor_nf' => $nf,
            'itens' => [['produto_id' => $produtos[$prod]->id, 'embalagem_codigo' => $emb, 'quantidade_kg' => $kg, 'volumes' => $vol]],
        ]);

        $cotacao('MARCOS', 'Alfaquim Indústria Química', 'HCL', 'TRUCK', 'BOMBONA', '14000', 560, $local('CUBATÃO', 'SP'), $local('CAMPINAS', 'SP'), '86000');

        $c2 = $cotacao('LUCIA', 'Beta Paulínia', 'SODA', 'CARRETA', 'GRANEL_LIQUIDO', '27000', null, $local('PAULÍNIA', 'SP'), $local('GOIÂNIA', 'GO'), '210000');
        $c2 = $cotacoes->encaminhar($c2, $admin, [$transportadoras['ROTA SUL']->id, $transportadoras['NOVA LOG']->id]);
        $viaSerra = $c2->negociacao->respostas->firstWhere('transportadora_id', $transportadoras['ROTA SUL']->id);
        $cotacoes->responder($c2, $viaSerra, $admin, ['valor_tonelada' => '285.00', 'prazo_dias' => 3, 'prazo_coleta_dias' => 1, 'observacao' => 'Saídas diárias do terminal de Paulínia.']);

        $c3 = $cotacao('TIAGO', 'Gama Brasil Fertilizantes', 'UREIA', 'BITREM', 'GRANEL_SOLIDO', '33000', null, $local('PARANAGUÁ', 'PR'), $local('RIO VERDE', 'GO'), '340000');
        $cotacoes->encaminhar($c3, $admin, [$transportadoras['ORION']->id]);

        $dia = fn (int $n) => Carbon::now(config('frete.fuso_exibicao'))->addDays($n)->toDateString();
        $carga = function (array $d, string $destinoStatus) use ($cargas, $vendedores, $transportadoras, $produtos, $clientes, $fornecedor, $admin) {
            $recebimento = ($d['operacao'] ?? 'CARREGAMENTO') === 'RECEBIMENTO';
            $p = $cargas->criar($admin, [
                'operacao' => $d['operacao'] ?? 'CARREGAMENTO',
                'parte' => $recebimento ? ['tipo' => 'FORNECEDOR', 'id' => $fornecedor->id] : ['tipo' => 'CLIENTE', 'id' => $clientes[$d['parte']]->id],
                'status' => $destinoStatus === 'EM_COTACAO' ? 'EM_COTACAO' : 'AGUARDANDO_CARREGAMENTO',
                'data_pedido' => $d['pedido'], 'pedido_venda' => $d['pv'], 'nfe_numero' => $d['nfe'] ?? null,
                'representante_id' => $vendedores[$d['rep']]->id,
                'deposito' => $d['deposito'], 'modalidade_frete' => $d['frete'],
                'transportadora_id' => isset($d['transp']) ? $transportadoras[$d['transp']]->id : null,
                'origem' => $d['origem'], 'destino' => $d['destino'],
                'valor_tonelada' => $d['vt'] ?? null,
                'data_coleta_prevista' => $d['coleta'] ?? null, 'data_entrega_prevista' => $d['entrega'] ?? null,
                'itens' => [['produto_id' => $produtos[$d['produto']]->id, 'embalagem_codigo' => $d['emb'], 'quantidade_kg' => $d['kg']]],
            ]);
            if (in_array($destinoStatus, ['EM_TRANSITO', 'ENTREGUE'], true)) {
                $p = $cargas->mudarStatus($p, StatusProgramacao::EmTransito, $d['coleta']);
            }
            if ($destinoStatus === 'ENTREGUE') {
                $p = $cargas->mudarStatus($p, StatusProgramacao::Entregue, $d['entregue']);
            }
        };

        $carga(['pedido' => $dia(-12), 'pv' => 'PV-1042', 'nfe' => '45882', 'rep' => 'MARCOS', 'parte' => 'Beta Paulínia', 'produto' => 'SODA', 'kg' => '27000', 'emb' => 'GRANEL_LIQUIDO', 'deposito' => 'FISICO', 'transp' => 'VX LOG', 'frete' => 'CIF', 'origem' => $local('SANTOS', 'SP'), 'destino' => $local('CAMPINAS', 'SP'), 'vt' => '240', 'coleta' => $dia(-9), 'entrega' => $dia(-6), 'entregue' => $dia(-6)], 'ENTREGUE');
        $carga(['pedido' => $dia(-4), 'pv' => 'PV-1051', 'nfe' => '45901', 'rep' => 'LUCIA', 'parte' => 'Delta Betim', 'produto' => 'HCL', 'kg' => '14000', 'emb' => 'BOMBONA', 'deposito' => 'FISICO', 'transp' => 'ARCO', 'frete' => 'FOB', 'origem' => $local('CUBATÃO', 'SP'), 'destino' => $local('BETIM', 'MG'), 'vt' => '320', 'coleta' => $dia(-1), 'entrega' => $dia(2)], 'EM_TRANSITO');
        $carga(['pedido' => $dia(-2), 'pv' => 'PV-1058', 'rep' => 'OTAVIO', 'parte' => 'Campo Verde Insumos', 'produto' => 'UREIA', 'kg' => '25000', 'emb' => 'SACARIA', 'deposito' => 'VIRTUAL', 'transp' => 'TRANS NORTE', 'frete' => 'CIF', 'origem' => $local('UBERABA', 'MG'), 'destino' => $local('DUMONT', 'SP'), 'vt' => '195', 'coleta' => $dia(1), 'entrega' => $dia(4)], 'AGUARDANDO_CARREGAMENTO');
        $carga(['pedido' => $dia(-15), 'pv' => 'PV-1036', 'nfe' => '45810', 'rep' => 'HELENA', 'parte' => 'Maresia Química Ltda', 'produto' => 'SULFÚRICO', 'kg' => '30000', 'emb' => 'GRANEL_LIQUIDO', 'deposito' => 'FISICO', 'transp' => 'ROTA SUL', 'frete' => 'CIF', 'origem' => $local('PAULÍNIA', 'SP'), 'destino' => $local('RECIFE', 'PE'), 'vt' => '395', 'coleta' => $dia(-11), 'entrega' => $dia(-5), 'entregue' => $dia(-3)], 'ENTREGUE');
        $carga(['pedido' => $dia(-1), 'pv' => 'PV-1060', 'rep' => 'CESAR', 'parte' => 'Vale Fertilizantes', 'produto' => 'FOSFÓRICO', 'kg' => '18000', 'emb' => 'CONTAINER', 'deposito' => 'VIRTUAL', 'frete' => 'CIF', 'origem' => $local('SALVADOR', 'BA'), 'destino' => $local('ARACAJU', 'SE')], 'EM_COTACAO');
        $carga(['pedido' => $dia(-3), 'operacao' => 'RECEBIMENTO', 'pv' => 'PV-1055', 'nfe' => '45897', 'rep' => 'DENIS', 'produto' => 'HIPO', 'kg' => '6000', 'emb' => 'BOMBONA', 'deposito' => 'VIRTUAL', 'transp' => 'VIA AZUL', 'frete' => 'FOB', 'origem' => $local('RIO DE JANEIRO', 'RJ'), 'destino' => $local('CUBATÃO', 'SP'), 'vt' => '180', 'coleta' => $dia(-1), 'entrega' => $dia(1)], 'EM_TRANSITO');
    }

    private function usuario(string $nome, string $login, string $senha, string $grupoId, ?string $transportadoraId = null): Usuario
    {
        $login = mb_strtolower($login);
        $u = Usuario::firstOrCreate(['login' => $login], [
            'nome' => $nome, 'senha_hash' => Hash::make($senha), 'transportadora_id' => $transportadoraId,
            'situacao' => 'ATIVO', 'liberado_em' => now(), 'token_versao' => 0,
        ]);
        DB::table('usuario_grupo')->insertOrIgnore(['usuario_id' => $u->id, 'grupo_id' => $grupoId]);

        return $u;
    }

    private function senhaDemo(): string
    {
        $senha = (string) env('FRETE_DEV_SENHA');
        if ($senha === '') {
            throw new \RuntimeException('Defina FRETE_DEV_SENHA no .env para criar os usuários de demonstração.');
        }

        return $senha;
    }

    private function slug(string $nome): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower($nome));
    }

    /** CNPJ com dígitos verificadores válidos a partir de um número base de 8 dígitos (filial 0001). */
    private function cnpj(int $base): string
    {
        $n = str_pad((string) $base, 8, '0', STR_PAD_LEFT).'0001';
        foreach ([[5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $pesos) {
            $soma = 0;
            foreach ($pesos as $i => $peso) {
                $soma += (int) $n[$i] * $peso;
            }
            $resto = $soma % 11;
            $n .= $resto < 2 ? '0' : (string) (11 - $resto);
        }

        return $n;
    }
}