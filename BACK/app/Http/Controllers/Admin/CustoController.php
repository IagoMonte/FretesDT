<?php

namespace App\Http\Controllers\Admin;

use App\Services\Admin\AnaliseCustoService;
use Illuminate\Http\Request;

class CustoController extends Base
{
    public function __invoke(Request $request, AnaliseCustoService $svc)
    {
        $p = $this->periodo($request);

        return view('admin.custos', [
            'periodo' => $p,
            'produtos' => $svc->porProduto($p),
            'rotas' => $svc->porRota($p),
            'veiculos' => $svc->porVeiculo($p),
            'partes' => $svc->porParte($p),
        ]);
    }
}
