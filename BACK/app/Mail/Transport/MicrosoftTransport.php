<?php

namespace App\Mail\Transport;

use Closure;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * SMTP do Microsoft 365 com OAuth2 (o token vai como senha: o Symfony usa XOAUTH2) ou com senha de aplicativo.
 * Em `graph` não há SMTP: o MIME vai pela API Microsoft Graph (permissão de aplicativo Mail.Send) em nome da caixa configurada.
 * O SMTP interno é montado a cada envio: assim um worker de longa duração sempre usa o token vigente.
 */
class MicrosoftTransport extends AbstractTransport
{
    private Closure $fabrica;

    public function __construct(private array $config, private MicrosoftTokenProvider $tokens, ?Closure $fabrica = null)
    {
        parent::__construct();
        if (! in_array($config['auth'] ?? null, ['oauth2', 'app_password', 'graph'], true)) {
            throw new \InvalidArgumentException('MICROSOFT_MAIL_AUTH deve ser oauth2, app_password ou graph.');
        }
        $this->fabrica = $fabrica ?? static function (string $host, int $porta, string $usuario, string $senha): TransportInterface {
            $smtp = new EsmtpTransport($host, $porta);
            $smtp->setUsername($usuario);
            $smtp->setPassword($senha);
            $smtp->getStream()->setTimeout(15);

            return $smtp;
        };
    }

    protected function doSend(SentMessage $message): void
    {
        if ($this->config['auth'] === 'graph') {
            $this->enviarPeloGraph($message);

            return;
        }
        $smtp = ($this->fabrica)(
            (string) ($this->config['host'] ?? 'smtp.office365.com'),
            (int) ($this->config['port'] ?? 587),
            (string) $this->config['username'],
            $this->senha(),
        );
        $smtp->send($message->getOriginalMessage(), $message->getEnvelope());
    }

    private function enviarPeloGraph(SentMessage $message): void
    {
        $caixa = rawurlencode((string) $this->config['username']);
        $r = Http::withToken($this->tokens->token())->timeout(15)
            ->withBody(base64_encode($message->toString()), 'text/plain')
            ->post("https://graph.microsoft.com/v1.0/users/{$caixa}/sendMail");
        if (! $r->successful()) {
            $motivo = mb_substr((string) ($r->json('error.code').': '.$r->json('error.message')), 0, 300);
            throw new TransportException("Microsoft Graph recusou o envio (HTTP {$r->status()}): {$motivo}");
        }
    }

    private function senha(): string
    {
        if ($this->config['auth'] === 'oauth2') {
            return $this->tokens->token();
        }
        if (empty($this->config['password'])) {
            throw new MicrosoftAuthException('Defina MICROSOFT_MAIL_PASSWORD (senha de aplicativo) no .env.');
        }

        return (string) $this->config['password'];
    }

    public function __toString(): string
    {
        if ($this->config['auth'] === 'graph') {
            return 'microsoft+graph://graph.microsoft.com';
        }

        return sprintf('microsoft://%s:%d', $this->config['host'] ?? 'smtp.office365.com', $this->config['port'] ?? 587);
    }
}
