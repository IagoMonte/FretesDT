<?php

namespace App\Http\Controllers;

use App\Enums\StatusCotacao;
use App\Exceptions\RegraNegocioException;
use App\Http\Requests\CotacaoRequest;
use App\Http\Resources\CotacaoResource;
use App\Http\Resources\ProgramacaoResource;
use App\Models\Cotacao;
use App\Models\NegociacaoResposta;
use App\Rules\CentavoMinimo;
use App\Services\CotacaoAnteriorService;
use App\Services\CotacaoService;
use App\Support\Fmt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CotacaoController extends Controller
{
    public function __construct(private CotacaoService $service, private CotacaoAnteriorService $anterior) {}

    public function index(Request $request)
    {
        $q = Cotacao::visivelPara($this->usuario())->with(CotacaoService::RELACOES)
            ->orderByDesc('data_solicitacao')->orderByDesc('codigo');

        $carrier = $this->usuario()->ehTransportadora();
        $permitidos = [...array_column(StatusCotacao::cases(), 'value'), ...($carrier ? ['RESPONDIDA'] : [])];
        $status = $this->statusDoFiltro($request, $permitidos);
        if ($status && ! $carrier) {
            $q->whereIn('status', $status);
        }
        if ($codigo = trim((string) $request->query('codigo'))) {
            $q->where('codigo', $codigo);
        }
        if ($termo = trim((string) $request->query('termo'))) {
            $q->where(fn ($w) => $this->contem($w, 'codigo', $termo)
                ->orWhereHas('cliente', fn ($c) => $this->contem($this->contem($c, 'razao_social', $termo), 'nome_fantasia', $termo, true))
                ->orWhereHas('fornecedor', fn ($c) => $this->contem($this->contem($c, 'razao_social', $termo), 'nome_fantasia', $termo, true))
                ->orWhereHas('itens.produto', fn ($p) => $this->usuario()->ehTransportadora()
                    ? $this->contem($p, "COALESCE(NULLIF(TRIM(descricao), ''), nome)", $termo)
                    : $this->contem($p, 'nome', $termo)));
        }

        $cotacoes = $q->get();
        if ($carrier && ($pedidos = $status)) {
            $minha = (string) $this->usuario()->transportadora_id;
            $cotacoes = $cotacoes->filter(fn (Cotacao $c) => in_array($c->statusParaTransportadora($minha), $pedidos, true))->values();
        }

        return $this->lista(CotacaoResource::class, $cotacoes);
    }

    /** Prévia da cotação repetida (granel líquido) antes de salvar. Só usuários internos. */
    public function anterior(Request $request): JsonResponse
    {
        abort_if($this->usuario()->ehTransportadora(), 403, 'Sem permissão.');
        $d = $request->validate([
            'tipo_veiculo_codigo' => ['required', 'string'],
            'origem_cidade' => ['required', 'string', 'max:100'],
            'origem_uf' => ['required', 'string', 'size:2'],
            'destino_cidade' => ['required', 'string', 'max:100'],
            'destino_uf' => ['required', 'string', 'size:2'],
            'produto_ids' => ['required', 'string'],
        ]);
        $produtoIds = array_values(array_filter(array_map('trim', explode(',', $d['produto_ids']))));
        $anterior = $this->anterior->buscar($d['tipo_veiculo_codigo'], $d['origem_cidade'], $d['origem_uf'], $d['destino_cidade'], $d['destino_uf'], $produtoIds);

        return response()->json(['data' => $anterior ? CotacaoResource::anterior($anterior, $this->usuario()) : null, 'meta' => ['alertas' => []]]);
    }

    public function show(string $id): JsonResponse
    {
        return $this->um(CotacaoResource::make($this->buscar($id)));
    }

    /**
     * Idempotente: o mesmo envio repetido (duplo clique, reenvio, chamadas paralelas) devolve a cotação já criada.
     * Vale a chave `Idempotency-Key` do cliente; sem ela, o hash do corpo dentro de uma janela curta.
     */
    public function store(CotacaoRequest $request): JsonResponse
    {
        $usuario = $this->usuario();
        $chave = $request->header('Idempotency-Key');
        $marca = 'cotacao-store:'.$usuario->id.':'.hash('sha256', $chave ?: json_encode($request->validated()));
        $janela = $chave ? 600 : (int) config('frete.idempotencia_segundos');

        return Cache::lock($marca.':lock', 10)->block(5, function () use ($request, $usuario, $marca, $janela) {
            if (($id = Cache::get($marca)) && ($existente = Cotacao::visivelPara($usuario)->with(CotacaoService::RELACOES)->find($id))) {
                return $this->um(CotacaoResource::make($existente));
            }

            $cotacao = $this->service->criar($usuario, $request->validated());
            Cache::put($marca, $cotacao->id, $janela);

            $alertas = $cotacao->origem ? ["Cotação repetida: usando as respostas da {$cotacao->origem->codigo}."] : [];
            $alertas = [...$alertas, ...$this->alertasItensRepetidos($request->validated('itens'))];
            if ($igual = $this->service->duplicada($cotacao)) {
                $alertas[] = "Já existe uma cotação igual: {$igual->codigo}. Confira se não é duplicidade.";
            }

            return $this->um(CotacaoResource::make($cotacao), $alertas, 201);
        });
    }

    /** Mesmo produto na mesma embalagem em mais de um item: provável erro de digitação, avisa sem bloquear. */
    private function alertasItensRepetidos(array $itens): array
    {
        $vistos = [];
        $alertas = [];
        foreach ($itens as $i) {
            $chave = $i['produto_id'].'|'.$i['embalagem_codigo'];
            if (isset($vistos[$chave]) && ! isset($alertas[$chave])) {
                $alertas[$chave] = 'Item repetido: o mesmo produto e embalagem aparecem mais de uma vez. Confira se não é duplicidade.';
            }
            $vistos[$chave] = true;
        }

        return array_values($alertas);
    }

    public function update(CotacaoRequest $request, string $id): JsonResponse
    {
        return $this->um(CotacaoResource::make($this->service->atualizar($this->buscar($id), $request->validated())));
    }

    public function encaminhar(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['transportadora_ids' => ['required', 'array', 'min:1'], 'transportadora_ids.*' => ['required', 'string']]);

        return $this->um(CotacaoResource::make($this->service->encaminhar($this->buscar($id), $this->usuario(), $d['transportadora_ids'])));
    }

    public function responder(Request $request, string $id, string $respostaId): JsonResponse
    {
        $cotacao = $this->buscar($id);
        $d = $request->validate([
            ...$this->regrasPreco($cotacao->fracionada),
            'dedicado' => ['nullable', 'boolean'],
            'eixos' => ['nullable', 'integer', 'between:2,9'],
            'tipo_carga_antt_codigo' => ['nullable', 'string', 'exists:tipo_carga_antt,codigo'],
            'validade' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.Fmt::hoje(), 'before_or_equal:'.Fmt::hoje(config('frete.limites.validade_dias_max'))],
            'observacao' => ['nullable', 'string', 'max:2000'],
            'versao' => ['nullable', 'integer'],
        ], [
            'validade.date_format' => 'A validade deve estar no formato AAAA-MM-DD.',
            'validade.after_or_equal' => 'A validade não pode ser anterior a hoje.',
            'validade.before_or_equal' => 'A validade pode ser de no máximo '.config('frete.limites.validade_dias_max').' dias.',
        ]);
        $resposta = $this->resposta($cotacao, $respostaId, 'NEGOCIACAO.RESPONDER');

        return $this->um(CotacaoResource::make($this->service->responder($cotacao, $resposta, $this->usuario(), $d)));
    }

    public function declinar(Request $request, string $id, string $respostaId): JsonResponse
    {
        $d = $request->validate(['observacao' => ['nullable', 'string', 'max:2000'], 'versao' => ['nullable', 'integer']]);
        $cotacao = $this->buscar($id);
        $resposta = $this->resposta($cotacao, $respostaId, 'NEGOCIACAO.RESPONDER');

        return $this->um(CotacaoResource::make($this->service->declinar($cotacao, $resposta, $this->usuario(), $d['observacao'] ?? null, $d['versao'] ?? null)));
    }

    public function fechar(Request $request, string $id): JsonResponse
    {
        $data = ['nullable', 'date_format:Y-m-d'];
        $cotacaoFechar = $this->buscar($id);
        $d = $request->validate([
            'transportadora_id' => ['required', 'string'],
            'negociado_por_fora' => ['nullable', 'boolean'],
            ...$this->regrasPreco($cotacaoFechar->fracionada),
            'eixos' => ['nullable', 'integer', 'between:2,9'],
            'tipo_carga_antt_codigo' => ['nullable', 'string', 'exists:tipo_carga_antt,codigo'],
            'observacao' => ['nullable', 'string', 'max:4000'],
            'programacao' => ['required', 'array'],
            'programacao.pedido_venda' => ['required', 'string', 'max:30'],
            'programacao.deposito' => ['nullable', 'in:VIRTUAL,FISICO'],
            'programacao.modalidade_frete' => ['nullable', 'in:CIF,FOB'],
            'programacao.data_coleta_prevista' => [...$data, 'after_or_equal:2000-01-01', 'before_or_equal:'.Fmt::tetoData()],
            'programacao.data_entrega_prevista' => [...$data, 'after_or_equal:programacao.data_coleta_prevista', 'before_or_equal:'.Fmt::tetoData()],
        ]);
        [$cotacao, $programacao] = $this->service->fechar($cotacaoFechar, $this->usuario(), $d);
        $programacao->load(ProgramacaoResource::RELACOES);

        return $this->um(
            CotacaoResource::make($cotacao),
            meta: ['programacao' => ProgramacaoResource::make($programacao)->resolve($request)],
        );
    }

    /** Valor, carro e prazo da proposta/fechamento: positivos e dentro dos limites de config/frete.php. */
    private function regrasPreco(bool $coletaObrigatoria = false): array
    {
        $l = config('frete.limites');

        return [
            'valor_tonelada' => ['nullable', 'numeric', 'gt:0', new CentavoMinimo, 'max:'.$l['valor_tonelada_max']],
            'valor_frete' => ['nullable', 'numeric', 'gt:0', new CentavoMinimo, 'max:'.$l['valor_frete_max']],
            'tipo_veiculo_codigo' => ['nullable', 'string', 'exists:tipo_veiculo,codigo'],
            'prazo_dias' => ['required', 'integer', 'min:1', 'max:'.$l['prazo_dias_max']],
            'prazo_coleta_dias' => [$coletaObrigatoria ? 'required' : 'nullable', 'integer', 'min:0', 'max:'.$l['prazo_coleta_dias_max']],
        ];
    }

    public function cancelar(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'min:3', 'max:500']], ['motivo.required' => 'Informe o motivo do cancelamento.']);

        return $this->um(CotacaoResource::make($this->service->cancelar($this->buscar($id), trim($d['motivo']))));
    }

    public function encerrar(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['motivo' => ['nullable', 'string', 'max:500']]);
        $motivo = trim((string) ($d['motivo'] ?? ''));

        return $this->um(CotacaoResource::make($this->service->encerrar($this->buscar($id), $motivo === '' ? null : $motivo)));
    }

    /** Fora do escopo do usuário = 404. */
    private function buscar(string $id): Cotacao
    {
        return Cotacao::visivelPara($this->usuario())->with(CotacaoService::RELACOES)->findOrFail($id);
    }

    /**
     * A transportadora só mexe na própria resposta (a dos outros é 404); quem lança em nome
     * de outra transportadora precisa da ação NEGOCIACAO.LANCAR_RESPOSTA.
     */
    private function resposta(Cotacao $cotacao, string $respostaId, string $acaoPropria): NegociacaoResposta
    {
        $u = $this->usuario();
        $resposta = $cotacao->negociacao->respostas->firstWhere('id', $respostaId);
        if ($resposta === null) {
            $origem = $cotacao->origemValida();
            $referenciada = $origem?->negociacao?->respostas->firstWhere('id', $respostaId);
            $restrito = $u->escopo('NEGOCIACAO') === 'TRANSPORTADORA';
            abort_if($referenciada === null || ($restrito && $referenciada->transportadora_id !== $u->transportadora_id), 404);
            throw new RegraNegocioException($restrito
                ? 'Esta proposta deve ser respondida na cotação original.'
                : "Esta resposta pertence à cotação {$origem->codigo}: responda nela.");
        }

        $propria = $u->transportadora_id !== null && $u->transportadora_id === $resposta->transportadora_id;
        if (! $propria && $u->escopo('NEGOCIACAO') === 'TRANSPORTADORA') {
            abort(404);
        }
        abort_unless($u->pode($propria ? $acaoPropria : 'NEGOCIACAO.LANCAR_RESPOSTA'), 403);

        return $resposta;
    }
}