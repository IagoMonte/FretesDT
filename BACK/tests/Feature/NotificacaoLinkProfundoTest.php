<?php

namespace Tests\Feature;

use App\Mail\CotacaoEncaminhada;
use App\Models\Notificacao;
use App\Services\Notificacao\Whatsapp\WhatsappGateway;
use App\Support\LinkProfundo;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LinkProfundoWhatsappFake implements WhatsappGateway
{
    public array $enviados = [];

    public function enviar(string $para, string $template, array $params): string
    {
        $this->enviados[] = [$para, $template, $params];

        return 'wamid.1';
    }
}

/** o link da notificação abre direto o registro (`?abrir=<código>`), igual no e-mail e no WhatsApp. */
class NotificacaoLinkProfundoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['notificacoes.front_url' => 'https://fretes.example.test/']);
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function eventos(): array
    {
        $cot = 'https://fretes.example.test/quotes?abrir=COT-000001';
        $crg = 'https://fretes.example.test/loads?abrir=CRG-000007';

        return [
            'E1 encaminhada' => ['NEGOCIACAO_ENCAMINHADA', 'COT-000001', $cot],
            'E2 respondida' => ['NEGOCIACAO_RESPONDIDA', 'COT-000001', $cot],
            'E2 declinada' => ['NEGOCIACAO_DECLINADA', 'COT-000001', $cot],
            'E3 fechada' => ['NEGOCIACAO_FECHADA', 'COT-000001', $cot],
            'E4 cotação cancelada' => ['COTACAO_CANCELADA', 'COT-000001', $cot],
            'E4 negociação cancelada' => ['NEGOCIACAO_CANCELADA', 'COT-000001', $cot],
            'E5 status' => ['PROGRAMACAO_STATUS', 'CRG-000007', $crg],
            'E6 carga cancelada' => ['PROGRAMACAO_CANCELADA', 'CRG-000007', $crg],
            'E7 qtd excedida' => ['PROGRAMACAO_QTD_EXCEDIDA', 'CRG-000007', $crg],
            'E7 diverge' => ['PROGRAMACAO_DIVERGE_FECHAMENTO', 'CRG-000007', $crg],
        ];
    }

    #[DataProvider('eventos')]
    public function test_helper_monta_o_link_do_evento_com_o_codigo_do_registro(string $evento, string $codigo, string $esperado): void
    {
        $n = (new Notificacao)->forceFill(['evento' => $evento, 'registro_codigo' => $codigo]);

        $this->assertSame($esperado, LinkProfundo::para($n));
    }

    public function test_codigo_e_codificado_na_url(): void
    {
        $n = (new Notificacao)->forceFill(['evento' => 'NEGOCIACAO_ENCAMINHADA', 'registro_codigo' => 'COT 1&x=2']);

        $this->assertSame('https://fretes.example.test/quotes?abrir=COT%201%26x%3D2', LinkProfundo::para($n));
    }

    public function test_evento_sem_registro_cai_na_raiz_sem_query(): void
    {
        $n = (new Notificacao)->forceFill(['evento' => 'TESTE', 'registro_codigo' => '']);

        $this->assertSame('https://fretes.example.test', LinkProfundo::para($n));
    }

    public function test_email_e_whatsapp_levam_o_mesmo_link_profundo(): void
    {
        Mail::fake();
        $wa = new LinkProfundoWhatsappFake;
        $this->app->instance(WhatsappGateway::class, $wa);
        $admin = $this->usuario('admin', 'ADMINISTRADOR');
        $vendedor = $this->usuario('marcos', 'VENDEDOR');
        $t = $this->transportadora('TRANSLOG');
        $t->update(['email' => 'translog@t.test', 'telefone' => '(19) 99999-0001']);
        $this->usuario('translog', 'TRANSPORTADORA', $t);
        $id = $this->como($vendedor)->postJson('/api/cotacoes', $this->corpoCotacao($this->cliente(), $this->produto()))->assertCreated()['data']['id'];

        $this->como($admin)->postJson("/api/cotacoes/{$id}/encaminhar", ['transportadora_ids' => [$t->id]])->assertOk();

        $codigo = Notificacao::where('canal', 'EMAIL')->value('registro_codigo');
        $link = 'https://fretes.example.test/quotes?abrir='.$codigo;
        Mail::assertSent(CotacaoEncaminhada::class, function ($m) use ($link) {
            $this->assertSame($link, $m->content()->with['link']);
            $this->assertStringContainsString('href="'.$link.'"', $m->render());

            return true;
        });
        $this->assertCount(1, $wa->enviados);
        $this->assertSame($link, end($wa->enviados[0][2]), 'o link é o último parâmetro do template');
    }
}
