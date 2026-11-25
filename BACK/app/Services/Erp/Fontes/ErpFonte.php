<?php

namespace App\Services\Erp\Fontes;

use App\Services\Erp\RegistroInvalido;
use Closure;

/**
 * Uma entidade do ERP: de onde ler, como normalizar e como casar com o cadastro local.
 * `normalizar` devolve só os campos ESPELHADOS (os que o ERP manda e entram no hash); campos que são só nossos
 * (descrição, ONU, densidade, fatores de embalagem...) nunca passam por aqui, então nunca são sobrescritos.
 */
abstract class ErpFonte
{
    /** Código da linha em `sincronizacao` e do recurso (CLIENTE, PRODUTO...). */
    abstract public function entidade(): string;

    /** Rota da ponte (/api/v1/{rota}); também o nome da entidade em /api/v1/status. */
    abstract public function rota(): string;

    abstract public function tabela(): string;

    /** A rota aceita `alteradoDesde` (o ERP tem data de alteração). Embalagem não tem: sempre lê tudo. */
    public function temDelta(): bool
    {
        return true;
    }

    /** Coluna da chave primária local. */
    public function pk(): string
    {
        return 'id';
    }

    /** Código do registro no ERP (vira `codigo_erp`). */
    abstract public function codigoErp(array $cru): string;

    /**
     * @param  Closure(?string, ?string): ?string  $ibge  (cidade, uf) => código IBGE local
     * @return array{campos: array<string, mixed>, avisos: array<int, string>}
     *
     * @throws RegistroInvalido
     */
    abstract public function normalizar(array $cru, Closure $ibge): array;

    /** Colunas locais lidas na pré-carga para o casamento (além de pk, codigo_erp, erp_hash). */
    public function colunasLocais(): array
    {
        return [];
    }

    /** Chaves pelas quais uma linha local MANUAL pode ser casada com um registro do ERP. */
    public function chavesLocais(object $linha): array
    {
        return [];
    }

    /** Chave do registro do ERP para casar com cadastro manual existente (null = nunca casa). */
    public function chaveCasamento(array $campos): ?string
    {
        return null;
    }

    /** CNPJ é UNIQUE na tabela local (transportadora): duplicidade no ERP pula a linha e a execução fica PARCIAL. */
    public function cnpjUnico(): bool
    {
        return false;
    }

    /** Campos gravados quando um cadastro manual é vinculado ao ERP (embalagem só ganha o código). */
    public function camposNaFusao(array $campos): array
    {
        return $campos;
    }

    /**
     * Colunas extras de um registro novo (a chave primária inclusive, quando não for UUID).
     *
     * @param  array<string, true>  $codigosUsados  chaves primárias já ocupadas (preenchido e atualizado pelo serviço)
     */
    public function novo(array $campos, string $codigoErp, array $codigosUsados): array
    {
        return [];
    }
}
