<?php

namespace Tests\Support;

use App\Services\Notificacao\Whatsapp\WhatsappGateway;

/** Gateway de WhatsApp que só registra o que seria enviado: [telefone, template, parâmetros]. */
class GatewayFake implements WhatsappGateway
{
    /** @var array<int, array{0: string, 1: string, 2: array}> */
    public array $enviados = [];

    public function enviar(string $para, string $template, array $params): string
    {
        $this->enviados[] = [$para, $template, $params];

        return 'wamid.'.count($this->enviados);
    }

    /** @return array{0: string, 1: string, 2: array}|null */
    public function para(string $telefone, ?string $template = null): ?array
    {
        return collect($this->enviados)->first(fn ($e) => $e[0] === $telefone && ($template === null || $e[1] === $template));
    }
}
