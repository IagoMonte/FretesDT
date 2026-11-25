<?php

namespace App\Http\Controllers;

use App\Http\Resources\EmbalagemResource;
use App\Models\Embalagem;
use App\Services\LogService;
use App\Support\Fmt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class EmbalagemController extends Controller
{
    public function __construct(private LogService $log) {}

    public function index(Request $request)
    {
        $q = Embalagem::orderBy('codigo');
        $this->filtroAtivo($q, $request->query('ativo'));
        if ($request->boolean('revisao_pendente')) {
            $q->where('revisao_pendente', true);
        }

        return $this->lista(EmbalagemResource::class, $q->get());
    }

    public function store(Request $request): JsonResponse
    {
        $d = $this->validar($request);
        $e = DB::transaction(function () use ($d) {
            $e = Embalagem::create(Arr::except($d, ['versao']) + ['ativo' => true]);
            $this->log->operacao('EMBALAGEM', $e, 'CADASTRO_CRIADO', null, null, Arr::only($d, ['codigo', 'descricao', 'permite_paletizar', 'permite_remontar', 'granel', 'fracionada']));

            return $e;
        });

        return $this->um(EmbalagemResource::make($e->refresh()), [], 201);
    }

    public function update(Request $request, string $codigo): JsonResponse
    {
        $e = Embalagem::findOrFail($codigo);
        $d = $this->validar($request, false);

        DB::transaction(function () use ($e, $d) {
            $e->exigirVersao($d['versao']);
            $e->fill(Arr::except($d, ['versao', 'codigo']));
            $e->revisao_pendente = $d['revisao_pendente'] ?? false;
            if ($e->granel) {
                $e->permite_paletizar = false;
                $e->permite_remontar = false;
                $e->fracionada = false;
                DB::table('produto_embalagem')->where('embalagem_codigo', $e->codigo)->update(['kg_por_volume' => null, 'kg_por_palete' => null]);
            } elseif (! $e->fracionada) {
                DB::table('produto_embalagem')->where('embalagem_codigo', $e->codigo)->update(['kg_por_palete' => null]);
            }
            $e->tocar();
            $this->log->operacao('EMBALAGEM', $e, 'CADASTRO_EDITADO', null, null, Arr::only($d, ['descricao', 'permite_paletizar', 'permite_remontar', 'granel']));
        });

        return $this->um(EmbalagemResource::make($e->refresh()));
    }

    public function inativar(string $codigo): JsonResponse
    {
        return $this->alterarAtivo($codigo, false);
    }

    public function ativar(string $codigo): JsonResponse
    {
        return $this->alterarAtivo($codigo, true);
    }

    private function alterarAtivo(string $codigo, bool $ativo): JsonResponse
    {
        $e = DB::transaction(function () use ($codigo, $ativo) {
            $e = Embalagem::findOrFail($codigo);
            $e->ativo = $ativo;
            $e->save();
            $this->log->operacao('EMBALAGEM', $e, $ativo ? 'CADASTRO_REATIVADO' : 'CADASTRO_INATIVADO');

            return $e;
        });

        return $this->um(EmbalagemResource::make($e->refresh()));
    }

    private function validar(Request $request, bool $novo = true): array
    {
        if ($request->has('codigo')) {
            $request->merge(['codigo' => Fmt::seTexto($request->input('codigo'), fn (string $t) => mb_strtoupper(trim($t)))]);
        }
        $obrig = $novo ? 'required' : 'sometimes';

        $d = $request->validate([
            'codigo' => $novo ? ['required', 'string', 'max:30', 'regex:/^[A-Z0-9_]+$/', 'unique:embalagem,codigo'] : ['prohibited'],
            'descricao' => [$obrig, 'string', 'max:100'],
            'permite_paletizar' => ['sometimes', 'boolean'],
            'permite_remontar' => ['sometimes', 'boolean'],
            'granel' => ['sometimes', 'boolean'],
            'fracionada' => ['sometimes', 'boolean'],
            'revisao_pendente' => ['sometimes', 'boolean'],
            'versao' => [$novo ? 'nullable' : 'required', 'integer'],
        ]);
        if (! empty($d['granel'])) {
            $d['permite_paletizar'] = false;
            $d['permite_remontar'] = false;
            $d['fracionada'] = false;
        }

        return $d;
    }
}
