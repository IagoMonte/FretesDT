<?php

namespace App\Jobs;

use App\Models\Notificacao;
use App\Services\Notificacao\NotificacaoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Envia 1 e-mail de notificação (1 destinatário, nunca cc coletivo). Marca ENVIADO ou, esgotadas as tentativas, FALHA. */
class EnviarEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct(public string $notificacaoId)
    {
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        $n = Notificacao::find($this->notificacaoId);
        if (! $n || $n->status !== 'PENDENTE') {
            return;
        }
        $mailable = config("notificacoes.eventos.{$n->evento}.mailable");
        $n->tentativas++;
        try {
            Mail::to($n->endereco)->send(new $mailable($n));
        } catch (Throwable $e) {
            $n->erro = mb_substr($e->getMessage(), 0, 500);
            $n->save();
            throw $e;
        }
        $n->forceFill(['status' => 'ENVIADO', 'erro' => null, 'enviado_em' => now()])->save();
    }

    public function failed(Throwable $e): void
    {
        app(NotificacaoService::class)->marcarFalha($this->notificacaoId, $e);
    }
}
