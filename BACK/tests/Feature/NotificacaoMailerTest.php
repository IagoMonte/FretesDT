<?php

namespace Tests\Feature;

use App\Mail\Transport\MicrosoftAuthException;
use App\Mail\Transport\MicrosoftTokenProvider;
use App\Mail\Transport\MicrosoftTransport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/** Provedores de e-mail: Brevo (SMTP) e Microsoft 365 (OAuth2 ou senha de aplicativo). Nada aqui fala com a rede de verdade. */
class NotificacaoMailerTest extends TestCase
{
    private const TOKEN_URL = 'login.microsoftonline.com/tenant-1/oauth2/v2.0/token';

    private function config(array $extra = []): array
    {
        return $extra + [
            'transport' => 'microsoft', 'auth' => 'oauth2', 'host' => 'smtp.office365.com', 'port' => 587,
            'username' => 'noreply@exemplo.com.br', 'password' => null,
            'tenant_id' => 'tenant-1', 'client_id' => 'cid', 'client_secret' => 'segredo', 'refresh_token' => null,
        ];
    }

    private function token(string $valor = 'tok-1', int $expira = 3599): \Illuminate\Http\Client\Response|\GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response(['access_token' => $valor, 'expires_in' => $expira, 'token_type' => 'Bearer']);
    }

    /** Transporte falso que registra o que recebeu. */
    private function interno(): object
    {
        return new class implements TransportInterface
        {
            public array $enviados = [];

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                $this->enviados[] = $message;

                return new SentMessage($message, $envelope ?? Envelope::create($message));
            }

            public function __toString(): string
            {
                return 'fake://';
            }
        };
    }

    private function email(): Email
    {
        return (new Email)->from('noreply@exemplo.com.br')->to('dest@cliente.com')->subject('[Fretes COT-1] teste')->text('corpo');
    }

    /** @return array{0: MicrosoftTransport, 1: object, 2: \ArrayObject} */
    private function transporte(array $config): array
    {
        $inner = $this->interno();
        $capturas = new \ArrayObject;
        $fabrica = function (string $host, int $porta, string $usuario, string $senha) use ($inner, $capturas) {
            $capturas->append(compact('host', 'porta', 'usuario', 'senha'));

            return $inner;
        };

        return [new MicrosoftTransport($config, new MicrosoftTokenProvider($config), $fabrica), $inner, $capturas];
    }


    public function test_token_por_client_credentials_e_reaproveitado_enquanto_vale(): void
    {
        Http::fake([self::TOKEN_URL => $this->token('tok-1')]);
        $provider = new MicrosoftTokenProvider($this->config());

        $this->assertSame('tok-1', $provider->token());
        $this->assertSame('tok-1', $provider->token());

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->url() === 'https://'.self::TOKEN_URL
            && $r['grant_type'] === 'client_credentials' && $r['client_id'] === 'cid' && $r['client_secret'] === 'segredo'
            && $r['scope'] === 'https://outlook.office365.com/.default');
    }

    public function test_token_por_refresh_token_quando_configurado(): void
    {
        Http::fake([self::TOKEN_URL => $this->token('tok-delegado')]);

        $token = (new MicrosoftTokenProvider($this->config(['refresh_token' => 'rt-123'])))->token();

        $this->assertSame('tok-delegado', $token);
        Http::assertSent(fn ($r) => $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'rt-123'
            && $r['scope'] === 'https://outlook.office.com/SMTP.Send offline_access');
    }

    public function test_token_expira_antes_do_prazo_da_microsoft(): void
    {
        Http::fake([self::TOKEN_URL => Http::sequence()->push(['access_token' => 'tok-1', 'expires_in' => 3599])->push(['access_token' => 'tok-2', 'expires_in' => 3599])]);
        $provider = new MicrosoftTokenProvider($this->config());

        $this->assertSame('tok-1', $provider->token());
        $this->travel(59)->minutes();
        $this->assertSame('tok-2', $provider->token());
    }

    public function test_erro_da_microsoft_vira_excecao_legivel_e_nao_fica_em_cache(): void
    {
        Http::fake([self::TOKEN_URL => Http::sequence()
            ->push(['error' => 'invalid_client', 'error_description' => 'AADSTS7000215: Invalid client secret provided.'], 401)
            ->push(['access_token' => 'tok-ok', 'expires_in' => 3599])]);
        $provider = new MicrosoftTokenProvider($this->config());

        try {
            $provider->token();
            $this->fail('esperava MicrosoftAuthException');
        } catch (MicrosoftAuthException $e) {
            $this->assertStringContainsString('AADSTS7000215', $e->getMessage());
            $this->assertStringNotContainsString('segredo', $e->getMessage(), 'o segredo nunca vai para a mensagem');
        }

        $this->assertSame('tok-ok', $provider->token());
    }

    public function test_sem_credenciais_a_excecao_diz_qual_variavel_falta(): void
    {
        Http::fake();

        try {
            (new MicrosoftTokenProvider($this->config(['tenant_id' => null])))->token();
            $this->fail('esperava MicrosoftAuthException');
        } catch (MicrosoftAuthException $e) {
            $this->assertStringContainsString('MICROSOFT_MAIL_TENANT_ID', $e->getMessage());
        }
        Http::assertNothingSent();
    }


    public function test_oauth2_manda_o_token_como_senha_do_smtp(): void
    {
        Http::fake([self::TOKEN_URL => $this->token('tok-1')]);
        [$transporte, $inner, $capturas] = $this->transporte($this->config());

        $transporte->send($this->email());

        $this->assertSame(
            ['host' => 'smtp.office365.com', 'porta' => 587, 'usuario' => 'noreply@exemplo.com.br', 'senha' => 'tok-1'],
            $capturas[0],
        );
        $this->assertCount(1, $inner->enviados);
        $this->assertSame('[Fretes COT-1] teste', $inner->enviados[0]->getSubject());
    }

    public function test_cada_envio_pega_o_token_vigente_sem_reconstruir_o_mailer(): void
    {
        Http::fake([self::TOKEN_URL => Http::sequence()->push(['access_token' => 'tok-1', 'expires_in' => 3599])->push(['access_token' => 'tok-2', 'expires_in' => 3599])]);
        [$transporte, , $capturas] = $this->transporte($this->config());

        $transporte->send($this->email());
        $transporte->send($this->email());
        $this->assertSame(['tok-1', 'tok-1'], array_column($capturas->getArrayCopy(), 'senha'), 'dentro da validade, um token só');

        Cache::flush();
        $transporte->send($this->email());
        $this->assertSame('tok-2', $capturas[2]['senha'], 'worker de longa duração renova o token expirado');
    }

    public function test_senha_de_aplicativo_nao_chama_a_microsoft_para_token(): void
    {
        Http::fake();
        [$transporte, $inner, $capturas] = $this->transporte($this->config(['auth' => 'app_password', 'password' => 'abcd efgh ijkl', 'tenant_id' => null, 'client_id' => null, 'client_secret' => null]));

        $transporte->send($this->email());

        Http::assertNothingSent();
        $this->assertSame('abcd efgh ijkl', $capturas[0]['senha']);
        $this->assertCount(1, $inner->enviados);
    }

    public function test_senha_de_aplicativo_sem_senha_falha_com_mensagem_clara(): void
    {
        [$transporte] = $this->transporte($this->config(['auth' => 'app_password', 'password' => null]));

        $this->expectException(MicrosoftAuthException::class);
        $this->expectExceptionMessage('MAIL_PASSWORD');
        $transporte->send($this->email());
    }

    public function test_modo_de_autenticacao_desconhecido_e_recusado(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('oauth2');
        new MicrosoftTransport($this->config(['auth' => 'basic']), new MicrosoftTokenProvider($this->config()));
    }


    public function test_mailer_microsoft_esta_registrado_no_laravel(): void
    {
        config(['mail.mailers.microsoft' => $this->config()]);

        $transporte = app('mail.manager')->mailer('microsoft')->getSymfonyTransport();

        $this->assertInstanceOf(MicrosoftTransport::class, $transporte);
        $this->assertStringContainsString('smtp.office365.com', (string) $transporte);
    }

    public function test_config_padrao_do_mailer_microsoft(): void
    {
        $m = config('mail.mailers.microsoft');

        $this->assertSame('microsoft', $m['transport']);
        $this->assertSame('oauth2', $m['auth']);
        $this->assertSame('smtp.office365.com', $m['host']);
        $this->assertSame(587, $m['port']);
    }

    public function test_brevo_e_um_mailer_smtp_pronto_para_so_preencher_login_e_chave(): void
    {
        config(['mail.mailers.brevo.username' => 'login@smtp-brevo.com', 'mail.mailers.brevo.password' => 'xsmtpsib-chave']);

        $transporte = app('mail.manager')->mailer('brevo')->getSymfonyTransport();

        $this->assertInstanceOf(EsmtpTransport::class, $transporte);
        $this->assertSame('smtp-relay.brevo.com', $transporte->getStream()->getHost());
        $this->assertSame(587, $transporte->getStream()->getPort());
        $this->assertSame('login@smtp-brevo.com', $transporte->getUsername());
        $this->assertSame('xsmtpsib-chave', $transporte->getPassword());
    }

    public function test_failover_padrao_tenta_smtp_e_cai_no_log_mas_a_ordem_vem_do_env(): void
    {
        $this->assertSame(['smtp', 'log'], config('mail.mailers.failover.mailers'));
        $this->assertSame(['brevo', 'smtp', 'log'], \App\Mail\Transport\MailerChain::parse('brevo, smtp ,log,,'));
        $this->assertSame(['log'], \App\Mail\Transport\MailerChain::parse(''), 'lista vazia nunca deixa o sistema sem destino');
    }


    public function test_graph_pede_token_com_escopo_do_graph(): void
    {
        Http::fake([self::TOKEN_URL => $this->token('tok-g')]);
        $config = $this->config(['auth' => 'graph']);

        $this->assertSame('tok-g', (new MicrosoftTokenProvider($config))->token());
        Http::assertSent(fn ($r) => $r['scope'] === 'https://graph.microsoft.com/.default' && $r['grant_type'] === 'client_credentials');
    }

    public function test_graph_envia_mime_em_base64_pela_caixa_autorizada(): void
    {
        Http::fake([
            self::TOKEN_URL => $this->token('tok-g'),
            'graph.microsoft.com/*' => Http::response('', 202),
        ]);
        $config = $this->config(['auth' => 'graph']);
        $transporte = new MicrosoftTransport($config, new MicrosoftTokenProvider($config));

        $transporte->send($this->email());

        Http::assertSent(function ($r) {
            return $r->url() === 'https://graph.microsoft.com/v1.0/users/noreply%40exemplo.com.br/sendMail'
                && $r->hasHeader('Authorization', 'Bearer tok-g')
                && $r->hasHeader('Content-Type', 'text/plain')
                && str_contains(base64_decode($r->body()), 'Subject: [Fretes COT-1] teste');
        });
    }

    public function test_graph_recusado_vira_excecao_com_o_motivo(): void
    {
        Http::fake([
            self::TOKEN_URL => $this->token('tok-g'),
            'graph.microsoft.com/*' => Http::response(['error' => ['code' => 'ErrorAccessDenied', 'message' => 'Access is denied']], 403),
        ]);
        $config = $this->config(['auth' => 'graph']);
        $transporte = new MicrosoftTransport($config, new MicrosoftTokenProvider($config));

        $this->expectException(\Symfony\Component\Mailer\Exception\TransportException::class);
        $this->expectExceptionMessage('ErrorAccessDenied');
        $transporte->send($this->email());
    }
}
