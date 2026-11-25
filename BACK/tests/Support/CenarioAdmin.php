<?php

namespace Tests\Support;

use App\Models\Cotacao;
use App\Models\CotacaoItem;
use App\Models\LogAcesso;
use App\Models\LogOperacao;
use App\Models\LogSistema;
use App\Models\Negociacao;
use App\Models\NegociacaoResposta;
use App\Models\Produto;
use App\Models\Programacao;
use App\Models\Transportadora;
use App\Models\Usuario;
use Illuminate\Support\Carbon;

/**
 * Cenário fixo, conferido à mão, para os testes do /admin (hoje = 2026-10-08 12:00).
 *
 * COT-1 marcos SODA 10 t SP→SP: A 100/4d, B 90/3d, C 120/5d → fechada com B a 90 (a menor: saving 0), entregue no prazo, frete 900
 * COT-2 marcos SODA 20 t SP→MG: A 80/2d, B 95/4d, C declinou → fechada com A a 80 (a menor: saving 0), entregue com 2 dias de atraso, frete 1600
 * COT-3 lucia ACIDO 5 t SP→SP: aberta, A aguardando desde 10-05
 * COT-4 lucia SODA 10 t SP→SP: cancelada
 */
trait CenarioAdmin
{
    public Usuario $admin;

    public Usuario $marcos;

    public Usuario $lucia;

    public Transportadora $tA;

    public Transportadora $tB;

    public Transportadora $tC;

    public Produto $soda;

    public Produto $acido;

    /** @var array<int, Cotacao> */
    public array $cot = [];

    protected function montarCenario(): void
    {
        Carbon::setTestNow('2026-10-08 12:00:00');

        $this->admin = $this->usuario('admin', 'ADMINISTRADOR');
        $this->marcos = $this->usuario('marcos', 'VENDEDOR');
        $this->lucia = $this->usuario('lucia', 'VENDEDOR');
        $this->tA = $this->transportadora('TRANSLOG');
        $this->tB = $this->transportadora('ROTASUL');
        $this->tC = $this->transportadora('SEMPRE');
        $this->soda = $this->produto('SODA');
        $this->acido = $this->produto('ACIDO');
        $cliente = $this->cliente('Cliente Um');

        $this->cot[1] = $this->cotacao(1, $this->marcos, $cliente->id, $this->soda, 10000, 'SP', 'SP', 'PROGRAMADA', '2026-10-01', '2026-10-01 09:00:00');
        $this->cot[2] = $this->cotacao(2, $this->marcos, $cliente->id, $this->soda, 20000, 'SP', 'MG', 'PROGRAMADA', '2026-10-02', '2026-10-02 08:00:00');
        $this->cot[3] = $this->cotacao(3, $this->lucia, $cliente->id, $this->acido, 5000, 'SP', 'SP', 'ABERTA', '2026-10-03', '2026-10-03 08:00:00');
        $this->cot[4] = $this->cotacao(4, $this->lucia, $cliente->id, $this->soda, 10000, 'SP', 'SP', 'CANCELADA', '2026-10-04', '2026-10-04 08:00:00');

        $n1 = $this->negociacao($this->cot[1], 'FECHADA', $this->tB, '90.00', '900.00', 3, '2026-10-01 21:00:00');
        $this->resposta($n1, $this->tA, 'RESPONDIDA', '100.00', 4, '2026-10-01 10:00:00', '2026-10-01 12:00:00');
        $this->resposta($n1, $this->tB, 'RESPONDIDA', '90.00', 3, '2026-10-01 10:00:00', '2026-10-01 11:00:00');
        $this->resposta($n1, $this->tC, 'RESPONDIDA', '120.00', 5, '2026-10-01 10:00:00', '2026-10-01 20:00:00');
        $n2 = $this->negociacao($this->cot[2], 'FECHADA', $this->tA, '80.00', '1600.00', 2, '2026-10-04 08:00:00');
        $this->resposta($n2, $this->tA, 'RESPONDIDA', '80.00', 2, '2026-10-02 09:00:00', '2026-10-02 13:00:00');
        $this->resposta($n2, $this->tB, 'RESPONDIDA', '95.00', 4, '2026-10-02 09:00:00', '2026-10-02 14:00:00');
        $this->resposta($n2, $this->tC, 'DECLINADA', null, null, '2026-10-02 09:00:00', null);
        $n3 = $this->negociacao($this->cot[3], 'ABERTA', null, null, null, null, null);
        $this->resposta($n3, $this->tA, 'AGUARDANDO', null, null, '2026-10-05 09:00:00', null);

        $this->programacao(1, $this->cot[1], $this->tB, '90.00', '900.00', '2026-10-05', '2026-10-04');
        $this->programacao(2, $this->cot[2], $this->tA, '80.00', '1600.00', '2026-10-01', '2026-10-03');

        $this->logs();
    }

    private function cotacao(int $n, Usuario $vendedor, string $clienteId, Produto $p, int $kg, string $ufO, string $ufD, string $status, string $data, string $criado): Cotacao
    {
        $c = Cotacao::create([
            'codigo' => sprintf('COT-%06d', $n), 'data_solicitacao' => $data, 'vendedor_id' => $vendedor->id,
            'operacao' => 'CARREGAMENTO', 'cliente_id' => $clienteId, 'tipo_veiculo_codigo' => 'CARRETA',
            'origem_cidade' => 'Origem', 'origem_uf' => $ufO, 'destino_cidade' => 'Destino', 'destino_uf' => $ufD,
            'valor_nf' => '15000.00', 'status' => $status, 'criado_em' => $criado,
        ]);
        CotacaoItem::create(['cotacao_id' => $c->id, 'sequencia' => 1, 'produto_id' => $p->id, 'embalagem_codigo' => 'BOMBONA', 'quantidade_kg' => $kg, 'volumes' => 1]);

        return $c;
    }

    private function negociacao(Cotacao $c, string $status, ?Transportadora $venc, ?string $vt, ?string $vf, ?int $prazo, ?string $fechadoEm): Negociacao
    {
        return Negociacao::create([
            'cotacao_id' => $c->id, 'status' => $status, 'transportadora_id' => $venc?->id,
            'valor_tonelada' => $vt, 'valor_frete' => $vf, 'prazo_dias' => $prazo,
            'observacao_fechamento' => $venc ? 'ok' : null, 'fechado_em' => $fechadoEm, 'fechado_por_id' => $venc ? $this->admin->id : null,
        ]);
    }

    private function resposta(Negociacao $n, Transportadora $t, string $status, ?string $vt, ?int $prazo, string $enc, ?string $resp): void
    {
        $toneladas = $n->cotacao->itens()->sum('quantidade_kg') / 1000;
        NegociacaoResposta::create([
            'negociacao_id' => $n->id, 'transportadora_id' => $t->id, 'status' => $status,
            'encaminhado_em' => $enc, 'encaminhado_por_id' => $this->admin->id,
            'valor_tonelada' => $vt, 'valor_frete' => $vt ? (string) ($vt * $toneladas) : null, 'prazo_dias' => $prazo,
            'respondido_em' => $resp, 'respondido_por_id' => $resp ? $this->admin->id : null,
        ]);
    }

    private function programacao(int $n, Cotacao $c, Transportadora $t, string $vt, string $total, string $prevista, string $realizada): void
    {
        Programacao::create([
            'codigo' => sprintf('CRG-%06d', $n), 'cotacao_id' => $c->id, 'operacao' => 'CARREGAMENTO', 'status' => 'ENTREGUE',
            'data_pedido' => $c->data_solicitacao, 'representante_id' => $c->vendedor_id, 'cliente_id' => $c->cliente_id,
            'deposito' => 'VIRTUAL', 'modalidade_frete' => 'CIF', 'transportadora_id' => $t->id,
            'origem_uf' => $c->origem_uf, 'destino_uf' => $c->destino_uf, 'valor_tonelada' => $vt, 'valor_total' => $total,
            'prazo_dias' => 3, 'data_entrega_prevista' => $prevista, 'data_entrega_realizada' => $realizada,
        ]);
    }

    private function logs(): void
    {
        $op = fn (string $quando, Usuario $u, string $entidade, Cotacao $c, string $evento, ?string $de = null, ?string $para = null, ?string $corr = null) => LogOperacao::create([
            'ocorrido_em' => $quando, 'usuario_id' => $u->id, 'entidade' => $entidade, 'registro_id' => $c->id,
            'registro_codigo' => $c->codigo, 'evento' => $evento, 'status_de' => $de, 'status_para' => $para, 'correlacao_id' => $corr,
        ]);
        $op('2026-10-01 09:00:00', $this->marcos, 'COTACAO', $this->cot[1], 'COTACAO_CRIADA', null, 'ABERTA');
        $op('2026-10-03 08:00:00', $this->lucia, 'COTACAO', $this->cot[3], 'COTACAO_CRIADA', null, 'ABERTA');
        $op('2026-10-01 21:00:00', $this->admin, 'NEGOCIACAO', $this->cot[1], 'NEGOCIACAO_FECHADA', 'ABERTA', 'FECHADA', 'corr-1');
        $op('2026-10-02 10:00:00', $this->admin, 'PROGRAMACAO', $this->cot[1], 'PROGRAMACAO_CRIADA', null, 'EM_COTACAO');

        LogSistema::create(['ocorrido_em' => '2026-10-01 21:00:00', 'nivel' => 'INFO', 'categoria' => 'ALTERACAO', 'evento' => 'REGISTRO_ALTERADO', 'usuario_id' => $this->admin->id, 'usuario_login' => 'admin', 'tabela' => 'negociacao', 'registro_id' => $this->cot[1]->id, 'mensagem' => 'diff da negociação', 'correlacao_id' => 'corr-1']);
        LogSistema::create(['ocorrido_em' => '2026-10-06 10:00:00', 'nivel' => 'ERROR', 'categoria' => 'ERRO', 'evento' => 'EXCECAO', 'mensagem' => 'falha no ERP']);
        LogSistema::create(['ocorrido_em' => '2026-10-06 11:00:00', 'nivel' => 'WARNING', 'categoria' => 'AUTENTICACAO', 'evento' => 'LOGIN_FALHA', 'usuario_login' => 'intruso', 'ip' => '10.0.0.9']);

        LogAcesso::create(['ocorrido_em' => '2026-10-01 08:00:00', 'usuario_id' => $this->marcos->id, 'ip' => '10.0.0.1']);
        LogAcesso::create(['ocorrido_em' => '2026-10-02 08:00:00', 'usuario_id' => $this->marcos->id, 'ip' => '10.0.0.1']);
        LogAcesso::create(['ocorrido_em' => '2026-10-02 09:00:00', 'usuario_id' => $this->admin->id, 'ip' => '10.0.0.2']);
    }

    protected function linha(array $linhas, string $campo, string $valor): array
    {
        $achou = collect($linhas)->first(fn ($l) => ($l[$campo] ?? null) === $valor);
        $this->assertNotNull($achou, "Linha {$campo}={$valor} não encontrada.");

        return $achou;
    }
}
