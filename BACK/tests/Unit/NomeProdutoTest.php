<?php

namespace Tests\Unit;

use App\Support\NomeProduto;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NomeProdutoTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function casos(): array
    {
        $soda = 'HIDROXIDO DE SODIO SOLUCAO 49%';

        return [
            'local no fim' => ['HIDROXIDO DE SODIO SOLUCAO 49% PORTO ALFA', $soda],
            'local com duas palavras' => ['HIDROXIDO DE SODIO SOLUCAO 49% VILA NORTE', $soda],
            'local e importado' => ['HIDROXIDO DE SODIO SOLUCAO 49% TERMINAL GAMA IMP', $soda],
            'prefixo de estoque' => ['STK HIDROXIDO DE SODIO SOLUCAO 49%', $soda],
            'membrana no meio' => ['HIDROXIDO DE SODIO SOLUCAO ESTOQUE 49% PORTO GAMA', $soda],
            'estoque e membrana' => ['HIDROXIDO DE SODIO SOLUCAO 49% ESTOQUE - ESTOQUE', $soda],
            'marca no fim' => ['HIDROXIDO DE SODIO SOLUCAO 49% DTX', $soda],
            'sem concentracao vira a padrao' => ['HIDROXIDO DE SODIO SOLUCAO ESTOQUE - T', $soda],
            'sem concentracao com local' => ['HIDROXIDO DE SODIO SOLUCAO CIDADE DELTA - T', $soda],
            'cliente entre parenteses' => ['ACIDO SULFURICO 98% PORTO GAMA (CLIENTE EXEMPLO)', 'ACIDO SULFURICO 98%'],
            'cidade com varias palavras' => ['ACIDO CLORIDRICO 33% SAO JOAO DO SUL', 'ACIDO CLORIDRICO 33%'],
            'dolar' => ['STK ACIDO FOSFORICO ALIMENTICIO 85% - DOLAR', 'ACIDO FOSFORICO ALIMENTICIO 85%'],
            'forma diferente' => ['HIDROXIDO DE SODIO SOLIDO ESCAMAS 99% IMP. ESTOQUE', 'HIDROXIDO DE SODIO SOLIDO ESCAMAS 99%'],
            'concentracao diferente' => ['HIDROXIDO DE SODIO SOLUCAO 30% - DTX', 'HIDROXIDO DE SODIO SOLUCAO 30%'],
            'variante entre parenteses' => ['CARBONATO DE SODIO 99% (BARRILHA LEVE) ESTOQUE', 'CARBONATO DE SODIO 99% (BARRILHA LEVE)'],
            'variante agricola' => ['UREIA 46% AGRICOLA CIDADE OMEGA', 'UREIA 46% AGRICOLA'],
            'sem nada para tirar mantem o original' => ['SERVIÇO DE FRETE', 'SERVIÇO DE FRETE'],
            'produto proprio com traco' => ['DTX - ESPUMA ALFA', 'DTX - ESPUMA ALFA'],
        ];
    }

    #[DataProvider('casos')]
    public function test_nome_base(string $erp, string $esperado): void
    {
        $this->assertSame($esperado, NomeProduto::base($erp));
    }

    public function test_variantes_da_mesma_mercadoria_tem_a_mesma_chave(): void
    {
        $this->assertSame(NomeProduto::chave('HIDROXIDO DE SODIO SOLUCAO 49% PORTO BETA'), NomeProduto::chave('STK HIDROXIDO DE SODIO SOLUCAO 49%'));
        $this->assertNotSame(NomeProduto::chave('HIDROXIDO DE SODIO SOLUCAO 49%'), NomeProduto::chave('HIDROXIDO DE SODIO SOLUCAO 30%'));
    }
}
