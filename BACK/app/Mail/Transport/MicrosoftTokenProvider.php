<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Access token OAuth2 do Microsoft 365 para o SMTP (XOAUTH2), guardado no cache até perto de vencer.
 * Com `refresh_token` usa o fluxo delegado (SMTP.Send); sem ele, client credentials (app registrado no Entra ID).
 */
class MicrosoftTokenProvider
{
    private const FOLGA_SEGUNDOS = 300;

    public function __construct(private array $config) {}

    public function token(): string
    {
        $this->exigirConfig();
        $chave = 'microsoft-mail-token:'.md5(($this->config['tenant_id'] ?? '').($this->config['client_id'] ?? '').($this->delegado() ? 'rt' : 'cc').($this->graph() ? 'g' : ''));
        if ($token = Cache::get($chave)) {
            return $token;
        }

        $r = Http::asForm()->timeout(10)->post("https://login.microsoftonline.com/{$this->config['tenant_id']}/oauth2/v2.0/token", $this->corpo());
        $token = $r->successful() ? $r->json('access_token') : null;
        if (! $token) {
            $motivo = mb_substr((string) ($r->json('error_description') ?? $r->json('error') ?? 'resposta sem access_token'), 0, 300);
            throw new MicrosoftAuthException("A Microsoft recusou o token OAuth2 (HTTP {$r->status()}): {$motivo}");
        }
        Cache::put($chave, $token, max(60, (int) $r->json('expires_in', 3600) - self::FOLGA_SEGUNDOS));

        return $token;
    }

    private function graph(): bool
    {
        return ($this->config['auth'] ?? null) === 'graph';
    }

    private function delegado(): bool
    {
        return ! empty($this->config['refresh_token']);
    }

    private function corpo(): array
    {
        $base = ['client_id' => $this->config['client_id'], 'client_secret' => $this->config['client_secret']];

        return $this->delegado()
            ? $base + ['grant_type' => 'refresh_token', 'refresh_token' => $this->config['refresh_token'], 'scope' => 'https://outlook.office.com/SMTP.Send offline_access']
            : $base + ['grant_type' => 'client_credentials', 'scope' => $this->graph() ? 'https://graph.microsoft.com/.default' : 'https://outlook.office365.com/.default'];
    }

    private function exigirConfig(): void
    {
        foreach (['tenant_id' => 'MICROSOFT_MAIL_TENANT_ID', 'client_id' => 'MICROSOFT_MAIL_CLIENT_ID', 'client_secret' => 'MICROSOFT_MAIL_CLIENT_SECRET'] as $campo => $env) {
            if (empty($this->config[$campo])) {
                throw new MicrosoftAuthException("Configure {$env} no .env para usar o OAuth2 da Microsoft.");
            }
        }
    }
}
