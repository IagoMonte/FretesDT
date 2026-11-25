<?php

namespace App\Http\Controllers\Admin;

use App\Services\Admin\AuditoriaService;
use Illuminate\Http\Request;

class AuditoriaController extends Base
{
    private const ABAS = ['operacao', 'sistema', 'acessos', 'resumo'];

    public function __construct(private AuditoriaService $svc) {}

    public function index(Request $request)
    {
        $aba = in_array($request->query('aba'), self::ABAS, true) ? $request->query('aba') : 'operacao';
        $f = $request->only(['usuario_id', 'entidade', 'evento', 'nivel', 'categoria', 'q', 'de', 'ate', 'correlacao_id', 'ip', 'pagina']);
        $f = array_map(fn ($v) => is_string($v) ? $v : '', $f);
        $p = $this->periodo($request);

        $dados = match ($aba) {
            'sistema' => ['pag' => $this->svc->sistema($f)],
            'acessos' => ['pag' => $this->svc->acessos($f)],
            'resumo' => ['r' => $this->svc->resumo($p)],
            default => ['pag' => $this->svc->operacao($f)],
        };
        if (isset($dados['pag'])) {
            $dados['pag']->withPath($request->url())->appends($request->except('pagina'));
        }

        return view('admin.auditoria.index', $dados + ['aba' => $aba, 'f' => $f, 'periodo' => $p, 'opcoes' => $this->svc->opcoes()]);
    }

    public function registro(string $entidade, string $id)
    {
        return view('admin.auditoria.registro', ['entidade' => $entidade, 'linhas' => $this->svc->linhaDoTempo($entidade, $id)]);
    }
}
