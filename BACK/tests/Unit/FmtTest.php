<?php

namespace Tests\Unit;

use App\Support\Fmt;
use Carbon\Carbon;
use Tests\TestCase;

class FmtTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_hoje_usa_o_fuso_de_sao_paulo_e_nao_o_utc(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-06 02:30:00', 'UTC'));
        $this->assertSame('2026-10-05', Fmt::hoje());

        Carbon::setTestNow(Carbon::parse('2026-10-06 03:00:00', 'UTC'));
        $this->assertSame('2026-10-06', Fmt::hoje());
    }

    public function test_data_aceita_carbon_string_datetime_e_nulos(): void
    {
        $this->assertSame('2026-10-05', Fmt::data(Carbon::parse('2026-10-05 23:59:59')));
        $this->assertSame('2026-10-05', Fmt::data('2026-10-05'));
        $this->assertSame('2026-10-05', Fmt::data('2026-10-05 00:00:00'));
        $this->assertNull(Fmt::data(null));
        $this->assertNull(Fmt::data(''));
    }

    public function test_iso_converte_para_o_fuso_de_exibicao_com_milissegundos(): void
    {
        $d = Carbon::parse('2026-10-05 15:00:00.123', 'UTC');

        $this->assertSame('2026-10-05T12:00:00.123-03:00', Fmt::iso($d));
        $this->assertNull(Fmt::iso(null));
    }

    public function test_cnpj_so_digitos(): void
    {
        $this->assertSame('12345678000199', Fmt::cnpj('12.345.678/0001-99'));
        $this->assertNull(Fmt::cnpj(null));
    }

    public function test_dinheiro_do_whatsapp_sai_em_brl_com_duas_casas(): void
    {
        $this->assertSame('1.000,00', Fmt::dinheiroWhatsapp('1000'));
        $this->assertSame('180,00', Fmt::dinheiroWhatsapp('180.0000'));
        $this->assertSame('1.234.567,89', Fmt::dinheiroWhatsapp(1234567.891));
        $this->assertSame('0,50', Fmt::dinheiroWhatsapp('0.5'));
    }

    public function test_peso_do_whatsapp_inteiro_sem_casas_e_quebrado_com_duas(): void
    {
        $this->assertSame('1.000', Fmt::pesoWhatsapp('1000.000'));
        $this->assertSame('12.000', Fmt::pesoWhatsapp('12000'));
        $this->assertSame('15,34', Fmt::pesoWhatsapp('15.34'));
        $this->assertSame('1.234,50', Fmt::pesoWhatsapp('1234.500'));
        $this->assertSame('15,34', Fmt::pesoWhatsapp('15.3400'));
    }

    public function test_valor_nao_numerico_do_whatsapp_passa_intacto(): void
    {
        $this->assertSame('declinou', Fmt::dinheiroWhatsapp('declinou'));
        $this->assertSame('-', Fmt::pesoWhatsapp('-'));
    }
}
