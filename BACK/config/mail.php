<?php

return [


    'default' => env('MAIL_MAILER', 'log'),


    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'brevo' => [
            'transport' => 'smtp',
            'host' => env('BREVO_SMTP_HOST', 'smtp-relay.brevo.com'),
            'port' => (int) env('BREVO_SMTP_PORT', 587),
            'username' => env('BREVO_SMTP_LOGIN'),
            'password' => env('BREVO_SMTP_KEY'),
            'timeout' => 15,
        ],

        'microsoft' => [
            'transport' => 'microsoft',
            'auth' => env('MICROSOFT_MAIL_AUTH', 'oauth2'),
            'host' => env('MICROSOFT_MAIL_HOST', 'smtp.office365.com'),
            'port' => (int) env('MICROSOFT_MAIL_PORT', 587),
            'username' => env('MICROSOFT_MAIL_USERNAME'),
            'password' => env('MICROSOFT_MAIL_PASSWORD'),
            'tenant_id' => env('MICROSOFT_MAIL_TENANT_ID'),
            'client_id' => env('MICROSOFT_MAIL_CLIENT_ID'),
            'client_secret' => env('MICROSOFT_MAIL_CLIENT_SECRET'),
            'refresh_token' => env('MICROSOFT_MAIL_REFRESH_TOKEN'),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => \App\Mail\Transport\MailerChain::parse(env('MAIL_FAILOVER', 'smtp,log')),
            'retry_after' => 60,
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
            'retry_after' => 60,
        ],

    ],


    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', env('APP_NAME', 'Laravel')),
    ],

];
