<?php

namespace App\Http\Controllers;

use App\Enums\StatusProgramacao;
use App\Http\Requests\ProgramacaoRequest;
use App\Http\Resources\ProgramacaoResource;
use App\Models\Programacao;
use App\Services\ProgramacaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProgramacaoController extends Controller
{
    public function __construct(private ProgramacaoService $service) {}

    public function index(Request $request)
    {
        $q = Programacao::visivelPara($this->usuario())->with(ProgramacaoResource::RELACOES)
            ->orderByDesc('data_pedido')->orderByDesc('codigo');

        if ($status = $this->statusDoFiltro($request, array_column(StatusProgramacao::cases(), 'value'))) {
            $q->whereIn('status', $status);
        }
        if ($codigo = trim((string) $request->query('codigo'))) {
            $q->where('codigo', $codigo);
        }
        if ($termo = trim((string) $request->query('termo'))) {
            $q->where(fn ($w) => $this->contem($w, 'codigo', $termo)
                ->orWhereRaw("pedido_venda like ? escape '!'", [$this->like($termo)])
                ->orWhereRaw("nfe_numero like ? escape '!'", [$this->like($termo)])
                ->orWhereHas('cliente', fn ($c) => $this->contem($this->contem($c, 'razao_social', $termo), 'nome_fantasia', $termo, true))
                ->orWhereHas('fornecedor', fn ($c) => $this->contem($this->contem($c, 'razao_social', $termo), 'nome_fantasia', $termo, true)));
        }

        return $this->lista(ProgramacaoResource::class, $q->get());
    }

    public function show(string $id): JsonResponse
    {
        return $this->um(ProgramacaoResource::make($this->buscar($id)));
    }

    public function store(ProgramacaoRequest $request): JsonResponse
    {
        $p = $this->service->criar($this->usuario(), $request->validated());

        return $this->resposta($p, 201, true);
    }

    public function update(ProgramacaoRequest $request, string $id): JsonResponse
    {
        $programacao = $this->buscar($id);
        abort_unless($this->usuario()->pode('PROGRAMACAO.alterar'), 403);
        $p = $this->service->atualizar($programacao, $request->validated());

        return $this->resposta($p, 200, true);
    }

    public function status(Request $request, string $id): JsonResponse
    {
        $d = $request->validate([
            'status' => ['required', Rule::enum(StatusProgramacao::class)],
            'data' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $para = StatusProgramacao::from($d['status']);
        $u = $this->usuario();
        abort_if($para === StatusProgramacao::Entregue && ! $u->pode('PROGRAMACAO.CONFIRMAR_ENTREGA'), 403);
        abort_if($para === StatusProgramacao::Cancelado && ! $u->pode('PROGRAMACAO.cancelar'), 403);
        abort_if($para === StatusProgramacao::Devolvido && ! $u->pode('PROGRAMACAO.alterar'), 403);

        $p = $this->service->mudarStatus($this->buscar($id), $para, $d['data'] ?? null);

        return $this->resposta($p);
    }

    public function entregar(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['data_entrega_realizada' => ['nullable', 'date_format:Y-m-d']]);
        $p = $this->service->mudarStatus($this->buscar($id), StatusProgramacao::Entregue, $d['data_entrega_realizada'] ?? null, 'data_entrega_realizada');

        return $this->resposta($p);
    }

    public function cancelar(Request $request, string $id): JsonResponse
    {
        $d = $request->validate(['motivo' => ['nullable', 'string', 'max:500']]);

        return $this->resposta($this->service->cancelar($this->buscar($id), $d['motivo'] ?? null));
    }

    private function buscar(string $id): Programacao
    {
        return Programacao::visivelPara($this->usuario())->with(ProgramacaoResource::RELACOES)->findOrFail($id);
    }

    /** Alertas de quantidade só fazem sentido ao gravar itens (criar/editar). */
    private function resposta(Programacao $p, int $status = 200, bool $alertar = false): JsonResponse
    {
        $p->refresh()->load(ProgramacaoResource::RELACOES);

        return $this->um(ProgramacaoResource::make($p), $alertar ? $this->service->alertas($p) : [], $status);
    }
}