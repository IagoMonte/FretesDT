<?php

namespace App\Services\Erp;

use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/** Leitura da API-ponte do ERP (GET /api/v1/{rota}). Só lê; o app nunca chama a ponte durante uma requisição de usuário. */
class ErpFretesClient
{
    /**
     * Páginas de uma rota, uma de cada vez (o chamador processa e descarta).
     *
     * @param  array<string, scalar>  $filtros  filtros da ponte (codigo, cnpj e alteradoDesde)
     * @return Generator<int, array<int, array<string, mixed>>>
     */
    public function paginas(string $rota, array $filtros = []): Generator
    {
        $pagina = 0;
        do {
            $corpo = $this->get("api/v1/{$rota}", $filtros + ['pagina' => $pagina, 'tamanho' => config('services.erp.pagina')]);
            if (! isset($corpo['itens']) || ! is_array($corpo['itens']) || ! isset($corpo['totalPaginas'])) {
                throw new ErpIndisponivelException("Resposta fora do contrato em /api/v1/{$rota}.");
            }
            yield $corpo['itens'];
            $pagina++;
        } while ($pagina < (int) $corpo['totalPaginas']);
    }

    /**
     * Quando cada carga completa da ponte rodou pela última vez com sucesso: ['clientes' => '2026-10-13T19:17:57', ...].
     *
     * @return array<string, string|null>
     */
    public function frescor(): array
    {
        $linhas = $this->get('api/v1/status');
        $r = [];
        foreach (is_array($linhas) ? $linhas : [] as $l) {
            if (($l['modo'] ?? null) === 'COMPLETA' && ($l['status'] ?? null) === 'SUCESSO' && isset($l['entidade'])) {
                $r[(string) $l['entidade']] = $l['ultimoSucessoEm'] ?? null;
            }
        }

        return $r;
    }

    private function get(string $caminho, array $query = []): array
    {
        $url = config('services.erp.url');
        if (! $url || ! config('services.erp.chave')) {
            throw new ErpIndisponivelException('ERP_FRETES_URL / ERP_FRETES_KEY não configurados.');
        }
        try {
            $resposta = $this->http()->retry(3, 500, fn ($e) => $e instanceof ConnectionException || ($e instanceof RequestException && $e->response->serverError()), throw: false)
                ->get(rtrim($url, '/').'/'.$caminho, $query);
        } catch (ConnectionException $e) {
            throw new ErpIndisponivelException('API-ponte inacessível: '.$e->getMessage(), 0, $e);
        }
        if (! $resposta->successful()) {
            throw new ErpIndisponivelException("API-ponte respondeu {$resposta->status()} em /{$caminho}.");
        }
        $json = $resposta->json();
        if (! is_array($json)) {
            throw new ErpIndisponivelException("Resposta não é JSON em /{$caminho}.");
        }

        return $json;
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders(['X-API-Key' => config('services.erp.chave')])->acceptJson()->timeout(config('services.erp.timeout'));
    }
}
