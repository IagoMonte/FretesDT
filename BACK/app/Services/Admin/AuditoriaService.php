<?php

namespace App\Services\Admin;

use App\Models\LogAcesso;
use App\Models\LogOperacao;
use App\Models\LogSistema;
use App\Models\Usuario;
use App\Support\Periodo;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/** Leitura dos três logs (operação, sistema e acesso): quem fez o quê e quando. */
class AuditoriaService
{
    public function operacao(array $f, int $porPagina = 25): LengthAwarePaginator
    {
        $q = LogOperacao::query()->orderByDesc('ocorrido_em')->orderByDesc('id');
        $this->comuns($q, $f);
        foreach (['entidade', 'evento', 'correlacao_id'] as $campo) {
            if (! empty($f[$campo])) {
                $q->where($campo, $f[$campo]);
            }
        }
        if (! empty($f['q'])) {
            $t = $this->like($f['q']);
            $q->where(fn ($w) => $w->where('registro_codigo', 'like', $t)->orWhere('registro_id', $f['q'])->orWhere('detalhe', 'like', $t));
        }

        return $this->paginar($q, $f, $porPagina, fn (LogOperacao $l, $u) => [
            'id' => $l->id, 'quando' => $this->local($l->ocorrido_em), 'usuario_id' => $l->usuario_id, 'usuario' => $u[$l->usuario_id] ?? 'Sistema',
            'entidade' => $l->entidade, 'registro_id' => $l->registro_id, 'codigo' => $l->registro_codigo, 'evento' => $l->evento,
            'de' => $l->status_de, 'para' => $l->status_para, 'detalhe' => $l->detalhe ? (json_decode($l->detalhe, true) ?? ['texto' => $l->detalhe]) : [],
            'correlacao_id' => $l->correlacao_id,
        ]);
    }

    public function sistema(array $f, int $porPagina = 25): LengthAwarePaginator
    {
        $q = LogSistema::query()->orderByDesc('ocorrido_em')->orderByDesc('id');
        $this->comuns($q, $f);
        foreach (['nivel', 'categoria', 'evento', 'correlacao_id'] as $campo) {
            if (! empty($f[$campo])) {
                $q->where($campo, $f[$campo]);
            }
        }
        if (! empty($f['q'])) {
            $t = $this->like($f['q']);
            $q->where(fn ($w) => $w->where('mensagem', 'like', $t)->orWhere('evento', 'like', $t)->orWhere('usuario_login', 'like', $t)->orWhere('tabela', 'like', $t)->orWhere('rota', 'like', $t));
        }

        return $this->paginar($q, $f, $porPagina, fn (LogSistema $l, $u) => [
            'id' => $l->id, 'quando' => $this->local($l->ocorrido_em), 'nivel' => $l->nivel, 'categoria' => $l->categoria, 'evento' => $l->evento,
            'usuario_id' => $l->usuario_id, 'usuario' => $u[$l->usuario_id] ?? $l->usuario_login ?? '—', 'tabela' => $l->tabela, 'registro_id' => $l->registro_id,
            'mensagem' => $l->mensagem, 'metodo' => $l->metodo, 'rota' => $l->rota, 'ip' => $l->ip, 'correlacao_id' => $l->correlacao_id,
            'dados_antes' => $l->dados_antes, 'dados_depois' => $l->dados_depois, 'contexto' => $l->contexto,
        ]);
    }

    public function acessos(array $f, int $porPagina = 25): LengthAwarePaginator
    {
        $q = LogAcesso::query()->orderByDesc('ocorrido_em')->orderByDesc('id');
        $this->comuns($q, $f);
        if (! empty($f['ip'])) {
            $q->where('ip', $f['ip']);
        }

        return $this->paginar($q, $f, $porPagina, fn (LogAcesso $l, $u) => [
            'id' => $l->id, 'quando' => $this->local($l->ocorrido_em), 'usuario_id' => $l->usuario_id, 'usuario' => $u[$l->usuario_id] ?? '—', 'ip' => $l->ip,
        ]);
    }

    /** Atividade no período: por usuário, evento, dia e hora, mais logins e falhas. */
    public function resumo(Periodo $p): array
    {
        $tz = config('frete.fuso_exibicao');
        $ops = LogOperacao::query()->whereBetween('ocorrido_em', [$p->inicioUtc(), $p->fimUtc()])->get(['usuario_id', 'evento', 'ocorrido_em']);
        $nomes = $this->nomes($ops->pluck('usuario_id')->merge(LogAcesso::query()->whereBetween('ocorrido_em', [$p->inicioUtc(), $p->fimUtc()])->pluck('usuario_id')));

        $heat = array_fill(0, 7, array_fill(0, 24, 0));
        $dias = [];
        foreach ($ops as $l) {
            $d = $l->ocorrido_em->copy()->setTimezone($tz);
            $heat[(int) $d->format('N') - 1][(int) $d->format('G')]++;
            $dias[$d->toDateString()] = ($dias[$d->toDateString()] ?? 0) + 1;
        }
        ksort($dias);

        $acessos = LogAcesso::query()->whereBetween('ocorrido_em', [$p->inicioUtc(), $p->fimUtc()])->get(['usuario_id', 'ip']);

        return [
            'total_eventos' => $ops->count(),
            'por_usuario' => $ops->groupBy('usuario_id')->map(fn ($g, $id) => ['usuario' => $nomes[$id] ?? 'Sistema', 'eventos' => $g->count()])->sortByDesc('eventos')->values()->all(),
            'por_evento' => $ops->groupBy('evento')->map(fn ($g, $e) => ['evento' => $e, 'total' => $g->count()])->sortByDesc('total')->values()->all(),
            'por_dia' => collect($dias)->map(fn ($n, $d) => ['dia' => $d, 'eventos' => $n])->values()->all(),
            'heatmap' => $heat,
            'acessos_por_usuario' => $acessos->groupBy('usuario_id')->map(fn ($g, $id) => ['usuario' => $nomes[$id] ?? '—', 'logins' => $g->count(), 'ips' => $g->pluck('ip')->unique()->filter()->count()])->sortByDesc('logins')->values()->all(),
            'falhas_login' => LogSistema::where('evento', 'LOGIN_FALHA')->whereBetween('ocorrido_em', [$p->inicioUtc(), $p->fimUtc()])->count(),
            'erros' => LogSistema::where('nivel', 'ERROR')->whereBetween('ocorrido_em', [$p->inicioUtc(), $p->fimUtc()])->count(),
        ];
    }

    /** Eventos de negócio + registros técnicos de um mesmo registro, em ordem cronológica. */
    public function linhaDoTempo(string $entidade, string $registroId): array
    {
        $ops = LogOperacao::where('registro_id', $registroId)->orderBy('ocorrido_em')->get();
        $correlacoes = $ops->pluck('correlacao_id')->filter()->unique()->all();
        $sis = LogSistema::query()->where(fn ($w) => $w->where('registro_id', $registroId)->orWhereIn('correlacao_id', $correlacoes ?: ['-']))->orderBy('ocorrido_em')->get();
        $nomes = $this->nomes($ops->pluck('usuario_id')->merge($sis->pluck('usuario_id')));

        $linhas = $ops->map(fn (LogOperacao $l) => [
            'origem' => 'operacao', 'quando' => $this->local($l->ocorrido_em), 'ordem' => 0, 'usuario' => $nomes[$l->usuario_id] ?? 'Sistema', 'evento' => $l->evento,
            'entidade' => $l->entidade, 'codigo' => $l->registro_codigo, 'de' => $l->status_de, 'para' => $l->status_para,
            'detalhe' => $l->detalhe ? (json_decode($l->detalhe, true) ?? []) : [], 'mensagem' => null, 'nivel' => null,
        ])->concat($sis->map(fn (LogSistema $l) => [
            'origem' => 'sistema', 'quando' => $this->local($l->ocorrido_em), 'ordem' => 1, 'usuario' => $nomes[$l->usuario_id] ?? $l->usuario_login ?? '—', 'evento' => $l->evento,
            'entidade' => $l->tabela, 'codigo' => null, 'de' => null, 'para' => null, 'detalhe' => [], 'mensagem' => $l->mensagem, 'nivel' => $l->nivel,
        ]));

        return $linhas->sortBy(fn ($l) => [$l['quando']->getTimestamp(), $l['ordem']])->values()->all();
    }

    /** Valores distintos para os filtros. */
    public function opcoes(): array
    {
        return [
            'entidades' => LogOperacao::distinct()->orderBy('entidade')->pluck('entidade')->all(),
            'eventos' => LogOperacao::distinct()->orderBy('evento')->pluck('evento')->all(),
            'eventos_sistema' => LogSistema::distinct()->orderBy('evento')->pluck('evento')->all(),
            'categorias' => LogSistema::distinct()->orderBy('categoria')->pluck('categoria')->all(),
            'usuarios' => Usuario::orderBy('nome')->get(['id', 'nome', 'login'])->map(fn ($u) => ['id' => $u->id, 'nome' => $u->nome, 'login' => $u->login])->all(),
        ];
    }

    private function comuns(Builder $q, array $f): void
    {
        if (! empty($f['usuario_id'])) {
            $q->where('usuario_id', $f['usuario_id']);
        }
        $tz = config('frete.fuso_exibicao');
        if (! empty($f['de']) && $this->data($f['de'])) {
            $q->where('ocorrido_em', '>=', \Carbon\CarbonImmutable::parse($f['de'], $tz)->startOfDay()->utc()->format('Y-m-d H:i:s.v'));
        }
        if (! empty($f['ate']) && $this->data($f['ate'])) {
            $q->where('ocorrido_em', '<=', \Carbon\CarbonImmutable::parse($f['ate'], $tz)->endOfDay()->utc()->format('Y-m-d H:i:s.v'));
        }
    }

    private function paginar(Builder $q, array $f, int $porPagina, callable $linha): LengthAwarePaginator
    {
        $pagina = max(1, (int) ($f['pagina'] ?? 1));
        $pag = $q->paginate($porPagina, ['*'], 'pagina', $pagina);
        $nomes = $this->nomes($pag->getCollection()->pluck('usuario_id'));

        return $pag->through(fn ($l) => $linha($l, $nomes));
    }

    /** @return array<string, string> id => nome */
    private function nomes(Collection $ids): array
    {
        $ids = $ids->filter()->unique()->values();

        return $ids->isEmpty() ? [] : Usuario::whereIn('id', $ids)->pluck('nome', 'id')->all();
    }

    private function local(?CarbonInterface $d): ?CarbonInterface
    {
        return $d?->copy()->setTimezone(config('frete.fuso_exibicao'));
    }

    private function like(string $v): string
    {
        return '%'.addcslashes(trim($v), '%_\\').'%';
    }

    private function data(string $v): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4));
    }
}
