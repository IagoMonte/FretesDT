<?php

namespace App\Services;

use App\Models\Usuario;
use Illuminate\Support\Facades\DB;

/** União das matrizes dos grupos do usuário. */
class PermissaoService
{
    /** @return array{permissoes: array<string, array>, acoes: array<int, string>} */
    public function efetivas(Usuario $usuario): array
    {
        $grupoIds = DB::table('usuario_grupo')
            ->join('grupo', 'grupo.id', '=', 'usuario_grupo.grupo_id')
            ->where('usuario_grupo.usuario_id', $usuario->id)
            ->where('grupo.ativo', true)
            ->pluck('grupo.id')->all();

        return $this->dosGrupos($grupoIds);
    }

    /**
     * União das matrizes de uma lista de grupos (mesmo cálculo do usuário).
     *
     * @param  array<int, string>  $grupoIds
     * @return array{permissoes: array<string, array>, acoes: array<int, string>}
     */
    public function dosGrupos(array $grupoIds): array
    {
        $permissoes = [];
        foreach (DB::table('recurso')->where('ativo', true)->orderBy('codigo')->get() as $r) {
            $permissoes[$r->codigo] = ['ver' => false, 'inserir' => false, 'alterar' => false, 'cancelar' => false, 'escopo' => 'PROPRIOS'];
        }

        $linhas = DB::table('grupo_permissao')->whereIn('grupo_id', $grupoIds)->get();
        $usaEscopo = DB::table('recurso')->pluck('usa_escopo', 'codigo');
        $achou = [];
        foreach ($linhas as $l) {
            if (! isset($permissoes[$l->recurso_codigo])) {
                continue;
            }
            $p = &$permissoes[$l->recurso_codigo];
            $p['ver'] = $p['ver'] || (bool) $l->pode_ver;
            $p['inserir'] = $p['inserir'] || (bool) $l->pode_inserir;
            $p['alterar'] = $p['alterar'] || (bool) $l->pode_alterar;
            $p['cancelar'] = $p['cancelar'] || (bool) $l->pode_cancelar;
            $escopo = $usaEscopo[$l->recurso_codigo] ? $l->escopo : 'TODOS';
            if (! isset($achou[$l->recurso_codigo]) || $escopo === 'TODOS') {
                $p['escopo'] = $escopo;
                $achou[$l->recurso_codigo] = true;
            }
            unset($p);
        }

        $acoes = [];
        foreach (DB::table('grupo_acao')->whereIn('grupo_id', $grupoIds)->get() as $a) {
            if ($permissoes[$a->recurso_codigo]['ver'] ?? false) {
                $acoes["{$a->recurso_codigo}.{$a->acao_codigo}"] = true;
            }
        }
        $acoes = array_keys($acoes);
        sort($acoes);

        return ['permissoes' => $permissoes, 'acoes' => $acoes];
    }

    /**
     * Quem concede permissão só concede o que já tem: toda flag, ação e escopo do alvo precisa estar no conjunto do ator.
     * Escopo restrito (PROPRIOS, TRANSPORTADORA) nunca excede; TODOS só vale se o ator também tiver TODOS.
     *
     * @param  array{permissoes: array<string, array>, acoes: array<int, string>}  $ator
     * @param  array{permissoes: array<string, array>, acoes: array<int, string>}  $alvo
     */
    public function contem(array $ator, array $alvo): bool
    {
        foreach ($alvo['permissoes'] as $recurso => $p) {
            $meu = $ator['permissoes'][$recurso] ?? null;
            $algum = false;
            foreach (['ver', 'inserir', 'alterar', 'cancelar'] as $op) {
                if (! empty($p[$op])) {
                    $algum = true;
                    if (empty($meu[$op])) {
                        return false;
                    }
                }
            }
            if ($algum && ($p['escopo'] ?? null) === 'TODOS' && ($meu['escopo'] ?? null) !== 'TODOS') {
                return false;
            }
        }

        return array_diff($alvo['acoes'], $ator['acoes']) === [];
    }}
