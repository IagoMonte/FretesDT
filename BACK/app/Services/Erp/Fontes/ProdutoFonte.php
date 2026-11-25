<?php

namespace App\Services\Erp\Fontes;

use App\Services\Erp\ProdutoEspelho;
use App\Services\Erp\RegistroInvalido;
use App\Support\NomeProduto;
use App\Support\Normaliza;
use Closure;

/**
 * Todo item do ERP é produto (sem filtro de serviço), mas o ERP tem um código por variação: vários códigos viram um
 * produto só (nome-base, ver {@see NomeProduto}) e cada código fica em `frete_produto_erp`. O código é o `codigo` da ponte. A gravação em lote é do {@see ProdutoEspelho}, não do fluxo genérico.
 */
class ProdutoFonte extends ErpFonte
{
    public function entidade(): string
    {
        return 'PRODUTO';
    }

    public function rota(): string
    {
        return 'produtos';
    }

    public function tabela(): string
    {
        return 'produto';
    }

    public function codigoErp(array $cru): string
    {
        return trim((string) ($cru['codigo'] ?? ''));
    }

    public function normalizar(array $cru, Closure $ibge): array
    {
        $situacao = (string) ($cru['situacao'] ?? '');
        $ativo = config('frete.erp.situacao')[$situacao] ?? null;
        $descricao = Normaliza::texto($cru['descricao'] ?? null, 200) ?? throw new RegistroInvalido('Descrição do produto vazia.');

        return [
            'campos' => [
                'nome' => mb_substr(NomeProduto::base($descricao), 0, 150),
                'descricao_erp' => $descricao,
                'ativo' => (bool) $ativo,
            ],
            'avisos' => $ativo === null ? ["Situação desconhecida '{$situacao}': código inativado."] : [],
        ];
    }
}
