<?php

namespace App\Http\Controllers;

use App\Http\Resources\AnttCoeficienteResource;
use App\Models\AnttCoeficiente;
use App\Services\AnttService;
use App\Services\LogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Coeficientes do piso mínimo ANTT (CCD/CC por tipo de carga × eixos) e consulta rápida de distância. */
class AnttController extends Controller
{
    public function __construct(private LogService $log, private AnttService $antt) {}

    public function index(Request $request)
    {
        $q = AnttCoeficiente::orderBy('tipo_carga_codigo')->orderBy('eixos')->orderByDesc('vigencia_inicio');
        if ($tipo = $request->query('tipo_carga_codigo')) {
            $q->where('tipo_carga_codigo', $tipo);
        }
        $this->filtroAtivo($q, $request->query('ativo'));

        return $this->lista(AnttCoeficienteResource::class, $q->get());
    }

    public function store(Request $request): JsonResponse
    {
        $d = $this->validar($request);
        $this->exigirUnico($d);
        $c = DB::transaction(function () use ($d) {
            $c = AnttCoeficiente::create(Arr::except($d, ['versao']) + ['ativo' => true]);
            $this->log->operacao('ANTT', $c, 'CADASTRO_CRIADO', null, null, Arr::only($d, ['tipo_carga_codigo', 'eixos', 'ccd', 'cc', 'vigencia_inicio']));

            return $c;
        });

        return $this->um(AnttCoeficienteResource::make($c->refresh()), [], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $c = AnttCoeficiente::findOrFail($id);
        $d = $this->validar($request, false);
        $this->exigirUnico(Arr::only($d, ['vigencia_inicio']) + $c->only(['tipo_carga_codigo', 'eixos', 'vigencia_inicio']), $c->id);

        DB::transaction(function () use ($c, $d) {
            $c->exigirVersao($d['versao']);
            $c->fill(Arr::except($d, ['versao']))->tocar();
            $this->log->operacao('ANTT', $c, 'CADASTRO_EDITADO', null, null, Arr::only($d, ['ccd', 'cc', 'vigencia_inicio', 'vigencia_fim']));
        });

        return $this->um(AnttCoeficienteResource::make($c->refresh()));
    }

    public function inativar(string $id): JsonResponse
    {
        return $this->alterarAtivo($id, false);
    }

    public function ativar(string $id): JsonResponse
    {
        return $this->alterarAtivo($id, true);
    }

    /** km entre duas cidades da tabela de distâncias; `data: null` se o par não existe. */
    public function distancia(Request $request): JsonResponse
    {
        $d = $request->validate([
            'origem_cidade' => ['required', 'string', 'max:100'],
            'origem_uf' => ['required', 'string', 'size:2'],
            'destino_cidade' => ['required', 'string', 'max:100'],
            'destino_uf' => ['required', 'string', 'size:2'],
        ]);
        $km = $this->antt->distancia($d['origem_cidade'], $d['origem_uf'], $d['destino_cidade'], $d['destino_uf']);

        return response()->json(['data' => $km === null ? null : ['km' => $km], 'meta' => ['alertas' => []]]);
    }

    /**
     * Frete mínimo (km × CCD + CC) entre duas cidades. CCD/CC vêm da tabela (tipo de carga + eixos) ou da própria
     * consulta; `data: null` se faltar o par de cidades ou o coeficiente.
     */
    public function freteMinimo(Request $request): JsonResponse
    {
        $d = $request->validate([
            'origem_cidade' => ['required', 'string', 'max:100'],
            'origem_uf' => ['required', 'string', 'size:2'],
            'destino_cidade' => ['required', 'string', 'max:100'],
            'destino_uf' => ['required', 'string', 'size:2'],
            'eixos' => ['required', 'integer', 'between:2,9'],
            'tipo_carga_codigo' => ['nullable', 'required_without_all:ccd,cc', 'string', Rule::exists('tipo_carga_antt', 'codigo')],
            'ccd' => ['nullable', 'required_without:tipo_carga_codigo', 'numeric', 'min:0', 'max:99999999'],
            'cc' => ['nullable', 'required_without:tipo_carga_codigo', 'numeric', 'min:0', 'max:9999999999999'],
        ]);
        $vazio = response()->json(['data' => null, 'meta' => ['alertas' => []]]);
        $km = $this->antt->distancia($d['origem_cidade'], $d['origem_uf'], $d['destino_cidade'], $d['destino_uf']);
        if ($km === null) {
            return $vazio;
        }
        if (isset($d['ccd'], $d['cc'])) {
            $coef = new AnttCoeficiente(['ccd' => $d['ccd'], 'cc' => $d['cc']]);
        } else {
            $coef = $this->antt->coeficiente($d['tipo_carga_codigo'], (int) $d['eixos']);
            if (! $coef) {
                return $vazio;
            }
        }

        return response()->json(['data' => [
            'km' => $km,
            'eixos' => (int) $d['eixos'],
            'ccd' => (string) $coef->ccd,
            'cc' => (string) $coef->cc,
            'frete_minimo' => $this->antt->calcular($km, $coef),
        ], 'meta' => ['alertas' => []]]);
    }

    private function alterarAtivo(string $id, bool $ativo): JsonResponse
    {
        $c = DB::transaction(function () use ($id, $ativo) {
            $c = AnttCoeficiente::findOrFail($id);
            $c->ativo = $ativo;
            $c->save();
            $this->log->operacao('ANTT', $c, $ativo ? 'CADASTRO_REATIVADO' : 'CADASTRO_INATIVADO');

            return $c;
        });

        return $this->um(AnttCoeficienteResource::make($c->refresh()));
    }

    private function validar(Request $request, bool $novo = true): array
    {
        $obrig = $novo ? 'required' : 'sometimes';

        return $request->validate([
            'tipo_carga_codigo' => $novo ? ['required', 'string', Rule::exists('tipo_carga_antt', 'codigo')] : ['prohibited'],
            'eixos' => $novo ? ['required', 'integer', 'between:2,9'] : ['prohibited'],
            'ccd' => [$obrig, 'numeric', 'min:0', 'max:99999999'],
            'cc' => [$obrig, 'numeric', 'min:0', 'max:9999999999999'],
            'vigencia_inicio' => [$obrig, 'date_format:Y-m-d'],
            'vigencia_fim' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:vigencia_inicio'],
            'versao' => [$novo ? 'nullable' : 'required', 'integer'],
        ]);
    }

    private function exigirUnico(array $d, ?string $ignorar = null): void
    {
        $existe = AnttCoeficiente::where(Arr::only($d, ['tipo_carga_codigo', 'eixos', 'vigencia_inicio']))
            ->when($ignorar, fn ($q) => $q->where('id', '<>', $ignorar))->exists();
        if ($existe) {
            throw ValidationException::withMessages(['vigencia_inicio' => ['Já existe coeficiente deste tipo de carga e eixos com esta vigência.']]);
        }
    }
}
