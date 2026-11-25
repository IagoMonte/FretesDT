<?php

namespace App\Http\Controllers\Admin;

use App\Services\Admin\AnaliseVendedorService;
use Illuminate\Http\Request;

class VendedorController extends Base
{
    public function __construct(private AnaliseVendedorService $svc) {}

    public function index(Request $request)
    {
        $p = $this->periodo($request);

        return view('admin.vendedores.index', ['periodo' => $p, 'ranking' => $this->svc->ranking($p)]);
    }

    public function show(Request $request, string $id)
    {
        $p = $this->periodo($request);
        $d = $this->svc->detalhe($id, $p);
        abort_if($d === null, 404);

        return view('admin.vendedores.show', ['periodo' => $p, 'd' => $d]);
    }
}
