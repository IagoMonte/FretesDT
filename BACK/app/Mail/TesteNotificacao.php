<?php

namespace App\Mail;

use App\Models\Notificacao;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** E-mail de teste disparado pela tela de notificações (confere SMTP e remetente). */
class TesteNotificacao extends Mailable
{
    public function __construct(public Notificacao $notificacao) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[Fretes] Teste de notificação');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.notificacao',
            with: [
                'titulo' => 'Teste de notificação',
                'secao' => 'Notificações', 'etiqueta' => 'Teste', 'pill' => 'claro', 'data' => now()->format('d/m/Y'),
                'chamada' => 'Se você recebeu este e-mail, o envio está funcionando.', 'botao' => 'Abrir no sistema',
                'codigo' => 'TESTE',
                'nome' => $this->notificacao->destino_nome,
                'linhas' => ['Enviado por' => $this->notificacao->destino_nome, 'Quando' => now()->format('d/m/Y H:i')],
                'link' => rtrim((string) config('notificacoes.front_url'), '/'),
            ],
        );
    }
}
