<?php

namespace Database\Seeders;

use App\Models\Cliente;
use App\Models\Cotacao;
use App\Models\CotacaoItem;
use App\Models\LogAcesso;
use App\Models\LogOperacao;
use App\Models\LogSistema;
use App\Models\Negociacao;
use App\Models\NegociacaoResposta;
use App\Models\Produto;
use App\Models\Programacao;
use App\Models\ProgramacaoItem;
use App\Models\TipoVeiculo;
use App\Models\Transportadora;
use App\Models\Usuario;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Massa de demonstração para o painel /admin: ~6 meses de cotações com respostas, fechamentos,
 * cargas e trilha de auditoria. Determinística (mt_srand), só para desenvolvimento.
 * Uso: php artisan db:seed --class=DevAnaliseSeeder (depois do DatabaseSeeder). Não entra no seed padrão.
 */
class DevAnaliseSeeder extends Seeder
{
    private const ROTAS = [
        ['CUBATÃO', 'SP', 'CAMPINAS', 'SP', .6], ['PAULÍNIA', 'SP', 'GOIÂNIA', 'GO', 1.1], ['PARANAGUÁ', 'PR', 'RIO VERDE', 'GO', 1.35],
        ['PAULÍNIA', 'SP', 'RECIFE', 'PE', 1.9], ['CUBATÃO', 'SP', 'BETIM', 'MG', .95], ['UBERABA', 'MG', 'DUMONT', 'SP', .55],
        ['SALVADOR', 'BA', 'ARACAJU', 'SE', .5], ['SANTOS', 'SP', 'CURITIBA', 'PR', .7], ['PAULÍNIA', 'SP', 'PORTO ALEGRE', 'RS', 1.5],
        ['RIO DE JANEIRO', 'RJ', 'CUBATÃO', 'SP', .8],
    ];

    private const BASE = ['HCL' => 260, 'SODA' => 300, 'SULFÚRICO' => 340, 'HIPO' => 270, 'FOSFÓRICO' => 360, 'UREIA' => 220];

    public function run(): void
    {
        if (Cotacao::where('codigo', 'like', 'COT-9%')->exists()) {
            return;
        }
        mt_srand(20261008);

        $admin = Usuario::where('login', mb_strtolower((string) env('FRETE_ADMIN_LOGIN')))->firstOrFail();
        $vendedores = Usuario::whereHas('grupos', fn ($q) => $q->where('codigo', 'VENDEDOR'))->get()->values();
        $usuariosTransp = Usuario::whereNotNull('transportadora_id')->get()->keyBy('transportadora_id');
        $transps = Transportadora::all()->values();
        $produtos = Produto::whereIn('nome', array_keys(self::BASE))->get()->values();
        $clientes = Cliente::all()->values();
        $veiculos = TipoVeiculo::pluck('codigo')->all();
        abort_if($vendedores->isEmpty() || $transps->count() < 5 || $produtos->isEmpty() || $clientes->isEmpty(), 1, 'Rode o DatabaseSeeder antes (precisa do DevSeeder).');

        $perfil = [];
        foreach ($transps as $t) {
            $perfil[$t->id] = ['vies' => $this->r(-.1, .16), 'forte' => mt_rand(0, count(self::ROTAS) - 1), 'resp' => $this->r(.55, .98), 'agil' => $this->r(1, 20)];
        }
        $pesoVend = $vendedores->map(fn () => $this->r(.3, 3))->all();

        $hoje = Carbon::now();
        $nCot = 0;
        $nCrg = 0;

        for ($i = 0; $i < 130; $i++) {
            $idade = (int) floor(pow(mt_rand() / mt_getrandmax(), 1.15) * 175);
            $criada = $hoje->copy()->subDays($idade)->setTime(mt_rand(7, 17), mt_rand(0, 59), mt_rand(0, 59));
            $vend = $vendedores[$this->sorteio($pesoVend)];
            $produto = $produtos[mt_rand(0, $produtos->count() - 1)];
            $cliente = $clientes[mt_rand(0, $clientes->count() - 1)];
            $rotaIdx = mt_rand(0, count(self::ROTAS) - 1);
            [$cO, $uO, $cD, $uD, $fator] = self::ROTAS[$rotaIdx];
            $kg = mt_rand(8, 34) * 1000;
            $t = $kg / 1000;
            $base = self::BASE[$produto->nome] * $fator;

            $status = 'ABERTA';
            $cancelada = false;
            if ($idade >= 4) {
                $x = mt_rand(1, 100);
                $status = $x <= 9 ? 'CANCELADA' : ($x <= 82 ? 'PROGRAMADA' : ($x <= 90 ? 'FECHADA' : 'EM_NEGOCIACAO'));
                $cancelada = $status === 'CANCELADA';
            } elseif (mt_rand(0, 1)) {
                $status = 'EM_NEGOCIACAO';
            }

            $cot = Cotacao::create([
                'codigo' => sprintf('COT-9%05d', ++$nCot), 'data_solicitacao' => $criada->copy()->setTimezone(config('frete.fuso_exibicao'))->toDateString(),
                'vendedor_id' => $vend->id, 'operacao' => 'CARREGAMENTO', 'cliente_id' => $cliente->id,
                'tipo_veiculo_codigo' => $veiculos[mt_rand(0, count($veiculos) - 1)], 'origem_cidade' => $cO, 'origem_uf' => $uO, 'destino_cidade' => $cD, 'destino_uf' => $uD,
                'valor_nf' => round($t * $base * mt_rand(9, 14), 2), 'status' => $status, 'criado_em' => $criada, 'atualizado_em' => $criada,
            ]);
            CotacaoItem::create(['cotacao_id' => $cot->id, 'sequencia' => 1, 'produto_id' => $produto->id, 'embalagem_codigo' => 'GRANEL_LIQUIDO', 'quantidade_kg' => $kg, 'volumes' => null]);
            $this->op($criada, $vend, 'COTACAO', $cot, 'COTACAO_CRIADA', null, 'ABERTA');

            if ($status === 'ABERTA' && ! mt_rand(0, 2)) {
                Negociacao::create(['cotacao_id' => $cot->id, 'status' => 'ABERTA']);

                continue;
            }
            if ($status === 'ABERTA') {
                continue;
            }

            $neg = Negociacao::create(['cotacao_id' => $cot->id, 'status' => $cancelada ? 'CANCELADA' : 'ABERTA']);
            $encaminhada = $criada->copy()->addHours(mt_rand(1, 8));
            $consultadas = $transps->shuffle()->take(mt_rand(1, 3) === 1 ? mt_rand(1, 2) : mt_rand(3, 7));
            $this->op($encaminhada, $admin, 'NEGOCIACAO', $cot, 'NEGOCIACAO_ENCAMINHADA', 'ABERTA', 'EM_NEGOCIACAO', ['transportadoras' => $consultadas->count()]);

            $respondidas = [];
            foreach ($consultadas as $tr) {
                $p = $perfil[$tr->id];
                $x = mt_rand(1, 100) / 100;
                $recente = $idade < 3;
                $st = $recente && $x > .5 ? 'AGUARDANDO' : ($x <= $p['resp'] ? 'RESPONDIDA' : ($x <= $p['resp'] + .1 ? 'DECLINADA' : ($recente ? 'AGUARDANDO' : 'DECLINADA')));
                $dados = ['negociacao_id' => $neg->id, 'transportadora_id' => $tr->id, 'status' => $st, 'encaminhado_em' => $encaminhada, 'encaminhado_por_id' => $admin->id];
                if ($st === 'RESPONDIDA') {
                    $preco = round($base * (1 + $p['vies'] + ($p['forte'] === $rotaIdx ? -.11 : 0) + $this->r(-.06, .07)) + $this->r(0, 4), 2);
                    $quando = $encaminhada->copy()->addMinutes((int) ($p['agil'] * 60 * $this->r(.5, 1.6)));
                    $quemResp = $usuariosTransp[$tr->id] ?? $admin;
                    $dados += ['valor_tonelada' => $preco, 'valor_frete' => round($preco * $t, 2), 'prazo_dias' => mt_rand(1, 6), 'prazo_coleta_dias' => mt_rand(0, 2), 'validade' => $quando->copy()->addDays(5)->toDateString(), 'respondido_em' => $quando, 'respondido_por_id' => $quemResp->id];
                    $respondidas[] = ['tr' => $tr, 'preco' => $preco, 'prazo' => $dados['prazo_dias'], 'coleta' => $dados['prazo_coleta_dias'], 'quando' => $quando];
                    $this->op($quando, $quemResp, 'NEGOCIACAO', $cot, 'NEGOCIACAO_RESPONDIDA', null, null, ['transportadora' => $tr->nome_fantasia, 'valor_tonelada' => $preco, 'prazo' => $dados['prazo_dias']]);
                } elseif ($st === 'DECLINADA') {
                    $this->op($encaminhada->copy()->addHours(mt_rand(2, 30)), $usuariosTransp[$tr->id] ?? $admin, 'NEGOCIACAO', $cot, 'NEGOCIACAO_DECLINADA', null, null, ['transportadora' => $tr->nome_fantasia]);
                }
                NegociacaoResposta::create($dados);
            }

            if ($cancelada) {
                $this->op($criada->copy()->addDays(mt_rand(1, 4)), $vend, 'COTACAO', $cot, 'COTACAO_CANCELADA', 'EM_NEGOCIACAO', 'CANCELADA');

                continue;
            }
            if (! in_array($status, ['FECHADA', 'PROGRAMADA'], true) || ! $respondidas) {
                if (in_array($status, ['FECHADA', 'PROGRAMADA'], true)) {
                    $cot->forceFill(['status' => 'EM_NEGOCIACAO'])->saveQuietly();
                }

                continue;
            }

            usort($respondidas, fn ($a, $b) => $a['preco'] <=> $b['preco']);
            $venc = mt_rand(1, 100) <= 82 || count($respondidas) === 1 ? $respondidas[0] : $respondidas[min(count($respondidas) - 1, mt_rand(1, 2))];
            $vt = round($venc['preco'] * $this->r(.97, 1), 2);
            $fechadoEm = collect($respondidas)->max('quando')->copy()->addHours(mt_rand(2, 26));
            $neg->forceFill(['status' => 'FECHADA', 'transportadora_id' => $venc['tr']->id, 'valor_tonelada' => $vt, 'valor_frete' => round($vt * $t, 2), 'prazo_dias' => $venc['prazo'], 'prazo_coleta_dias' => $venc['coleta'], 'observacao_fechamento' => 'Fechado por telefone.', 'fechado_em' => $fechadoEm, 'fechado_por_id' => $admin->id])->saveQuietly();
            $this->op($fechadoEm, $admin, 'NEGOCIACAO', $cot, 'NEGOCIACAO_FECHADA', 'ABERTA', 'FECHADA', ['transportadora' => $venc['tr']->nome_fantasia, 'valor_tonelada' => $vt, 'frete' => round($vt * $t, 2)]);

            if ($status !== 'PROGRAMADA') {
                continue;
            }

            $partes = mt_rand(1, 4) === 1 ? 2 : 1;
            for ($k = 0; $k < $partes; $k++) {
                $kgCarga = $partes === 1 ? $kg : ($k === 0 ? (int) ($kg / 2) : $kg - (int) ($kg / 2));
                $tCarga = $venc['tr'];
                if (mt_rand(1, 100) <= 8) {
                    $tCarga = $transps[mt_rand(0, $transps->count() - 1)];
                }
                $pedido = $fechadoEm->copy()->addDays(mt_rand(0, 2));
                $coleta = $pedido->copy()->addDays(mt_rand(1, 3));
                $entregaPrev = $coleta->copy()->addDays($venc['prazo']);
                $atraso = mt_rand(1, 100) <= (in_array($tCarga->id, [$transps[0]->id, $transps[3]->id], true) ? 45 : 18) ? mt_rand(1, 4) : mt_rand(-1, 0);
                $entregaReal = $entregaPrev->copy()->addDays($atraso);
                $stCarga = $entregaReal->lte($hoje) ? 'ENTREGUE' : ($coleta->lte($hoje) ? 'EM_TRANSITO' : 'AGUARDANDO_CARREGAMENTO');
                $prog = Programacao::create([
                    'codigo' => sprintf('CRG-9%05d', ++$nCrg), 'cotacao_id' => $cot->id, 'operacao' => 'CARREGAMENTO', 'status' => $stCarga, 'data_pedido' => $pedido->toDateString(),
                    'pedido_venda' => 'PV-'.mt_rand(1000, 9999), 'representante_id' => $vend->id, 'cliente_id' => $cliente->id, 'deposito' => mt_rand(0, 1) ? 'FISICO' : 'VIRTUAL',
                    'modalidade_frete' => mt_rand(0, 3) ? 'CIF' : 'FOB', 'transportadora_id' => $tCarga->id, 'tipo_veiculo_codigo' => $cot->tipo_veiculo_codigo,
                    'origem_cidade' => $cO, 'origem_uf' => $uO, 'destino_cidade' => $cD, 'destino_uf' => $uD, 'valor_tonelada' => $vt, 'valor_total' => round($vt * $kgCarga / 1000, 2),
                    'prazo_dias' => $venc['prazo'], 'prazo_coleta_dias' => $venc['coleta'], 'data_coleta_prevista' => $coleta->toDateString(), 'data_coleta_realizada' => $stCarga === 'AGUARDANDO_CARREGAMENTO' ? null : $coleta->toDateString(),
                    'data_entrega_prevista' => $entregaPrev->toDateString(), 'data_entrega_realizada' => $stCarga === 'ENTREGUE' ? $entregaReal->toDateString() : null,
                    'criado_em' => $pedido, 'atualizado_em' => $pedido,
                ]);
                ProgramacaoItem::create(['programacao_id' => $prog->id, 'sequencia' => 1, 'produto_id' => $produto->id, 'embalagem_codigo' => 'GRANEL_LIQUIDO', 'quantidade_kg' => $kgCarga]);
                $this->op($pedido, $vend, 'PROGRAMACAO', $prog, 'PROGRAMACAO_CRIADA', null, 'AGUARDANDO_CARREGAMENTO');
                if ($tCarga->id !== $venc['tr']->id) {
                    $this->op($pedido, $vend, 'PROGRAMACAO', $prog, 'PROGRAMACAO_DIVERGE_FECHAMENTO', null, null, ['fechamento' => $venc['tr']->nome_fantasia, 'carga' => $tCarga->nome_fantasia]);
                }
                if ($stCarga !== 'AGUARDANDO_CARREGAMENTO') {
                    $this->op($coleta, $usuariosTransp[$tCarga->id] ?? $admin, 'PROGRAMACAO', $prog, 'PROGRAMACAO_STATUS', 'AGUARDANDO_CARREGAMENTO', 'EM_TRANSITO');
                }
                if ($stCarga === 'ENTREGUE') {
                    $this->op($entregaReal, $usuariosTransp[$tCarga->id] ?? $admin, 'PROGRAMACAO', $prog, 'PROGRAMACAO_ENTREGUE', 'EM_TRANSITO', 'ENTREGUE');
                }
            }
        }

        $ips = ['203.0.113.8', '203.0.113.31', '198.51.100.77', '192.0.2.15'];
        foreach (range(1, 40) as $n) {
            $quando = $hoje->copy()->subDays(mt_rand(0, 90))->setTime(mt_rand(6, 20), mt_rand(0, 59));
            $quem = collect([$admin])->merge($vendedores)->random();
            LogAcesso::create(['ocorrido_em' => $quando, 'usuario_id' => $quem->id, 'ip' => $ips[mt_rand(0, 3)]]);
        }
        foreach (range(1, 14) as $n) {
            $quando = $hoje->copy()->subDays(mt_rand(0, 60))->setTime(mt_rand(0, 23), mt_rand(0, 59));
            LogSistema::create(['ocorrido_em' => $quando, 'nivel' => 'WARNING', 'categoria' => 'AUTENTICACAO', 'evento' => 'LOGIN_FALHA', 'usuario_login' => ['admin', 'operador', 'root', 'marcos'][mt_rand(0, 3)], 'mensagem' => 'Senha inválida.', 'metodo' => 'POST', 'rota' => 'api/auth/login', 'ip' => $ips[mt_rand(0, 3)], 'user_agent' => 'Mozilla/5.0']);
        }
        foreach (range(1, 5) as $n) {
            $quando = $hoje->copy()->subDays(mt_rand(0, 30))->setTime(mt_rand(0, 23), mt_rand(0, 59));
            LogSistema::create(['ocorrido_em' => $quando, 'nivel' => 'ERROR', 'categoria' => 'ERRO', 'evento' => 'EXCECAO', 'mensagem' => 'Timeout ao consultar o ERP (SYNC_REGISTRO_ERRO).', 'contexto' => json_encode(['tentativa' => $n, 'endpoint' => '/erp/clientes']), 'metodo' => 'GET', 'rota' => 'api/clientes', 'ip' => '10.0.0.15']);
        }
        LogSistema::create(['ocorrido_em' => $hoje->copy()->subDays(2), 'nivel' => 'INFO', 'categoria' => 'ALTERACAO', 'evento' => 'REGISTRO_ALTERADO', 'usuario_id' => $admin->id, 'usuario_login' => 'admin', 'tabela' => 'transportadora', 'mensagem' => 'Telefone atualizado.', 'dados_antes' => json_encode(['telefone' => '11 4000-0000']), 'dados_depois' => json_encode(['telefone' => '11 4000-1111']), 'metodo' => 'PUT', 'rota' => 'api/transportadoras/1', 'ip' => '10.0.0.15']);
    }

    private function op(Carbon $quando, Usuario $quem, string $entidade, object $registro, string $evento, ?string $de = null, ?string $para = null, array $detalhe = []): void
    {
        LogOperacao::create([
            'ocorrido_em' => $quando, 'usuario_id' => $quem->id, 'entidade' => $entidade, 'registro_id' => $registro->id,
            'registro_codigo' => $registro->codigo, 'evento' => $evento, 'status_de' => $de, 'status_para' => $para,
            'detalhe' => $detalhe ? json_encode($detalhe, JSON_UNESCAPED_UNICODE) : null, 'correlacao_id' => (string) Str::uuid(),
        ]);
    }

    private function r(float $a, float $b): float
    {
        return $a + (mt_rand() / mt_getrandmax()) * ($b - $a);
    }

    /** Índice sorteado com probabilidade proporcional aos pesos. */
    private function sorteio(array $pesos): int
    {
        $x = $this->r(0, array_sum($pesos));
        foreach ($pesos as $i => $p) {
            if (($x -= $p) <= 0) {
                return $i;
            }
        }

        return count($pesos) - 1;
    }
}
