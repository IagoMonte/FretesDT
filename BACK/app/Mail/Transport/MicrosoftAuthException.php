<?php

namespace App\Mail\Transport;

/** Falha ao autenticar no e-mail da Microsoft (credencial ausente, token recusado). A mensagem nunca carrega segredo. */
class MicrosoftAuthException extends \RuntimeException {}
