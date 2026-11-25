<?php

namespace App\Http\Controllers;

use App\Models\Embalagem;
use App\Models\Grupo;
use App\Models\TipoCargaAntt;
use App\Models\TipoVeiculo;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;

/** Domínios e listas de apoio. */
class ReferenciaController extends Controller
{
    public function dominios(): JsonResponse
    {
        return response()->json(['data' => [
            'embalagens' => Embalagem::where('ativo', true)->orderBy('codigo')->get()
                ->map(fn ($e) => [
                    'codigo' => $e->codigo, 'descricao' => $e->descricao,
                    'permite_paletizar' => (bool) $e->permite_paletizar, 'permite_remontar' => (bool) $e->permite_remontar,
                    'granel' => (bool) $e->granel, 'fracionada' => (bool) $e->fracionada,
                ])->values(),
            'tipos_veiculo' => TipoVeiculo::where('ativo', true)->orderBy('descricao')->get()
                ->map(fn ($v) => ['codigo' => $v->codigo, 'descricao' => $v->descricao])->values(),
            'tipos_carga_antt' => TipoCargaAntt::where('ativo', true)->orderBy('descricao')->get()
                ->map(fn ($v) => ['codigo' => $v->codigo, 'descricao' => $v->descricao])->values(),
        ]]);
    }

    public function grupos(): JsonResponse
    {
        $grupos = Grupo::where('ativo', true)->orderBy('nome')->get()
            ->map(fn ($g) => ['codigo' => $g->codigo, 'nome' => $g->nome, 'tipo_usuario' => $g->tipo_usuario])->values();

        return response()->json(['data' => $grupos, 'meta' => ['total' => $grupos->count()]]);
    }

    /** Usuários internos ativos (campo "Representante" da programação). */
    public function vendedores(): JsonResponse
    {
        $v = Usuario::where('situacao', 'ATIVO')->whereNull('transportadora_id')->orderBy('nome')->get()
            ->map(fn ($u) => ['id' => $u->id, 'nome' => $u->nome])->values();

        return response()->json(['data' => $v, 'meta' => ['total' => $v->count()]]);
    }
}