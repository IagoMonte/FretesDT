<?php

namespace App\Http\Controllers\Admin;

use App\Services\Admin\AnaliseTransportadoraService;
use Illuminate\Http\Request;

class TransportadoraController extends Base
{
    public function __construct(private AnaliseTransportadoraService $svc) {}

    public function index(Request $request)
    {
        $p = $this->periodo($request);

        return view('admin.transportadoras.index', [
            'periodo' => $p,
            'ranking' => $this->svc->ranking($p),
            'mercado' => $this->svc->mercadoPorProduto($p),
            'matriz' => $this->svc->matrizProduto($p),
            'rotas' => $this->svc->ganhaPerde($p, 'rota'),
            'oport' => $this->svc->oportunidades($p),
        ]);
    }

    public function show(Request $request, string $id)
    {
        $p = $this->periodo($request);
        $d = $this->svc->detalhe($id, $p);
        abort_if($d === null, 404);

        return view('admin.transportadoras.show', ['periodo' => $p, 'd' => $d]);
    }
}
