<?php

namespace App\Http\Controllers;

use App\Http\Resources\ParteResource;
use App\Models\Cliente;
use App\Models\Fornecedor;
use App\Models\Parte;
use App\Rules\CnpjValido;
use App\Services\LogService;
use App\Support\Fmt;
use App\Support\Normaliza;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Clientes e fornecedores (cadastro local provisório) e a busca unificada /partes. */
class ParteController extends Controller
{
    public function __construct(private LogService $log) {}

    public function index(Request $request)
    {
        $q = Parte::query()->where('ativo', true)->orderBy('razao_social')->limit(20);
        if ($request->boolean('revisao_pendente')) {
            $q->where('revisao_pendente', true);
        }
        if (in_array($request->query('tipo'), ['CLIENTE', 'FORNECEDOR'], true)) {
            $q->where('tipo', $request->query('tipo'));
        }
        if ($termo = trim((string) $request->query('termo'))) {
            $digitos = preg_replace('/\D+/', '', $termo);
            $q->where(function ($w) use ($termo, $digitos) {
                $this->contem($this->contem($w, 'razao_social', $termo), 'nome_fantasia', $termo, true);
                if ($digitos !== '') {
                    $w->orWhere('cnpj', 'like', "%{$digitos}%");
                }
            });
        }

        return $this->lista(ParteResource::class, $q->get());
    }

    public function storeCliente(Request $request): JsonResponse
    {
        return $this->salvar($request, Cliente::class);
    }

    public function storeFornecedor(Request $request): JsonResponse
    {
        return $this->salvar($request, Fornecedor::class);
    }

    public function updateCliente(Request $request, string $id): JsonResponse
    {
        return $this->atualizar($request, Cliente::class, $id);
    }

    public function updateFornecedor(Request $request, string $id): JsonResponse
    {
        return $this->atualizar($request, Fornecedor::class, $id);
    }

    /** @param  class-string<Model>  $classe */
    private function salvar(Request $request, string $classe): JsonResponse
    {
        $d = $this->validar($request);
        $existente = $classe::where('cnpj', $d['cnpj'])->where('ativo', true)->first();
        if ($existente) {
            throw new HttpResponseException(response()->json([
                'message' => 'Já existe um '.strtolower(class_basename($classe))." ativo com este CNPJ: {$existente->razao_social}.",
                'existente' => ParteResource::make($existente)->resolve(),
            ], 409));
        }

        $parte = DB::transaction(function () use ($classe, $d) {
            $p = $classe::create(Arr::except($d, ['versao']) + ['origem_cadastro' => 'MANUAL', 'ativo' => true]);
            $this->log->operacao(strtoupper(class_basename($classe)), $p, 'CADASTRO_CRIADO', null, null, ['razao_social' => $p->razao_social]);

            return $p;
        });

        return $this->um(ParteResource::make($parte), [], 201);
    }

    /** @param  class-string<Model>  $classe */
    private function atualizar(Request $request, string $classe, string $id): JsonResponse
    {
        $p = $classe::findOrFail($id);
        $d = $this->validar($request, false, $p);

        DB::transaction(function () use ($p, $d, $classe) {
            $p->exigirVersao($d['versao']);
            $p->fill(Arr::except($d, ['versao']));
            if ($p->cnpj) {
                $p->revisao_pendente = false;
            }
            $p->tocar();
            $this->log->operacao(strtoupper(class_basename($classe)), $p, 'CADASTRO_EDITADO', null, null, ['razao_social' => $p->razao_social]);
        });

        return $this->um(ParteResource::make($p->refresh()));
    }

    private function validar(Request $request, bool $novo = true, ?Model $atual = null): array
    {
        if ($request->has('cnpj')) {
            $request->merge(['cnpj' => Fmt::cnpj($request->input('cnpj'))]);
        }
        $obrig = $novo ? 'required' : 'sometimes';

        return $request->validate([
            'razao_social' => [$obrig, 'string', 'max:200'],
            'nome_fantasia' => ['nullable', 'string', 'max:150'],
            'cnpj' => [$novo ? 'required' : ($atual?->cnpj ? 'sometimes' : 'nullable'), 'digits:14', new CnpjValido],
            'cidade_ibge' => ['nullable', 'string', 'size:7'],
            'cidade' => ['nullable', 'string', 'max:100'],
            'uf' => ['bail', 'nullable', 'string', 'size:2', fn (string $a, mixed $v, Closure $falhar) => in_array(mb_strtoupper($v), Normaliza::UFS, true) || $falhar('UF inválida.')],
            'versao' => [$novo ? 'nullable' : 'required', 'integer'],
        ]);
    }
}
