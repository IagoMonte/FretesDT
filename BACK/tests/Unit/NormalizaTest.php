<?php

namespace Tests\Unit;

use App\Support\Normaliza;
use PHPUnit\Framework\TestCase;

class NormalizaTest extends TestCase
{
    public function test_cnpj_recupera_o_zero_que_a_ponte_perdeu(): void
    {
        $this->assertSame('00200001000180', Normaliza::cnpj(200001000180));
        $this->assertSame('00123456000149', Normaliza::cnpj('123456000149'));
        $this->assertSame('99887766000105', Normaliza::cnpj('99.887.766/0001-05'));
        $this->assertNull(Normaliza::cnpj(0));
        $this->assertNull(Normaliza::cnpj('   '));
        $this->assertNull(Normaliza::cnpj(null));
        $this->assertNull(Normaliza::cnpj('123456789012345'));
    }

    public function test_texto_limpa_controle_espacos_e_vazio(): void
    {
        $this->assertNull(Normaliza::texto(' '));
        $this->assertNull(Normaliza::texto(null));
        $this->assertSame('KM 22,8', Normaliza::texto("  KM \t 22,8 \n"));
        $this->assertSame('ABC', Normaliza::texto('ABCDEF', 3));
    }

    public function test_uf_email_e_telefone(): void
    {
        $this->assertSame('SP', Normaliza::uf(' sp '));
        $this->assertNull(Normaliza::uf('XX'));
        $this->assertSame('a@b.com', Normaliza::email('invalido; A@B.COM, outro@x.com'));
        $this->assertNull(Normaliza::email(' '));
        $this->assertSame('1140007100', Normaliza::fone('(11)4000-7100'));
        $this->assertNull(Normaliza::fone(' '));
    }

    public function test_codigo_de_embalagem_e_ascii_maiusculo_com_underscore(): void
    {
        $this->assertSame('BIG_BAG', Normaliza::codigoEmbalagem('Big-Bag'));
        $this->assertSame('CONTAINER_S', Normaliza::codigoEmbalagem('CONTAINER(S)'));
        $this->assertSame('CONTAINERS', Normaliza::chave('Container(s)'));
    }
}
