<?php

namespace App\Services\Notificacao\Whatsapp;

/** Erro 4xx do provedor (número inválido, template não aprovado...): reenviar não resolve. */
class WhatsappFalhaDefinitiva extends \RuntimeException {}
