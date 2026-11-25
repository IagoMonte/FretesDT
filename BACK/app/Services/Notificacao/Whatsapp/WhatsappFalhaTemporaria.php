<?php

namespace App\Services\Notificacao\Whatsapp;

/** 5xx, 429 ou timeout: o job tenta de novo com backoff. */
class WhatsappFalhaTemporaria extends \RuntimeException {}
