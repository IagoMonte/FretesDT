<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProdutoResource;
use App\Models\Embalagem;
use App\Models\Produto;
use App\Services\LogService;
use App\Support\Fmt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProdutoController extends Controller
{
    public function __construct(private LogService $log) {}

    public function index(Request $request)
    {
        $q = Produto::with('embalagens')->withCount('codigosErp')->orderBy('nome');
        $this->filtroAtivo($q, $request->query('ativo'));
        if ($termo = $request->query('termo')) {
            $this->contem($q, 'nome', trim($termo));
        }

        return $this->lista(ProdutoResource::class, $q->get());
    }

    public function show(string $id): JsonResponse
    {
        return $this->um(ProdutoResource::make(Produto::with('embalagens')->withCount('codigosErp')->findOrFail($id)));
    }

    public function store(Request $request): JsonResponse
    {
        $d = $this->validar($request);
        $p = DB::transaction(function () use ($d) {
            $p = Produto::create(Arr::except($d, ['embalagens', 'fatores', 'fatores_palete', 'versao']) + ['origem_cadastro' => 'MANUAL', 'ativo' => true]);
            $p->embalagens()->sync($this->pivot($d['embalagens'], $d['fatores'] ?? [], $d['fatores_palete'] ?? []));
            $this->log->operacao('PRODUTO', $p, 'CADASTRO_CRIADO', null, null, ['nome' => $p->nome, 'embalagens' => $d['embalagens'], 'fatores' => $d['fatores'] ?? [], 'fatores_palete' => $d['fatores_palete'] ?? []]);

            return $p;
        });

        return $this->um(ProdutoResource::make($p->load('embalagens')), [], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $p = Produto::findOrFail($id);
        $d = $this->validar($request, false);

        DB::transaction(function () use ($p, $d) {
            $p->exigirVersao($d['versao']);
            $p->fill(Arr::except($d, ['embalagens', 'fatores', 'fatores_palete', 'versao']));
            $fatores = $d['fatores'] ?? null;
            $palete = $d['fatores_palete'] ?? null;
            if (isset($d['embalagens'])) {
                $atuais = $p->embalagens()->get();
                $fatores ??= $atuais->filter(fn ($e) => $e->pivot->kg_por_volume !== null)->mapWithKeys(fn ($e) => [$e->codigo => $e->pivot->kg_por_volume])->all();
                $palete ??= $atuais->filter(fn ($e) => $e->pivot->kg_por_palete !== null)->mapWithKeys(fn ($e) => [$e->codigo => $e->pivot->kg_por_palete])->all();
                $p->embalagens()->sync($this->pivot($d['embalagens'], $fatores, $palete));
            } elseif ($fatores !== null || $palete !== null) {
                $atuais = $p->embalagens()->pluck('codigo')->all();
                foreach (['fatores' => $fatores, 'fatores_palete' => $palete] as $campo => $mapa) {
                    if ($mapa !== null && array_diff(array_keys($mapa), $atuais)) {
                        throw ValidationException::withMessages([$campo => 'Fator para embalagem que não pertence ao produto.']);
                    }
                }
                foreach ($fatores ?? [] as $codigo => $fator) {
                    $p->embalagens()->updateExistingPivot($codigo, ['kg_por_volume' => Embalagem::whereKey($codigo)->where('granel', true)->exists() ? null : $this->fatorValido($fator)]);
                }
                foreach ($palete ?? [] as $codigo => $fator) {
                    $p->embalagens()->updateExistingPivot($codigo, ['kg_por_palete' => $this->fatorPalete(Embalagem::find($codigo), $fator)]);
                }
            }
            $p->tocar();
            $this->log->operacao('PRODUTO', $p, 'CADASTRO_EDITADO', null, null, Arr::only($d, ['nome', 'embalagens', 'fatores', 'fatores_palete']));
        });

        return $this->um(ProdutoResource::make($p->refresh()->load('embalagens')));
    }

    public function inativar(string $id): JsonResponse
    {
        return $this->alterarAtivo($id, false);
    }

    public function ativar(string $id): JsonResponse
    {
        return $this->alterarAtivo($id, true);
    }

    private function alterarAtivo(string $id, bool $ativo): JsonResponse
    {
        $p = DB::transaction(function () use ($id, $ativo) {
            $p = Produto::findOrFail($id);
            $p->ativo = $ativo;
            $p->save();
            $this->log->operacao('PRODUTO', $p, $ativo ? 'CADASTRO_REATIVADO' : 'CADASTRO_INATIVADO');

            return $p;
        });

        return $this->um(ProdutoResource::make($p->refresh()->load('embalagens')));
    }

    private function validar(Request $request, bool $novo = true): array
    {
        if ($request->has('nome')) {
            $request->merge(['nome' => Fmt::seTexto($request->input('nome'), fn (string $t) => mb_strtoupper(trim($t)))]);
        }
        foreach (['descricao', 'onu', 'risco'] as $campo) {
            if ($request->has($campo)) {
                $request->merge([$campo => Fmt::seTexto($request->input($campo), function (string $t) use ($campo) {
                    $v = trim($t);

                    return $v === '' ? null : ($campo === 'risco' ? mb_strtoupper($v) : $v);
                })]);
            }
        }
        $obrig = $novo ? 'required' : 'sometimes';

        $d = $request->validate([
            'nome' => [$obrig, 'string', 'min:3', 'max:150'],
            'descricao' => ['nullable', 'string', 'max:255'],
            'onu' => ['nullable', 'digits:4'],
            'risco' => ['nullable', 'string', 'max:10'],
            'densidade' => ['nullable', 'numeric', 'gt:0', 'lt:1000'],
            'embalagens' => [$obrig, 'array', 'min:1'],
            'embalagens.*' => ['string', 'distinct', 'exists:embalagem,codigo'],
            'fatores' => ['sometimes', 'nullable', 'array'],
            'fatores.*' => ['nullable', 'numeric', 'gt:0', 'lt:1000000000000'],
            'fatores_palete' => ['sometimes', 'nullable', 'array'],
            'fatores_palete.*' => ['nullable', 'numeric', 'gt:0', 'lt:1000000000000'],
            'versao' => [$novo ? 'nullable' : 'required', 'integer'],
        ]);
        $d['fatores'] = $d['fatores'] ?? ($request->has('fatores') ? [] : null);
        if ($d['fatores'] === null) {
            unset($d['fatores']);
        }
        $d['fatores_palete'] = $d['fatores_palete'] ?? ($request->has('fatores_palete') ? [] : null);
        if ($d['fatores_palete'] === null) {
            unset($d['fatores_palete']);
        }
        foreach (['fatores', 'fatores_palete'] as $campo) {
            if (isset($d[$campo], $d['embalagens']) && array_diff(array_keys($d[$campo]), $d['embalagens'])) {
                throw ValidationException::withMessages([$campo => 'Fator para embalagem que não está em embalagens.']);
            }
        }

        return $d;
    }

    /** Monta o sync: kg_por_volume por embalagem (granel nunca tem) e kg_por_palete só nas fracionadas. */
    private function pivot(array $embalagens, array $fatores, array $palete = []): array
    {
        $r = [];
        $modelos = Embalagem::whereIn('codigo', $embalagens)->get()->keyBy('codigo');
        foreach ($embalagens as $codigo) {
            $e = $modelos[$codigo] ?? null;
            $r[$codigo] = [
                'kg_por_volume' => ! $e || $e->granel ? null : $this->fatorValido($fatores[$codigo] ?? null),
                'kg_por_palete' => $this->fatorPalete($e, $palete[$codigo] ?? null),
            ];
        }

        return $r;
    }

    private function fatorPalete(?Embalagem $e, mixed $fator): mixed
    {
        return $e && $e->fracionada && ! $e->granel ? $this->fatorValido($fator) : null;
    }

    private function fatorValido(mixed $fator): mixed
    {
        return $fator === null || $fator === '' ? null : $fator;
    }
}
