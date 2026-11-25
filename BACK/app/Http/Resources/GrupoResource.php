<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/** Grupo de acesso com a matriz de permissões (só o que foi concedido) e as ações liberadas. */
class GrupoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $permissoes = [];
        foreach (DB::table('grupo_permissao')->where('grupo_id', $this->id)->orderBy('recurso_codigo')->get() as $p) {
            if ($p->pode_ver || $p->pode_inserir || $p->pode_alterar || $p->pode_cancelar) {
                $permissoes[$p->recurso_codigo] = [
                    'ver' => (bool) $p->pode_ver, 'inserir' => (bool) $p->pode_inserir,
                    'alterar' => (bool) $p->pode_alterar, 'cancelar' => (bool) $p->pode_cancelar, 'escopo' => $p->escopo,
                ];
            }
        }
        $acoes = DB::table('grupo_acao')->where('grupo_id', $this->id)->orderBy('recurso_codigo')->orderBy('acao_codigo')->get()
            ->map(fn ($a) => "{$a->recurso_codigo}.{$a->acao_codigo}")->values();

        return [
            'id' => $this->id,
            'codigo' => $this->codigo,
            'nome' => $this->nome,
            'descricao' => $this->descricao,
            'tipo_usuario' => $this->tipo_usuario,
            'sistema' => (bool) $this->sistema,
            'ativo' => (bool) $this->ativo,
            'usuarios' => $this->usuarios_count ?? DB::table('usuario_grupo')->where('grupo_id', $this->id)->count(),
            'permissoes' => (object) $permissoes,
            'acoes' => $acoes,
            'versao' => $this->versao,
        ];
    }
}
