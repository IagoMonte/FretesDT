<?php

namespace App\Jobs;

use App\Models\Notificacao;
use App\Services\Notificacao\NotificacaoService;
use App\Support\Fmt;
use App\Support\LinkProfundo;
use App\Services\Notificacao\Whatsapp\WhatsappFalhaDefinitiva;
use App\Services\Notificacao\Whatsapp\WhatsappGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Envia 1 template de WhatsApp. 4xx = falha definitiva (não repete); 5xx/429/timeout = relança e a fila tenta de novo com backoff.
 */
class EnviarWhatsappJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public function __construct(public string $notificacaoId)
    {
        $this->onQueue('whatsapp');
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 1800];
    }

    /** Limite por minuto do WhatsApp (config/whatsapp.php): o que passar do limite volta para a fila em vez de falhar. */
    public function middleware(): array
    {
        return [new RateLimited('whatsapp')];
    }

    public function handle(WhatsappGateway $gateway): void
    {
        $n = Notificacao::find($this->notificacaoId);
        if (! $n || $n->status !== 'PENDENTE') {
            return;
        }
        $n->tentativas++;
        try {
            $idExterno = $gateway->enviar((string) $n->endereco, (string) $n->template, $this->parametros($n));
        } catch (WhatsappFalhaDefinitiva $e) {
            app(NotificacaoService::class)->marcarFalha($n->id, $e);

            return;
        } catch (Throwable $e) {
            $n->forceFill(['erro' => mb_substr($e->getMessage(), 0, 500)])->save();
            throw $e;
        }
        $n->forceFill(['status' => 'ENVIADO', 'erro' => null, 'id_externo' => mb_substr($idExterno, 0, 100), 'enviado_em' => now()])->save();
    }

    public function failed(Throwable $e): void
    {
        app(NotificacaoService::class)->marcarFalha($this->notificacaoId, $e);
    }

    /** @return array<int, string> */
    private function parametros(Notificacao $n): array
    {
        $payload = (array) json_decode((string) $n->payload, true);
        $payload['codigo'] = $n->registro_codigo;
        $payload['link'] = LinkProfundo::para($n);
        foreach (['valor_tonelada' => 'dinheiroWhatsapp', 'kg' => 'pesoWhatsapp'] as $chave => $formatador) {
            if (isset($payload[$chave]) && ! is_array($payload[$chave])) {
                $payload[$chave] = Fmt::$formatador($payload[$chave]);
            }
        }

        return array_map(function ($chave) use ($payload) {
            $v = $payload[$chave] ?? '-';

            return is_array($v) ? implode(' | ', $v) : (string) $v;
        }, config("notificacoes.templates.{$n->template}", ['codigo']));
    }
}
