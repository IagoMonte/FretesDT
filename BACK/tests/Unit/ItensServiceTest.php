<?php

namespace Tests\Unit;

use App\Services\ItensService;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** v1.1 item 1: granel não tem volumes. */
class ItensServiceTest extends TestCase
{
    private function item(string $embalagem, mixed $volumes = 'ausente', string $kg = '1000'): array
    {
        $produto = $this->produto('P'.uniqid(), ['BOMBONA', 'GRANEL_LIQUIDO', 'GRANEL_SOLIDO']);
        $item = ['produto_id' => $produto->id, 'embalagem_codigo' => $embalagem, 'quantidade_kg' => $kg];
        if ($volumes !== 'ausente') {
            $item['volumes'] = $volumes;
        }

        return $item;
    }

    public static function granel(): array
    {
        return [['GRANEL_LIQUIDO'], ['GRANEL_SOLIDO']];
    }

    #[DataProvider('granel')]
    public function test_granel_com_volumes_positivos_e_rejeitado(string $embalagem): void
    {
        try {
            app(ItensService::class)->itens([$this->item($embalagem, 5)]);
            $this->fail('Deveria lançar ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('itens.0.volumes', $e->errors());
        }
    }

    #[DataProvider('granel')]
    public function test_granel_com_zero_ausente_ou_nulo_grava_null(string $embalagem): void
    {
        foreach ([0, '0', null, 'ausente'] as $v) {
            $saida = app(ItensService::class)->itens([$this->item($embalagem, $v)]);
            $this->assertNull($saida[0]['volumes'], 'volumes='.var_export($v, true));
        }
    }

    public function test_erro_de_granel_aponta_o_indice_do_item(): void
    {
        try {
            app(ItensService::class)->itens([$this->item('BOMBONA', 3), $this->item('GRANEL_SOLIDO', 2)]);
            $this->fail('Deveria lançar ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame(['itens.1.volumes'], array_keys($e->errors()));
        }
    }

    public function test_embalagem_nao_granel_mantem_volumes_e_sequencia(): void
    {
        $saida = app(ItensService::class)->itens([$this->item('BOMBONA', 10), $this->item('BOMBONA', 0)]);

        $this->assertSame(10, $saida[0]['volumes']);
        $this->assertSame(0, $saida[1]['volumes']);
        $this->assertSame([1, 2], array_column($saida, 'sequencia'));
    }

    public function test_embalagem_nao_permitida_para_o_produto_e_rejeitada(): void
    {
        $produto = $this->produto('SO_BOMBONA', ['BOMBONA']);

        $this->expectException(ValidationException::class);
        app(ItensService::class)->itens([['produto_id' => $produto->id, 'embalagem_codigo' => 'GRANEL_LIQUIDO', 'quantidade_kg' => '1']]);
    }

    public function test_produto_inexistente_e_rejeitado(): void
    {
        $this->expectException(ValidationException::class);
        app(ItensService::class)->itens([['produto_id' => 'nao-existe', 'embalagem_codigo' => 'BOMBONA', 'quantidade_kg' => '1']]);
    }

    public function test_fracionada_calcula_paletes_por_kg_arredondando_para_cima(): void
    {
        \App\Models\Embalagem::create(['codigo' => 'FRAC', 'descricao' => 'Fracionada', 'ativo' => true, 'fracionada' => true]);
        $produto = $this->produto('PFRAC', ['FRAC']);
        \Illuminate\Support\Facades\DB::table('produto_embalagem')->where('produto_id', $produto->id)->update(['kg_por_volume' => '60', 'kg_por_palete' => '600']);
        $svc = app(ItensService::class);
        $item = fn (string $kg) => $svc->itens([['produto_id' => $produto->id, 'embalagem_codigo' => 'FRAC', 'quantidade_kg' => $kg, 'volumes' => 20]])[0];

        $this->assertSame(2, $item('1200')['paletes']);
        $this->assertSame(1, $item('600')['paletes']);
        $this->assertSame(3, $item('1201')['paletes']);
        $this->assertSame(20, $item('1200')['volumes']);
    }

    public function test_fracionada_sem_fator_de_palete_fica_sem_paletes_e_outras_embalagens_nunca_tem(): void
    {
        \App\Models\Embalagem::create(['codigo' => 'FRAC', 'descricao' => 'Fracionada', 'ativo' => true, 'fracionada' => true]);
        $produto = $this->produto('PFRAC', ['FRAC', 'BOMBONA']);
        $svc = app(ItensService::class);
        $r = $svc->itens([['produto_id' => $produto->id, 'embalagem_codigo' => 'FRAC', 'quantidade_kg' => '500', 'volumes' => 7]]);
        $this->assertNull($r[0]['paletes']);
        $this->assertSame(7, $r[0]['volumes']);
        $r = $svc->itens([['produto_id' => $produto->id, 'embalagem_codigo' => 'BOMBONA', 'quantidade_kg' => '500', 'volumes' => 7]]);
        $this->assertNull($r[0]['paletes']);
    }
}
