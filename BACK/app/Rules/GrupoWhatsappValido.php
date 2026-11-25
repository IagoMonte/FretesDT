<?php

namespace App\Rules;

use App\Services\Notificacao\Whatsapp\EvolutionWhatsappGateway;
use App\Services\Notificacao\Whatsapp\WhatsappFalhaTemporaria;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** ID de grupo de WhatsApp (JID `<dígitos>@g.us`). Com driver Evolution, confirma que o grupo existe e que a instância participa dele. */
class GrupoWhatsappValido implements ValidationRule
{
    public const FORMATO = '/^\d{10,25}@g\.us$/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match(self::FORMATO, $value)) {
            $fail('Informe o ID do grupo no formato 120363123456789012@g.us.');

            return;
        }
        if (config('whatsapp.driver') !== 'evolution') {
            return;
        }
        try {
            if (! app(EvolutionWhatsappGateway::class)->grupoExiste($value)) {
                $fail('Grupo não encontrado na Evolution: confira o ID e se o número conectado participa do grupo.');
            }
        } catch (WhatsappFalhaTemporaria) {
            $fail('Não foi possível validar o grupo agora (Evolution sem resposta). Tente novamente em instantes.');
        }
    }
}
