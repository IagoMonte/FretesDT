<?php

namespace App\Services;

use App\Enums\Operacao;
use App\Enums\StatusCotacao;
use App\Enums\StatusNegociacao;
use App\Enums\StatusResposta;
use App\Exceptions\RegraNegocioException;
use App\Models\Cotacao;
use App\Models\Negociacao;
use App\Models\NegociacaoResposta;
use App\Models\Programacao;
use App\Models\TipoVeiculo;
use App\Models\Transportadora;
use App\Models\Usuario;
use App\Services\Notificacao\NotificacaoService;
use App\Support\Dinheiro;
use App\Support\Fmt;
use App\Support\MensagemFrete;
use App\Support\Rota;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Cotação com N transportadoras: criar, encaminhar, responder/declinar, fechar e cancelar. */
class CotacaoService
{
    public const RELACOES = [
        'vendedor', 'cliente', 'fornecedor', 'itens.produto', 'tipoVeiculo',
        'negociacao.respostas.transportadora', 'negociacao.transportadora', 'negociacao.fechadoPor',
        'programacoes', 'origem.negociacao.respostas.transportadora',
    ];

    public function __construct(
        private ItensService $itens,
        private SequenciaService $sequencia,
        private ProgramacaoService $programacoes,
        private LogService $log,
        private CotacaoAnteriorService $anterior,
        private AnttService $antt,
        private NotificacaoService $notificar,
    ) {}

    public function criar(Usuario $vendedor, array $d): Cotacao
    {
        return DB::transaction(function () use ($vendedor, $d) {
            $usarAnterior = (bool) ($d['usar_anterior'] ?? true);
            $operacao = Operacao::from($d['operacao']);
            $parte = $this->itens->parte($operacao, $d['parte']);
            $itens = $this->itens->itens($d['itens']);
            $fracionada = $this->itens->fracionada($itens);
            $d['tipo_veiculo_codigo'] = $this->veiculoDaCotacao($d, $fracionada);

            $origem = null;
            $todoGranelLiquido = collect($itens)->every(fn ($i) => $i['embalagem_codigo'] === CotacaoAnteriorService::GRANEL_LIQUIDO);
            if ($usarAnterior && $todoGranelLiquido && ! $fracionada) {
                $origem = $this->anterior->buscar(
                    $d['tipo_veiculo_codigo'],
                    $d['origem']['cidade'], $d['origem']['uf'],
                    $d['destino']['cidade'], $d['destino']['uf'],
                    collect($itens)->pluck('produto_id')->all(),
                );
            }
            $cotacao = Cotacao::create(array_merge(
                $this->campos($d, $fracionada),
                $this->itens->colunasParte($operacao, $parte),
                [
                    'codigo' => $this->sequencia->proximo('COTACAO', 'COT-'),
                    'data_solicitacao' => Fmt::hoje(),
                    'vendedor_id' => $vendedor->id,
                    'operacao' => $operacao,
                    'status' => $origem ? StatusCotacao::EmNegociacao : StatusCotacao::Aberta,
                    'cotacao_origem_id' => $origem?->id,
                ],
            ));
            $this->gravarItens($cotacao, $itens);
            $cotacao->negociacao()->create(['status' => StatusNegociacao::Aberta]);

            $this->log->operacao('COTACAO', $cotacao, 'COTACAO_CRIADA', null, $cotacao->status->value, array_filter([
                'parte' => $parte->razao_social, 'itens' => count($itens), 'cotacao_origem' => $origem?->codigo,
            ]));

            return $this->recarregar($cotacao);
        });
    }

    public function atualizar(Cotacao $cotacao, array $d): Cotacao
    {
        return DB::transaction(function () use ($cotacao, $d) {
            $cotacao->exigirVersao($d['versao']);
            if ($cotacao->status === StatusCotacao::Encerrada) {
                throw new RegraNegocioException('Esta cotação foi encerrada e não pode mais ser alterada.');
            }
            if ($cotacao->status !== StatusCotacao::Aberta) {
                throw new RegraNegocioException('Só é possível editar cotações que ainda não foram encaminhadas.');
            }
            $operacao = Operacao::from($d['operacao']);
            $parte = $this->itens->parte($operacao, $d['parte']);
            $itens = $this->itens->itens($d['itens']);
            $fracionada = $this->itens->fracionada($itens);
            $d['tipo_veiculo_codigo'] = $this->veiculoDaCotacao($d, $fracionada);

            $itensAntes = $this->resumoItens($cotacao->itens()->reorder()->get()->all());
            $cotacao->fill(array_merge($this->campos($d, $fracionada), $this->itens->colunasParte($operacao, $parte), ['operacao' => $operacao]));
            $mudancas = [];
            foreach ($cotacao->getDirty() as $campo => $novo) {
                $mudancas[$campo] = ['de' => $cotacao->getOriginal($campo), 'para' => $cotacao->getAttribute($campo)];
            }
            $cotacao->itens()->reorder()->delete();
            $this->gravarItens($cotacao, $itens);
            $itensDepois = $this->resumoItens($itens);
            if ($itensAntes !== $itensDepois) {
                $mudancas['itens'] = ['de' => $itensAntes, 'para' => $itensDepois];
            }
            $cotacao->tocar();

            $this->log->operacao('COTACAO', $cotacao, 'COTACAO_EDITADA', $cotacao->status->value, $cotacao->status->value, $mudancas);

            return $this->recarregar($cotacao);
        });
    }

    /** @param  array<int, string>  $transportadoraIds */
    public function encaminhar(Cotacao $cotacao, Usuario $usuario, array $transportadoraIds): Cotacao
    {
        return DB::transaction(function () use ($cotacao, $usuario, $transportadoraIds) {
            $negociacao = $this->negociacaoAberta($cotacao, [StatusCotacao::Aberta, StatusCotacao::EmNegociacao]);
            $ids = array_values(array_unique($transportadoraIds));
            $transportadoras = Transportadora::whereIn('id', $ids)->where('ativo', true)->get();
            if ($transportadoras->count() !== count($ids)) {
                throw ValidationException::withMessages(['transportadora_ids' => ['Há transportadoras inexistentes ou inativas na lista.']]);
            }

            $jaTem = $negociacao->respostas()->pluck('transportadora_id')->all();
            if ($origem = $cotacao->origemValida()) {
                $jaTem = array_merge($jaTem, Negociacao::where('cotacao_id', $origem->id)->first()?->respostas()->pluck('transportadora_id')->all() ?? []);
            }
            $novas = $transportadoras->reject(fn ($t) => in_array($t->id, $jaTem, true));
            foreach ($novas as $t) {
                $negociacao->respostas()->create([
                    'transportadora_id' => $t->id,
                    'status' => StatusResposta::Aguardando,
                    'encaminhado_em' => now(),
                    'encaminhado_por_id' => $usuario->id,
                ]);
            }
            $this->log->operacao('NEGOCIACAO', $cotacao, 'NEGOCIACAO_ENCAMINHADA', null, null, ['transportadoras' => $novas->pluck('nome_fantasia')->values()]);
            if ($novas->isNotEmpty()) {
                $this->notificar->disparar('NEGOCIACAO_ENCAMINHADA', 'NEGOCIACAO', $cotacao, [
                    'transportadora_ids' => $novas->pluck('id')->values()->all(),
                    'rota' => $this->rotaTexto($cotacao),
                    'kg' => Dinheiro::soma($cotacao->itens()->pluck('quantidade_kg')),
                ]);
            }

            if ($cotacao->status === StatusCotacao::Aberta) {
                $this->mudarStatus($cotacao, StatusCotacao::EmNegociacao);
            } else {
                $cotacao->tocar();
            }

            return $this->recarregar($cotacao);
        });
    }

    /**
     * Cotação igual já existente (mesma parte, rota, carro, valor da NF e itens), não cancelada e recente: só avisa, não bloqueia.
     */
    public function duplicada(Cotacao $nova): ?Cotacao
    {
        $nova->loadMissing('itens');
        $assinatura = fn (Cotacao $c) => $c->itens->map(fn ($i) => $i->produto_id.'|'.$i->embalagem_codigo.'|'.Dinheiro::dec($i->quantidade_kg, 3))->sort()->values()->all();
        $minha = $assinatura($nova);

        return Cotacao::query()
            ->where('id', '<>', $nova->id)
            ->where('cliente_id', $nova->cliente_id)->where('fornecedor_id', $nova->fornecedor_id)
            ->where('operacao', $nova->operacao->value)
            ->where('tipo_veiculo_codigo', $nova->tipo_veiculo_codigo)
            ->where('origem_uf', $nova->origem_uf)->where('destino_uf', $nova->destino_uf)
            ->where('status', '<>', StatusCotacao::Cancelada->value)
            ->where('data_solicitacao', '>=', Fmt::hoje(-(int) config('frete.cotacao_repetida_dias')))
            ->with('itens')->orderByDesc('data_solicitacao')->orderByDesc('codigo')->get()
            ->first(fn (Cotacao $c) => CotacaoAnteriorService::normalizarCidade($c->origem_cidade) === CotacaoAnteriorService::normalizarCidade($nova->origem_cidade)
                && CotacaoAnteriorService::normalizarCidade($c->destino_cidade) === CotacaoAnteriorService::normalizarCidade($nova->destino_cidade)
                && Dinheiro::dec($c->valor_nf, 2) === Dinheiro::dec($nova->valor_nf, 2)
                && $assinatura($c) === $minha);
    }

    public function responder(Cotacao $cotacao, NegociacaoResposta $resposta, Usuario $usuario, array $d): Cotacao
    {
        return DB::transaction(function () use ($cotacao, $resposta, $usuario, $d) {
            $this->negociacaoAberta($cotacao, [StatusCotacao::EmNegociacao]);
            $this->exigirRespostaAtual($resposta, $d['versao'] ?? null, declinadaBloqueia: true);
            [$valorTonelada, $frete, $veiculo] = $this->preco($cotacao, $d);
            $resposta->fill([
                'status' => StatusResposta::Respondida,
                'valor_tonelada' => $valorTonelada,
                'valor_frete' => $frete,
                'tipo_veiculo_codigo' => $veiculo,
                ...$this->antt->snapshot($cotacao, $d['eixos'] ?? $resposta->eixos, ($d['tipo_carga_antt_codigo'] ?? null) ?: $resposta->tipo_carga_antt_codigo, ! empty($d['dedicado'])),
                'prazo_dias' => $d['prazo_dias'],
                'prazo_coleta_dias' => $d['prazo_coleta_dias'] ?? null,
                'validade' => $d['validade'] ?? null,
                'observacao' => $d['observacao'] ?? null,
                'respondido_em' => now(),
                'respondido_por_id' => $usuario->id,
            ])->tocar();
            $this->log->operacao('NEGOCIACAO', $cotacao, 'NEGOCIACAO_RESPONDIDA', null, null, [
                'transportadora' => $resposta->transportadora->nome_fantasia,
                'valor_tonelada' => $resposta->valor_tonelada,
                'prazo_dias' => $resposta->prazo_dias,
                'prazo_coleta_dias' => $resposta->prazo_coleta_dias,
            ]);
            $this->notificar->disparar('NEGOCIACAO_RESPONDIDA', 'NEGOCIACAO', $cotacao, [
                'transportadora_ids' => [$resposta->transportadora_id],
                'transportadora' => $resposta->transportadora->nome_fantasia,
                'valor' => MensagemFrete::valor($cotacao, $resposta->valor_tonelada, $resposta->valor_frete),
                'prazo_dias' => $resposta->prazo_dias,
                'prazo_coleta_dias' => $resposta->prazo_coleta_dias,
                'chave' => $resposta->id.':'.$resposta->versao,
            ]);

            return $this->recarregar($cotacao);
        });
    }

    public function declinar(Cotacao $cotacao, NegociacaoResposta $resposta, Usuario $usuario, ?string $observacao, mixed $versao = null): Cotacao
    {
        return DB::transaction(function () use ($cotacao, $resposta, $usuario, $observacao, $versao) {
            $this->negociacaoAberta($cotacao, [StatusCotacao::EmNegociacao]);
            $this->exigirRespostaAtual($resposta, $versao, true);
            $resposta->fill([
                'status' => StatusResposta::Declinada,
                'valor_tonelada' => null, 'valor_frete' => null, 'tipo_veiculo_codigo' => null, 'prazo_dias' => null, 'prazo_coleta_dias' => null, 'validade' => null,
                'dedicado' => false, 'eixos' => null, 'tipo_carga_antt_codigo' => null, 'piso_antt_valor' => null, 'piso_antt_km' => null, 'piso_antt_coef_id' => null,
                'observacao' => $observacao,
                'respondido_em' => now(),
                'respondido_por_id' => $usuario->id,
            ])->tocar();
            $this->log->operacao('NEGOCIACAO', $cotacao, 'NEGOCIACAO_DECLINADA', null, null, ['transportadora' => $resposta->transportadora->nome_fantasia]);
            $this->notificar->disparar('NEGOCIACAO_DECLINADA', 'NEGOCIACAO', $cotacao, array_filter([
                'transportadora_ids' => [$resposta->transportadora_id],
                'transportadora' => $resposta->transportadora->nome_fantasia,
                'valor' => 'declinou',
                'observacao' => $observacao,
                'chave' => $resposta->id.':'.$resposta->versao,
            ]));

            return $this->recarregar($cotacao);
        });
    }

    /**
     * Fecha a negociação e, na mesma transação, gera a programação: cotação -> FECHADA -> PROGRAMADA.
     *
     * @return array{0: Cotacao, 1: Programacao}
     */
    public function fechar(Cotacao $cotacao, Usuario $usuario, array $d): array
    {
        return DB::transaction(function () use ($cotacao, $usuario, $d) {
            $negociacao = $this->negociacaoAberta($cotacao, [StatusCotacao::Aberta, StatusCotacao::EmNegociacao]);
            $transportadora = Transportadora::where('id', $d['transportadora_id'])->where('ativo', true)->first();
            if (! $transportadora) {
                throw ValidationException::withMessages(['transportadora_id' => ['Transportadora não encontrada ou inativa.']]);
            }
            $this->exigirRespostaValida($cotacao, $negociacao, $transportadora);
            $porFora = ! $this->respondeu($cotacao, $negociacao, $transportadora);
            if ($porFora && empty($d['negociado_por_fora'])) {
                throw ValidationException::withMessages(['transportadora_id' => [
                    'Esta transportadora não respondeu a cotação. Para fechar mesmo assim, marque "Negociado por fora".',
                ]]);
            }
            if ($cotacao->fracionada && empty($d['tipo_veiculo_codigo'])) {
                $d['tipo_veiculo_codigo'] = $negociacao->respostas->firstWhere('transportadora_id', $transportadora->id)?->tipo_veiculo_codigo;
            }
            [$valorTonelada, $frete, $veiculo] = $this->preco($cotacao, $d);
            $resposta = $negociacao->respostas->firstWhere('transportadora_id', $transportadora->id);
            $eixos = $d['eixos'] ?? $resposta?->eixos;
            $tipoCarga = ($d['tipo_carga_antt_codigo'] ?? null) ?: $resposta?->tipo_carga_antt_codigo;

            $negociacao->fill([
                'status' => StatusNegociacao::Fechada,
                'transportadora_id' => $transportadora->id,
                'valor_tonelada' => $valorTonelada,
                'valor_frete' => $frete,
                'tipo_veiculo_codigo' => $veiculo,
                ...$this->antt->snapshot($cotacao, $eixos, $tipoCarga, (bool) $resposta?->dedicado),
                'prazo_dias' => $d['prazo_dias'],
                'prazo_coleta_dias' => $d['prazo_coleta_dias'] ?? null,
                'observacao_fechamento' => $d['observacao'] ?? '',
                'fechado_em' => now(),
                'fechado_por_id' => $usuario->id,
            ])->save();
            $negociacao->setRelation('transportadora', $transportadora);
            $this->log->operacao('NEGOCIACAO', $cotacao, 'NEGOCIACAO_FECHADA', 'ABERTA', 'FECHADA', [
                'transportadora' => $transportadora->nome_fantasia,
                'valor_tonelada' => $negociacao->valor_tonelada,
                'valor_frete' => $negociacao->valor_frete,
                'prazo_dias' => $negociacao->prazo_dias,
                'prazo_coleta_dias' => $negociacao->prazo_coleta_dias,
                'observacao' => mb_substr((string) ($d['observacao'] ?? ''), 0, 200),
                'negociado_por_fora' => $porFora ?: null,
            ]);

            $this->mudarStatus($cotacao, StatusCotacao::Fechada);
            $cotacao->load('itens');
            $programacao = $this->programacoes->criarDeFechamento($cotacao, $negociacao, $d['programacao'] ?? [], $usuario);
            $this->mudarStatus($cotacao, StatusCotacao::Programada);
            $this->notificar->disparar('NEGOCIACAO_FECHADA', 'NEGOCIACAO', $cotacao, [
                'transportadora_ids' => [$transportadora->id],
                'transportadora' => $transportadora->nome_fantasia,
                'valor' => MensagemFrete::valor($cotacao, $negociacao->valor_tonelada, $negociacao->valor_frete),
                'prazo_dias' => $negociacao->prazo_dias,
                'prazo_coleta_dias' => $negociacao->prazo_coleta_dias,
                'pedido' => $programacao->pedido_venda,
                'programacao' => $programacao->codigo,
                'coleta' => MensagemFrete::data($programacao->data_coleta_prevista),
                ...MensagemFrete::pedido($programacao),
            ]);

            return [$this->recarregar($cotacao), $programacao];
        });
    }

    public function cancelar(Cotacao $cotacao, ?string $motivo): Cotacao
    {
        return DB::transaction(function () use ($cotacao, $motivo) {
            $cotacao->status->exigirTransicao(StatusCotacao::Cancelada);
            $negociacao = $cotacao->negociacao;
            if ($negociacao && $negociacao->status === StatusNegociacao::Aberta) {
                $negociacao->status = StatusNegociacao::Cancelada;
                $negociacao->save();
                $this->log->operacao('NEGOCIACAO', $cotacao, 'NEGOCIACAO_CANCELADA', 'ABERTA', 'CANCELADA');
            }
            $de = $cotacao->status->value;
            $cotacao->status = StatusCotacao::Cancelada;
            $cotacao->save();
            $this->log->operacao('COTACAO', $cotacao, 'COTACAO_CANCELADA', $de, 'CANCELADA', array_filter(['motivo' => $motivo]));
            $this->notificar->disparar('COTACAO_CANCELADA', 'COTACAO', $cotacao, array_filter(['motivo' => $motivo]));

            return $this->recarregar($cotacao);
        });
    }

    /** Encerra sem fechar: as respostas ficam como estão (válidas), mas a cotação não aceita mais nenhuma mudança. */
    public function encerrar(Cotacao $cotacao, ?string $motivo): Cotacao
    {
        return DB::transaction(function () use ($cotacao, $motivo) {
            $de = $cotacao->status;
            $de->exigirTransicao(StatusCotacao::Encerrada);
            $cotacao->status = StatusCotacao::Encerrada;
            $cotacao->save();
            $this->log->operacao('COTACAO', $cotacao, 'COTACAO_ENCERRADA', $de->value, 'ENCERRADA', array_filter(['motivo' => $motivo]));

            return $this->recarregar($cotacao);
        });
    }

    private function rotaTexto(Cotacao $cotacao): string
    {
        return "{$cotacao->origem_cidade}/{$cotacao->origem_uf} → {$cotacao->destino_cidade}/{$cotacao->destino_uf}";
    }

    public function recarregar(Cotacao $cotacao): Cotacao
    {
        return $cotacao->refresh()->load(self::RELACOES);
    }

    private function mudarStatus(Cotacao $cotacao, StatusCotacao $para): void
    {
        $de = $cotacao->status;
        $de->exigirTransicao($para);
        $cotacao->status = $para;
        $cotacao->save();
        $this->log->operacao('COTACAO', $cotacao, 'COTACAO_STATUS', $de->value, $para->value);
    }

    /**
     * Duas abas mexendo na mesma proposta: a versão enviada precisa ser a atual (409). Proposta declinada é definitiva:
     * não volta a ser respondida (a transportadora que quiser retomar fala com o gestor, que reencaminha a cotação).
     */
    private function exigirRespostaAtual(NegociacaoResposta $resposta, mixed $versao, bool $declinadaBloqueia = false): void
    {
        $resposta->refresh();
        if ($versao !== null) {
            $resposta->exigirVersao($versao);
        }
        if ($declinadaBloqueia && $resposta->status === StatusResposta::Declinada) {
            throw new RegraNegocioException('Esta transportadora já declinou esta cotação.');
        }
    }

    /** Negociação precisa estar ABERTA e a cotação num dos status permitidos. */
    private function negociacaoAberta(Cotacao $cotacao, array $statusPermitidos): Negociacao
    {
        $negociacao = $cotacao->negociacao;
        if (! in_array($cotacao->status, $statusPermitidos, true) || ! $negociacao || $negociacao->status !== StatusNegociacao::Aberta) {
            throw new RegraNegocioException(match ($cotacao->status) {
                StatusCotacao::Fechada, StatusCotacao::Programada => 'Esta cotação já foi fechada e não aceita mais respostas.',
                StatusCotacao::Cancelada => 'Esta cotação foi cancelada.',
                StatusCotacao::Encerrada => 'Esta cotação foi encerrada e não aceita mais alterações.',
                StatusCotacao::Aberta => 'Esta cotação ainda não foi encaminhada às transportadoras.',
                default => 'Esta operação não é permitida na situação atual da cotação.',
            });
        }

        return $negociacao;
    }

    /**
     * Transportadora cuja única resposta está na original cancelada não tem mais proposta válida:
     * 422 sem citar a origem (a mensagem também chega a quem não é interno).
     */
    private function exigirRespostaValida(Cotacao $cotacao, Negociacao $negociacao, Transportadora $t): void
    {
        $origem = $cotacao->origem;
        if (! $origem || $origem->status !== StatusCotacao::Cancelada || $negociacao->respostas->contains('transportadora_id', $t->id)) {
            return;
        }
        if ($origem->negociacao?->respostas()->where('transportadora_id', $t->id)->exists()) {
            throw ValidationException::withMessages(['transportadora_id' => ['A proposta desta transportadora não é mais válida: reencaminhe a cotação.']]);
        }
    }

    /** A transportadora tem resposta RESPONDIDA nesta cotação (própria ou herdada da cotação original). */
    private function respondeu(Cotacao $cotacao, Negociacao $negociacao, Transportadora $t): bool
    {
        if ($negociacao->respostas->contains(fn ($r) => $r->transportadora_id === $t->id && $r->status === StatusResposta::Respondida)) {
            return true;
        }

        return (bool) $cotacao->origem?->negociacao?->respostas()
            ->where('transportadora_id', $t->id)->where('status', StatusResposta::Respondida->value)->exists();
    }

    /** valor_frete = valor_tonelada × Σ quantidade_kg dos itens ÷ 1000 (HALF_UP, 2 casas). */
    private function frete(Cotacao $cotacao, string|int|float $valorTonelada): string
    {
        $cotacao->loadMissing('itens');

        return Dinheiro::frete($valorTonelada, Dinheiro::soma($cotacao->itens->pluck('quantidade_kg')));
    }

    /**
     * Preço e carro de uma resposta/fechamento. Normal: a transportadora informa R$/t e o frete é R$/t × kg ÷ 1000 (2 casas, HALF_UP,
     * a partir do valor já arredondado). Fracionada: informa o frete cheio (R$) e o carro que consegue usar; o R$/t fica derivado.
     *
     * @return array{0: string, 1: string, 2: ?string} [valor_tonelada, valor_frete, tipo_veiculo_codigo]
     */
    private function preco(Cotacao $cotacao, array $d): array
    {
        $cotacao->loadMissing('itens');
        if (! $cotacao->fracionada) {
            $valorTonelada = Dinheiro::dec($d['valor_tonelada'] ?? null, 2);
            if ($valorTonelada === null) {
                throw ValidationException::withMessages(['valor_tonelada' => ['Informe o valor por tonelada.']]);
            }

            return [$valorTonelada, $this->frete($cotacao, $valorTonelada), null];
        }
        $frete = Dinheiro::dec($d['valor_frete'] ?? null, 2);
        if ($frete === null) {
            throw ValidationException::withMessages(['valor_frete' => ['Informe o valor do frete (cotação fracionada).']]);
        }
        $veiculo = $d['tipo_veiculo_codigo'] ?? null;
        if (! $veiculo || ! TipoVeiculo::where('codigo', $veiculo)->exists()) {
            throw ValidationException::withMessages(['tipo_veiculo_codigo' => ['Informe o tipo de carro que atende o transporte.']]);
        }

        return [Dinheiro::tonelada($frete, Dinheiro::soma($cotacao->itens->pluck('quantidade_kg'))), $frete, $veiculo];
    }

    /** Fracionada vai sem carro (a transportadora diz qual consegue); as demais exigem o carro. */
    private function veiculoDaCotacao(array $d, bool $fracionada): ?string
    {
        if ($fracionada) {
            return null;
        }
        if (empty($d['tipo_veiculo_codigo'])) {
            throw ValidationException::withMessages(['tipo_veiculo_codigo' => ['Escolha o tipo de carro.']]);
        }

        return $d['tipo_veiculo_codigo'];
    }

    private function campos(array $d, bool $fracionada = false): array
    {
        return array_merge(
            [
                'tipo_veiculo_codigo' => $d['tipo_veiculo_codigo'] ?? null,
                'fracionada' => $fracionada,
                'tipo_carga_antt_codigo' => $d['tipo_carga_antt_codigo'] ?? null,
                'distancia_km' => $d['distancia_km'] ?? $this->antt->distancia(
                    $d['origem']['cidade'], $d['origem']['uf'], $d['destino']['cidade'], $d['destino']['uf'],
                ),
                'valor_nf' => $d['valor_nf'] ?? null,
                'observacao' => $d['observacao'] ?? null,
            ],
            Rota::colunas('origem', $d['origem']),
            Rota::colunas('destino', $d['destino']),
        );
    }

    /** Itens em forma comparável (modelo ou array), para o diff da auditoria. */
    private function resumoItens(array $itens): array
    {
        return array_map(fn ($i) => [
            'produto_id' => data_get($i, 'produto_id'),
            'embalagem_codigo' => data_get($i, 'embalagem_codigo'),
            'quantidade_kg' => (float) data_get($i, 'quantidade_kg'),
            'volumes' => data_get($i, 'volumes') === null ? null : (int) data_get($i, 'volumes'),
            'paletizado' => (bool) data_get($i, 'paletizado'),
            'remontado' => (bool) data_get($i, 'remontado'),
        ], $itens);
    }

    private function gravarItens(Cotacao $cotacao, array $itens): void
    {
        foreach ($itens as $item) {
            unset($item['cotacao_item_id']);
            $cotacao->itens()->create($item);
        }
    }
}