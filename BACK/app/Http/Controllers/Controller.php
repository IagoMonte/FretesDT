<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Services\Notificacao\NotificacaoAlertas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Validation\ValidationException;

abstract class Controller
{
    /** Recurso único: { data, meta: { alertas } }. */
    protected function um(JsonResource $recurso, array $alertas = [], int $status = 200, array $meta = []): JsonResponse
    {
        $avisosDeContato = request()->user()?->ehTransportadora() ? [] : app(NotificacaoAlertas::class)->todos();
        $alertas = array_values(array_unique([...$alertas, ...$avisosDeContato]));

        return $recurso->additional(['meta' => ['alertas' => $alertas] + $meta])->response()->setStatusCode($status);
    }

    /** Lista: { data: [...], meta: { total } }. */
    protected function lista(string $recurso, iterable $itens): ResourceCollection
    {
        $colecao = collect($itens);

        return $recurso::collection($colecao)->additional(['meta' => ['total' => $colecao->count()]]);
    }

    protected function usuario(): Usuario
    {
        return request()->user();
    }

    /** @return array<int, string> valores de um filtro "A,B,C" */
    protected function csv(?string $valor): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $valor))));
    }

    /** Termo de busca para LIKE ... ESCAPE '!': % e _ digitados valem como texto, não como curinga. */
    protected function like(string $termo): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $termo).'%';
    }

    /** `coluna LIKE %termo%` (AND, ou OR com $ou) sem tratar % e _ como curingas. */
    protected function contem(object $consulta, string $coluna, string $termo, bool $ou = false): object
    {
        return $consulta->{$ou ? 'orWhereRaw' : 'whereRaw'}("{$coluna} like ? escape '!'", [$this->like($termo)]);
    }

    /**
     * Filtro "A,B,C" de status: valor fora de `$permitidos` é 422 em vez de ser ignorado (que devolvia a lista toda).
     *
     * @param  array<int, string>  $permitidos
     * @return array<int, string>
     */
    protected function statusDoFiltro(Request $request, array $permitidos): array
    {
        $pedidos = $this->csv($request->query('status'));
        if ($invalidos = array_diff($pedidos, $permitidos)) {
            throw ValidationException::withMessages(['status' => 'Status inválido: '.implode(', ', $invalidos).'.']);
        }

        return $pedidos;
    }

    protected function filtroAtivo(object $consulta, ?string $ativo): void
    {
        if ($ativo !== null && $ativo !== '') {
            $consulta->where('ativo', in_array(strtolower($ativo), ['1', 'true', 'sim'], true));
        }
    }
}