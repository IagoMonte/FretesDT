<?php

namespace Tests\Unit;

use App\Support\Dinheiro;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** v1.1 item 2: frete = valor_tonelada × Σ kg ÷ 1000, HALF_UP, 2 casas, sem float. */
class FreteCalculoTest extends TestCase
{
    public static function casos(): array
    {
        return [
            'exato' => ['90', '10000', '900.00'],
            'decimais' => ['100.50', '25000', '2512.50'],
            'half_up_sobe' => ['0.01', '500', '0.01'],
            'abaixo_da_metade' => ['0.01', '499', '0.00'],
            'tres_casas_kg' => ['123.45', '1234.567', '152.41'],
            'half_up_exato' => ['1.01', '2500', '2.53'],
            'zero' => ['0', '1000', '0.00'],
            'float_do_json' => [90.1, 3000, '270.30'],
            'inteiro' => [180, 12000, '2160.00'],
            'notacao_cientifica' => [1.0E-5, 1000, '0.00'],
        ];
    }

    #[DataProvider('casos')]
    public function test_frete(string|int|float $valor, string|int|float $kg, string $esperado): void
    {
        $this->assertSame($esperado, Dinheiro::frete($valor, $kg));
    }

    public function test_soma_de_itens_com_decimais_nao_perde_precisao(): void
    {
        $this->assertSame('0.300', Dinheiro::soma(['0.1', '0.2']));
        $this->assertSame('12000.500', Dinheiro::soma(['5000.250', '7000.250', null, '']));
        $this->assertSame('0.000', Dinheiro::soma([]));
        $this->assertSame('1.001', Dinheiro::soma(['1.0005']));
    }

    public function test_frete_sobre_a_soma_dos_itens(): void
    {
        $kg = Dinheiro::soma(['3333.333', '3333.333', '3333.334']);

        $this->assertSame('10000.000', $kg);
        $this->assertSame('1234.00', Dinheiro::frete('123.40', $kg));
    }

    public function test_dec_arredonda_half_up(): void
    {
        $this->assertSame('90.01', Dinheiro::dec('90.005', 2));
        $this->assertSame('90.00', Dinheiro::dec('90.004', 2));
        $this->assertNull(Dinheiro::dec(null, 2));
        $this->assertNull(Dinheiro::dec('', 2));
    }

    public function test_maior_compara_decimais(): void
    {
        $this->assertTrue(Dinheiro::maior('10000.001', '10000'));
        $this->assertFalse(Dinheiro::maior('10', '10.000'));
    }
}
