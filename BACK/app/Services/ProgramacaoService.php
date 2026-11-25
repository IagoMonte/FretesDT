<?php

namespace App\Services;

use App\Enums\Operacao;
use App\Enums\StatusCotacao;
use App\Enums\StatusNegociacao;
use App\Enums\StatusProgramacao;
use App\Exceptions\RegraNegocioException;
use App\Models\Cotacao;
use App\Models\CotacaoItem;
use App\Models\Negociacao;
use App\Models\Programacao;
use App\Models\Transportadora;
use App\Models\Usuario;
use App\Services\Notificacao\NotificacaoService;
use App\Support\Dinheiro;
use App\Support\Fmt;
use App\Support\MensagemFrete;
use App\Support\Rota;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProgramacaoService
{
    private const CAMPOS = [
        'data_pedido', 'pedido_venda', 'nfe_numero', 'nfe_serie', 'nfe_chave', 'representante_id', 'deposito',
        'modalidade_frete', 'transportadora_id', 'tipo_veiculo_codigo', 'valor_tonelada', 'prazo_dias', 'prazo_coleta_dias',
        'data_coleta_prevista', 'data_coleta_realizada', 'data_entrega_prevista', 'data_entrega_realizada', 'observacao',
    ];

    public function __construct(
        private ItensService $itens,
        private SequenciaService $sequencia,
        private LogService $log,
        private NotificacaoService $notificar,
    ) {}

    /** Carga avulsa (sem cotação) ou com itens ligados a uma cotação via cotacao_item_id. */
    public function criar(Usuario $usuario, array $d): Programacao
    {
        return DB::transaction(function () use ($usuario, $d) {
            $operacao = Operacao::from($d['operacao']);
            $parte = $this->itens->parte($operacao, $d['parte']);
            $itens = $this->itens->itens($d['itens']);
            $this->validarTransportadora($d['transportadora_id'] ?? null);

            $status = StatusProgramacao::from($d['status'] ?? StatusProgramacao::AguardandoCarregamento->value);
            if (! in_array($status, [StatusProgramacao::EmCotacao, StatusProgramacao::AguardandoCarregamento], true)) {
                throw new RegraNegocioException('Uma carga nova só pode nascer em cotação ou aguardando carregamento.');
            }

            $programacao = Programacao::create(array_merge(
                ['data_pedido' => Fmt::hoje(), 'representante_id' => $usuario->id, 'deposito' => 'VIRTUAL', 'modalidade_frete' => 'CIF'],
                array_filter(Arr::only($d, self::CAMPOS), fn ($v, $k) => $v !== null || ! in_array($k, ['data_pedido', 'representante_id', 'deposito', 'modalidade_frete'], true), ARRAY_FILTER_USE_BOTH),
                Rota::colunas('origem', $d['origem'] ?? null),
                Rota::colunas('destino', $d['destino'] ?? null),
                $this->itens->colunasParte($operacao, $parte),
                [
                    'codigo' => $this->sequencia->proximo('PROGRAMACAO', 'CRG-'),
                    'cotacao_id' => $this->cotacaoDosItens($itens),
                    'operacao' => $operacao,
                    'status' => $status,
                    'valor_total' => $this->total($d['valor_tonelada'] ?? null, array_column($itens, 'quantidade_kg')),
                ],
            ));
            $this->gravarItens($programacao, $itens);
            $this->log->operacao('PROGRAMACAO', $programacao, 'PROGRAMACAO_CRIADA', null, $status->value, [
                'transportadora' => $programacao->transportadora?->nome_fantasia,
                'valor_tonelada' => $programacao->valor_tonelada,
            ]);
            $this->registrarDivergencia($programacao);
            $this->avisarCriada($programacao);

            return $programacao;
        });
    }

    /**
     * Carga criada sem cotação não passa pelo fechamento (NEGOCIACAO_FECHADA), então o aviso à transportadora sai daqui (programar_pedido).
     * Sem transportadora não há para quem avisar; carga ligada a cotação segue o fluxo da cotação.
     */
    private function avisarCriada(Programacao $programacao): void
    {
        if ($programacao->cotacao_id || ! $programacao->transportadora_id) {
            return;
        }
        $this->notificar->disparar('PROGRAMACAO_CRIADA', 'PROGRAMACAO', $programacao, [
            'transportadora_ids' => [$programacao->transportadora_id],
            'pedido' => $programacao->pedido_venda,
            'programacao' => $programacao->codigo,
            'valor' => MensagemFrete::valorDaCarga($programacao),
            'prazo_dias' => $programacao->prazo_dias,
            'prazo_coleta_dias' => $programacao->prazo_coleta_dias,
            ...MensagemFrete::pedido($programacao),
        ]);
    }

    /** Programação gerada no fechamento da negociação (copia cotação + fechamento: R8). */
    public function criarDeFechamento(Cotacao $cotacao, Negociacao $negociacao, array $opcoes, Usuario $usuario): Programacao
    {
        $programacao = Programacao::create([
            'codigo' => $this->sequencia->proximo('PROGRAMACAO', 'CRG-'),
            'cotacao_id' => $cotacao->id,
            'operacao' => $cotacao->operacao,
            'status' => StatusProgramacao::AguardandoCarregamento,
            'data_pedido' => Fmt::hoje(),
            'representante_id' => $cotacao->vendedor_id,
            'cliente_id' => $cotacao->cliente_id,
            'fornecedor_id' => $cotacao->fornecedor_id,
            'pedido_venda' => $opcoes['pedido_venda'] ?? null,
            'deposito' => $opcoes['deposito'] ?? 'VIRTUAL',
            'modalidade_frete' => $opcoes['modalidade_frete'] ?? 'CIF',
            'transportadora_id' => $negociacao->transportadora_id,
            'tipo_veiculo_codigo' => $cotacao->tipo_veiculo_codigo ?? $negociacao->tipo_veiculo_codigo,
            'origem_ibge' => $cotacao->origem_ibge, 'origem_cidade' => $cotacao->origem_cidade, 'origem_uf' => $cotacao->origem_uf,
            'destino_ibge' => $cotacao->destino_ibge, 'destino_cidade' => $cotacao->destino_cidade, 'destino_uf' => $cotacao->destino_uf,
            'valor_tonelada' => $negociacao->valor_tonelada,
            'prazo_dias' => $negociacao->prazo_dias,
            'prazo_coleta_dias' => $negociacao->prazo_coleta_dias,
            'data_coleta_prevista' => $opcoes['data_coleta_prevista'] ?? null,
            'data_entrega_prevista' => $opcoes['data_entrega_prevista'] ?? null,
            'valor_total' => $cotacao->fracionada ? $negociacao->valor_frete : $this->total($negociacao->valor_tonelada, $cotacao->itens->pluck('quantidade_kg')),
        ]);
        foreach ($cotacao->itens as $item) {
            $programacao->itens()->create([
                'sequencia' => $item->sequencia,
                'cotacao_item_id' => $item->id,
                'produto_id' => $item->produto_id,
                'embalagem_codigo' => $item->embalagem_codigo,
                'quantidade_kg' => $item->quantidade_kg,
                'volumes' => $item->volumes,
            ]);
        }
        $this->log->operacao('PROGRAMACAO', $programacao, 'PROGRAMACAO_CRIADA', null, StatusProgramacao::AguardandoCarregamento->value, [
            'cotacao' => $cotacao->codigo,
            'transportadora' => $negociacao->transportadora?->nome_fantasia,
        ]);

        return $programacao;
    }

    public function atualizar(Programacao $programacao, array $d): Programacao
    {
        return DB::transaction(function () use ($programacao, $d) {
            $programacao->exigirVersao($d['versao']);
            if ($programacao->status->final()) {
                throw new RegraNegocioException('Programação em status final não pode ser alterada.');
            }
            if (isset($d['transportadora_id'])) {
                $this->validarTransportadora($d['transportadora_id']);
            }

            $campos = Arr::only($d, self::CAMPOS);
            $campos += Rota::colunas('origem', $d['origem'] ?? null) + Rota::colunas('destino', $d['destino'] ?? null);

            if (isset($d['parte']) || isset($d['operacao'])) {
                $operacao = Operacao::from($d['operacao'] ?? $programacao->operacao->value);
                $parte = $this->itens->parte($operacao, $d['parte'] ?? ['tipo' => $operacao->tipoParte(), 'id' => $programacao->cliente_id ?? $programacao->fornecedor_id]);
                $campos += $this->itens->colunasParte($operacao, $parte) + ['operacao' => $operacao];
            }
            $programacao->fill($campos);

            if (isset($d['itens'])) {
                $itens = $this->itens->itens($d['itens']);
                $programacao->itens()->reorder()->delete();
                $this->gravarItens($programacao, $itens);
                if ($cotacaoId = $this->cotacaoDosItens($itens)) {
                    $programacao->cotacao_id = $cotacaoId;
                }
            }
            $kgs = isset($itens) ? array_column($itens, 'quantidade_kg') : $programacao->itens()->pluck('quantidade_kg');
            $programacao->valor_total = $this->total($programacao->valor_tonelada, $kgs);
            $programacao->tocar();

            $this->log->operacao('PROGRAMACAO', $programacao, 'PROGRAMACAO_EDITADA', null, null, Arr::only($campos, ['transportadora_id', 'valor_tonelada', 'prazo_dias', 'prazo_coleta_dias']));
            $this->registrarDivergencia($programacao);

            return $programacao;
        });
    }

    public function mudarStatus(Programacao $programacao, StatusProgramacao $para, ?string $data = null, string $campoData = 'data'): Programacao
    {
        return DB::transaction(function () use ($programacao, $para, $data, $campoData) {
            $de = $programacao->status;
            $de->exigirTransicao($para);
            if ($data !== null && $para !== StatusProgramacao::Entregue) {
                $this->validarDataRealizada($programacao, $para, $data, $campoData);
            }
            $programacao->status = $para;
            if ($para === StatusProgramacao::EmTransito) {
                $programacao->data_coleta_realizada = $data ?? Fmt::hoje();
            }
            if ($para === StatusProgramacao::Entregue) {
                $programacao->entregue_em = now();
                $programacao->data_entrega_realizada = Fmt::hoje();
            }
            $programacao->save();

            $evento = $para === StatusProgramacao::Entregue ? 'PROGRAMACAO_ENTREGUE' : 'PROGRAMACAO_STATUS';
            $this->log->operacao('PROGRAMACAO', $programacao, $evento, $de->value, $para->value);
            if (in_array($para, [StatusProgramacao::EmTransito, StatusProgramacao::Entregue, StatusProgramacao::Devolvido], true)) {
                $dia = $para === StatusProgramacao::Entregue ? $programacao->data_entrega_realizada : $programacao->data_coleta_realizada;
                $this->notificar->disparar('PROGRAMACAO_STATUS', 'PROGRAMACAO', $programacao, [
                    'status' => $para->value,
                    'data' => $dia ? \Carbon\Carbon::parse($dia)->format('d/m/Y') : Fmt::hoje(),
                    'chave' => $para->value,
                ]);
            }

            return $programacao;
        });
    }

    /**
     * Cancelou a última carga ativa de uma cotação PROGRAMADA: a cotação volta a EM_NEGOCIACAO e a negociação reabre
     * (sem o fechamento), para refechar ou cancelar a cotação. Com outra carga ativa, nada muda.
     */
    private function reabrirCotacaoSemCarga(Programacao $programacao): void
    {
        $cotacao = $programacao->cotacao;
        if (! $cotacao || $cotacao->status !== StatusCotacao::Programada) {
            return;
        }
        $ativas = Programacao::where('cotacao_id', $cotacao->id)->where('status', '<>', StatusProgramacao::Cancelado->value)->exists();
        $negociacao = $cotacao->negociacao;
        if ($ativas || ! $negociacao) {
            return;
        }
        $negociacao->forceFill([
            'status' => StatusNegociacao::Aberta,
            'transportadora_id' => null, 'valor_tonelada' => null, 'valor_frete' => null, 'tipo_veiculo_codigo' => null, 'prazo_dias' => null, 'prazo_coleta_dias' => null,
            'observacao_fechamento' => null, 'fechado_em' => null, 'fechado_por_id' => null,
            'dedicado' => false, 'eixos' => null, 'tipo_carga_antt_codigo' => null, 'piso_antt_valor' => null, 'piso_antt_km' => null, 'piso_antt_coef_id' => null,
        ])->save();
        $cotacao->status->exigirTransicao(StatusCotacao::EmNegociacao);
        $cotacao->status = StatusCotacao::EmNegociacao;
        $cotacao->save();
        $this->log->operacao('NEGOCIACAO', $cotacao, 'NEGOCIACAO_REABERTA', 'FECHADA', 'ABERTA');
        $this->log->operacao('COTACAO', $cotacao, 'COTACAO_STATUS', 'PROGRAMADA', 'EM_NEGOCIACAO');
    }

    /** Coleta e entrega realizadas já aconteceram: nunca no futuro, e a entrega não vem antes da coleta. */
    private function validarDataRealizada(Programacao $programacao, StatusProgramacao $para, string $data, string $campo): void
    {
        if ($data > Fmt::hoje()) {
            throw ValidationException::withMessages([$campo => ['A data não pode ser futura.']]);
        }
        $coleta = Fmt::data($programacao->data_coleta_realizada);
        if ($para === StatusProgramacao::Entregue && $coleta !== null && $data < $coleta) {
            throw ValidationException::withMessages([$campo => ['A entrega não pode ser anterior à coleta ('.\Carbon\Carbon::parse($coleta)->format('d/m/Y').').']]);
        }
    }

    public function cancelar(Programacao $programacao, ?string $motivo): Programacao
    {
        return DB::transaction(function () use ($programacao, $motivo) {
            $de = $programacao->status;
            $de->exigirTransicao(StatusProgramacao::Cancelado);
            $programacao->status = StatusProgramacao::Cancelado;
            $programacao->save();
            $this->log->operacao('PROGRAMACAO', $programacao, 'PROGRAMACAO_STATUS', $de->value, 'CANCELADO', array_filter(['motivo' => $motivo]));
            $this->notificar->disparar('PROGRAMACAO_CANCELADA', 'PROGRAMACAO', $programacao, array_filter(['motivo' => $motivo]));
            $this->reabrirCotacaoSemCarga($programacao);

            return $programacao;
        });
    }

    /**
     * Quantidade programada (cargas não canceladas) acima da cotada: avisa, não bloqueia.
     *
     * @return array<int, string>
     */
    public function alertas(Programacao $programacao): array
    {
        $alertas = [];
        $ids = $programacao->itens()->whereNotNull('cotacao_item_id')->pluck('cotacao_item_id')->unique();
        foreach ($ids as $cotacaoItemId) {
            $cotado = CotacaoItem::find($cotacaoItemId);
            $programado = Dinheiro::soma(
                DB::table('programacao_item')
                    ->join('programacao', 'programacao.id', '=', 'programacao_item.programacao_id')
                    ->where('programacao_item.cotacao_item_id', $cotacaoItemId)
                    ->where('programacao.status', '<>', StatusProgramacao::Cancelado->value)
                    ->pluck('programacao_item.quantidade_kg'),
            );
            if ($cotado && Dinheiro::maior($programado, $cotado->quantidade_kg)) {
                $codigo = $programacao->cotacao?->codigo ?? Cotacao::find($cotado->cotacao_id)?->codigo;
                $alertas[] = "Quantidade programada ({$programado} kg) excede a cotada ({$cotado->quantidade_kg} kg) no item {$cotado->sequencia} da cotação {$codigo}.";
            }
        }
        if ($alertas) {
            $this->log->operacao('PROGRAMACAO', $programacao, 'PROGRAMACAO_QTD_EXCEDIDA', null, null, ['avisos' => $alertas]);
            $this->notificar->disparar('PROGRAMACAO_QTD_EXCEDIDA', 'PROGRAMACAO', $programacao, ['avisos' => $alertas, 'chave' => md5(implode('|', $alertas))]);
        }

        return $alertas;
    }

    private function gravarItens(Programacao $programacao, array $itens): void
    {
        foreach ($itens as $item) {
            unset($item['paletizado'], $item['remontado'], $item['paletes']);
            $programacao->itens()->create($item);
        }
    }

    /** valor_total = valor_tonelada × Σkg ÷ 1000, HALF_UP (nunca float). */
    private function total(string|int|float|null $valorTonelada, iterable $kgs): ?string
    {
        return $valorTonelada === null ? null : Dinheiro::frete(Dinheiro::dec($valorTonelada, 2), Dinheiro::soma($kgs));
    }

    private function validarTransportadora(?string $id): void
    {
        if ($id !== null && ! Transportadora::where('id', $id)->where('ativo', true)->exists()) {
            throw ValidationException::withMessages(['transportadora_id' => ['Transportadora não encontrada ou inativa.']]);
        }
    }

    /** Os itens ligados a cotacao_item_id precisam ser da mesma cotação; ela vira o cotacao_id da carga. */
    private function cotacaoDosItens(array $itens): ?string
    {
        $ids = collect($itens)->pluck('cotacao_item_id')->filter()->unique();
        if ($ids->isEmpty()) {
            return null;
        }
        $cotacoes = CotacaoItem::whereIn('id', $ids)->pluck('cotacao_id')->unique();
        if ($cotacoes->count() !== 1 || CotacaoItem::whereIn('id', $ids)->count() !== $ids->count()) {
            throw ValidationException::withMessages(['itens' => ['Os itens ligados à cotação precisam ser da mesma cotação.']]);
        }

        return $cotacoes->first();
    }

    /** Transportadora/preço diferentes do fechamento: permitido, mas registrado. */
    private function registrarDivergencia(Programacao $programacao): void
    {
        $negociacao = $programacao->cotacao_id ? Negociacao::where('cotacao_id', $programacao->cotacao_id)->first() : null;
        if (! $negociacao || $negociacao->status !== StatusNegociacao::Fechada) {
            return;
        }
        $mudouTransp = $programacao->transportadora_id !== $negociacao->transportadora_id;
        $mudouPreco = $programacao->valor_tonelada !== $negociacao->valor_tonelada;
        if ($mudouTransp || $mudouPreco) {
            $this->log->operacao('PROGRAMACAO', $programacao, 'PROGRAMACAO_DIVERGE_FECHAMENTO', null, null, [
                'transportadora' => [$negociacao->transportadora_id, $programacao->transportadora_id],
                'valor_tonelada' => [$negociacao->valor_tonelada, $programacao->valor_tonelada],
            ]);
            $this->notificar->disparar('PROGRAMACAO_DIVERGE_FECHAMENTO', 'PROGRAMACAO', $programacao, [
                'avisos' => array_values(array_filter([
                    $mudouTransp ? 'Transportadora diferente da negociada no fechamento.' : null,
                    $mudouPreco ? "Valor por tonelada diferente do fechamento ({$negociacao->valor_tonelada} → {$programacao->valor_tonelada})." : null,
                ])),
                'chave' => md5($programacao->transportadora_id.'|'.$programacao->valor_tonelada),
            ]);
        }
    }
}