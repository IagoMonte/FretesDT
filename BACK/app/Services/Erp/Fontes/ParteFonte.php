<?php

namespace App\Services\Erp\Fontes;

use App\Services\Erp\RegistroInvalido;
use App\Support\Fmt;
use App\Support\Normaliza;
use Closure;

/** Campos comuns de transportadora, cliente e fornecedor: a ponte usa o mesmo molde para os três. */
abstract class ParteFonte extends ErpFonte
{
    /** Campos que só a transportadora tem (e-mail, telefone). */
    protected function especificos(array $cru): array
    {
        return [];
    }

    public function codigoErp(array $cru): string
    {
        return (string) ($cru['codigo'] ?? '');
    }

    public function colunasLocais(): array
    {
        return ['cnpj'];
    }

    public function chavesLocais(object $linha): array
    {
        return [$linha->cnpj];
    }

    public function chaveCasamento(array $campos): ?string
    {
        return $campos['cnpj'];
    }

    /** O IBGE não existe no ERP: é resolvido aqui por cidade + UF (sem resolução = null e pendência). */
    private function cidadeUf(array $cru, Closure $ibge): array
    {
        $cidade = Normaliza::texto($cru['cidade'] ?? null, 100);
        $uf = Normaliza::uf($cru['uf'] ?? null);

        return ['cidade' => $cidade, 'uf' => $uf, 'cidade_ibge' => $cidade && $uf ? $ibge($cidade, $uf) : null];
    }

    public function normalizar(array $cru, Closure $ibge): array
    {
        $razao = Normaliza::texto($cru['razao_social'] ?? null, 200) ?? throw new RegistroInvalido('Razão social vazia.');
        $cnpj = Normaliza::cnpj($cru['cnpj'] ?? null);
        $avisos = match (true) {
            $cnpj === null => ['CNPJ vazio ou zerado no ERP: entra em branco e vai para revisão.'],
            ! Fmt::cnpjValido($cnpj) => ["CNPJ {$cnpj} com dígito verificador inválido."],
            default => [],
        };

        $situacao = (string) ($cru['situacao'] ?? '');
        $ativo = config('frete.erp.situacao')[$situacao] ?? null;
        if ($ativo === null) {
            $avisos[] = "Situação desconhecida '{$situacao}': cadastro inativado.";
        }

        return [
            'campos' => [
                'razao_social' => $razao,
                'nome_fantasia' => Normaliza::texto($cru['nome_fantasia'] ?? null, 150) ?? mb_substr($razao, 0, 150),
                'cnpj' => $cnpj,
                'revisao_pendente' => $cnpj === null,
            ] + $this->cidadeUf($cru, $ibge) + $this->especificos($cru) + ['ativo' => (bool) $ativo],
            'avisos' => $avisos,
        ];
    }
}
