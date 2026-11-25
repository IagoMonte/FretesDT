<?php

namespace App\Services\Notificacao;

/** Avisos da requisição (ex.: transportadora sem WhatsApp) que o controller devolve em `meta.alertas`. Um por requisição (scoped). */
class NotificacaoAlertas
{
    /** @var array<int, string> */
    private array $alertas = [];

    public function add(string $alerta): void
    {
        if (! in_array($alerta, $this->alertas, true)) {
            $this->alertas[] = $alerta;
        }
    }

    /** @return array<int, string> */
    public function todos(): array
    {
        return $this->alertas;
    }
}
