<?php

return [
    'driver' => env('WHATSAPP_DRIVER', 'log'),
    'evolution' => [
        'url' => env('WHATSAPP_EVOLUTION_URL', 'http://127.0.0.1:8181'),
        'apikey' => env('WHATSAPP_EVOLUTION_APIKEY'),
        'instance' => env('WHATSAPP_EVOLUTION_INSTANCE', 'fretesdt'),
        'timeout' => (int) env('WHATSAPP_EVOLUTION_TIMEOUT', 10),
    ],
    'token' => env('WHATSAPP_TOKEN'),
    'app_secret' => env('WHATSAPP_APP_SECRET'),
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    'waba_id' => env('WHATSAPP_WABA_ID'),
    'template_lang' => env('WHATSAPP_TEMPLATE_LANG', 'pt_BR'),
    'timeout' => (int) env('WHATSAPP_TIMEOUT', 10),
    'limite_por_minuto' => (int) env('WHATSAPP_LIMITE_POR_MINUTO', 60),
];
