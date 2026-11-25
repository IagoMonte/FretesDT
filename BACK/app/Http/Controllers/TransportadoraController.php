<?php

namespace App\Http\Controllers;

use App\Http\Resources\TransportadoraResource;
use App\Models\Transportadora;
use App\Rules\CnpjValido;
use App\Rules\GrupoWhatsappValido;
use App\Services\LogService;
use App\Support\Fmt;
use App\Support\Telefone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TransportadoraController extends Controller
{
    public function __construct(private LogService $log) {}

    public function index(Request $request)
    {
        $q = Transportadora::query()->orderBy('nome_fantasia');
        $this->filtroAtivo($q, $request->query('ativo'));
        if ($request->boolean('revisao_pendente')) {
            $q->where('revisao_pendente', true);
        }
        if ($termo = $request->query('termo')) {
            $q->where(fn ($w) => $this->contem($this->contem($w, 'razao_social', $termo), 'nome_fantasia', $termo, true)
                ->orWhere('cnpj', 'like', '%'.preg_replace('/\D+/', '', $termo).'%'));
        }

        return $this->lista(TransportadoraResource::class, $q->get());
    }

    public function show(string $id): JsonResponse
    {
        return $this->um(TransportadoraResource::make(Transportadora::findOrFail($id)));
    }

    public function store(Request $request): JsonResponse
    {
        $d = $this->validar($request);
        $t = DB::transaction(function () use ($d) {
            $t = Transportadora::create($d + ['ativo' => true]);
            $this->log->operacao('TRANSPORTADORA', $t, 'CADASTRO_CRIADO', null, null, ['nome' => $t->nome_fantasia]);

            return $t;
        });

        return $this->um(TransportadoraResource::make($t), [], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $t = Transportadora::findOrFail($id);
        $d = $this->validar($request, $t);

        DB::transaction(function () use ($t, $d, $request) {
            $t->exigirVersao($request->integer('versao'));
            $t->fill($d);
            if ($t->cnpj) {
                $t->revisao_pendente = false;
            }
            $t->tocar();
            $this->log->operacao('TRANSPORTADORA', $t, 'CADASTRO_EDITADO', null, null, ['nome' => $t->nome_fantasia]);
        });

        return $this->um(TransportadoraResource::make($t->refresh()));
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
        $t = DB::transaction(function () use ($id, $ativo) {
            $t = Transportadora::findOrFail($id);
            $t->ativo = $ativo;
            $t->save();
            $this->log->operacao('TRANSPORTADORA', $t, $ativo ? 'CADASTRO_REATIVADO' : 'CADASTRO_INATIVADO');

            return $t;
        });

        return $this->um(TransportadoraResource::make($t->refresh()));
    }

    private function validar(Request $request, ?Transportadora $atual = null): array
    {
        if ($request->has('cnpj')) {
            $request->merge(['cnpj' => Fmt::cnpj($request->input('cnpj'))]);
        }
        $novo = $atual === null;

        $d = $request->validate([
            'razao_social' => [$novo ? 'required' : 'sometimes', 'string', 'min:2', 'max:200'],
            'nome_fantasia' => [$novo ? 'required' : 'sometimes', 'string', 'min:2', 'max:150'],
            'cnpj' => [$novo ? 'required' : ($atual->cnpj ? 'sometimes' : 'nullable'), 'digits:14', new CnpjValido, Rule::unique('transportadora', 'cnpj')->ignore($atual?->id)],
            'email' => ['nullable', 'email', 'max:200'],
            'telefone' => ['nullable', 'string', 'max:20', fn ($a, $v, $fail) => $v && ! Telefone::e164($v) ? $fail('Informe um telefone com DDD, por exemplo (19) 99999-0001.') : null],
            'whatsapp_grupo_id' => array_filter(['nullable', 'string', 'max:60', $request->input('whatsapp_grupo_id') !== $atual?->whatsapp_grupo_id ? new GrupoWhatsappValido : null]),
            'versao' => [$novo ? 'nullable' : 'required', 'integer'],
        ]);

        return Arr::except($d, ['versao']);
    }
}
