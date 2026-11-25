<?php

namespace App\Http\Controllers\Admin;

use App\Services\Admin\AnaliseGeralService;
use App\Services\Admin\AnaliseTransportadoraService;
use App\Services\Admin\AnaliseVendedorService;
use Illuminate\Http\Request;

/** Visão geral: indicadores do período, séries, rotas e alertas. */
class PainelController extends Base
{
    public function __invoke(Request $request, AnaliseGeralService $geral, AnaliseTransportadoraService $transp, AnaliseVendedorService $vend)
    {
        $p = $this->periodo($request);

        return view('admin.geral', [
            'periodo' => $p,
            'k' => $geral->kpis($p),
            'cmp' => $geral->comparativo($p),
            'serie' => $geral->serieMensal($p),
            'rotas' => $geral->topRotas($p),
            'produtos' => $geral->topProdutos($p),
            'operacao' => $geral->porOperacao($p),
            'alertas' => $geral->alertas(),
            'transportadoras' => array_slice($transp->ranking($p), 0, 5),
            'vendedores' => array_slice($vend->ranking($p), 0, 5),
        ]);
    }
}
