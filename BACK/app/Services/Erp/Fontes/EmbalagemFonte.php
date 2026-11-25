<?php

namespace App\Services\Erp\Fontes;

use App\Services\Erp\RegistroInvalido;
use App\Support\Normaliza;
use Closure;

/**
 * Embalagem do ERP. As locais são casadas pelo nome (e só ganham `codigo_erp`, sem mudar o `codigo`);
 * as novas entram com flags sugeridas por palavra-chave e `revisao_pendente`, para o painel confirmar.
 * O ERP não tem situação de embalagem, então `ativo` é decisão local e fica fora do espelho.
 */
class EmbalagemFonte extends ErpFonte
{
    public function entidade(): string
    {
        return 'EMBALAGEM';
    }

    public function rota(): string
    {
        return 'embalagens';
    }

    public function tabela(): string
    {
        return 'embalagem';
    }

    public function temDelta(): bool
    {
        return false;
    }

    public function pk(): string
    {
        return 'codigo';
    }

    public function codigoErp(array $cru): string
    {
        return (string) ($cru['codigo'] ?? '');
    }

    public function colunasLocais(): array
    {
        return ['descricao'];
    }

    public function chavesLocais(object $linha): array
    {
        return [Normaliza::chave($linha->codigo), Normaliza::chave($linha->descricao)];
    }

    public function chaveCasamento(array $campos): ?string
    {
        $chave = Normaliza::chave($campos['descricao']);

        return Normaliza::chave(config('frete.erp.embalagem_alias')[$chave] ?? $chave);
    }

    public function camposNaFusao(array $campos): array
    {
        return [];
    }

    public function normalizar(array $cru, Closure $ibge): array
    {
        return ['campos' => [
            'descricao' => Normaliza::texto($cru['descricao'] ?? null, 100) ?? throw new RegistroInvalido('Descrição da embalagem vazia.'),
        ], 'avisos' => []];
    }

    public function novo(array $campos, string $codigoErp, array $codigosUsados): array
    {
        $codigo = Normaliza::codigoEmbalagem($campos['descricao']) ?: "ERP_{$codigoErp}";
        if (isset($codigosUsados[$codigo])) {
            $codigo = substr($codigo, 0, 30 - strlen("_{$codigoErp}"))."_{$codigoErp}";
        }

        $nome = Normaliza::chave($campos['descricao']);
        $granel = str_contains($nome, 'GRANEL');

        return [
            'codigo' => $codigo,
            'ativo' => true,
            'granel' => $granel,
            'permite_paletizar' => ! $granel,
            'permite_remontar' => false,
            'fracionada' => ! $granel && (bool) preg_match('/BOMBONA|TAMBOR|GALAO/', $nome),
            'revisao_pendente' => true,
        ];
    }
}
