<?php

namespace App\Mail;

use App\Models\Notificacao;
use App\Support\LinkProfundo;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Base dos e-mails de notificação. O envio já é feito dentro de um job na fila `emails`, então o Mailable em si é síncrono.
 * Assunto e linhas vêm de config/notificacoes.php + payload da notificação.
 */
abstract class NotificacaoMail extends Mailable
{
    public function __construct(public Notificacao $notificacao) {}

    public function envelope(): Envelope
    {
        $assunto = config("notificacoes.eventos.{$this->notificacao->evento}.assunto", 'Notificação');

        return new Envelope(subject: "[Fretes {$this->notificacao->registro_codigo}] {$assunto}");
    }

    public function content(): Content
    {
        $n = $this->notificacao;
        $rotulos = config('notificacoes.rotulos');
        $linhas = [];
        foreach ((array) json_decode((string) $n->payload, true) as $chave => $valor) {
            $linhas[$rotulos[$chave] ?? $chave] = is_array($valor) ? implode(' | ', $valor) : (string) $valor;
        }
        $visual = (array) config("notificacoes.email.{$n->evento}", []);

        return new Content(
            view: 'emails.notificacao',
            with: [
                ...$visual,
                'titulo' => config("notificacoes.eventos.{$n->evento}.assunto", 'Notificação'),
                'data' => \Illuminate\Support\Carbon::parse($n->created_at ?? now())->format('d/m/Y'),
                'codigo' => $n->registro_codigo,
                'nome' => $n->destino_nome,
                'linhas' => $linhas,
                'link' => LinkProfundo::para($n),
            ],
        );
    }
}
