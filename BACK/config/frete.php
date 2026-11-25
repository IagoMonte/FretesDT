<?php

return [

    'fuso_exibicao' => 'America/Sao_Paulo',

    'jwt' => [
        'chave_privada' => env('JWT_PRIVATE_KEY_PATH', 'storage/keys/jwt_private.pem'),
        'chave_publica' => env('JWT_PUBLIC_KEY_PATH', 'storage/keys/jwt_public.pem'),
        'emissor' => env('APP_URL', 'http://127.0.0.1:8000'),
        'kid' => env('JWT_KID', 'frete-1'),
        'access_segundos' => (int) env('JWT_ACCESS_SEGUNDOS', 900),
        'refresh_dias' => (int) env('JWT_REFRESH_DIAS', 7),
        'refresh_graca_segundos' => (int) env('JWT_REFRESH_GRACA_SEGUNDOS', 10),
    ],

    'login_tentativas_por_minuto' => (int) env('FRETE_LOGIN_TENTATIVAS', 5),

    'cadastro_tentativas_por_hora' => (int) env('FRETE_CADASTRO_TENTATIVAS', 10),

    'limites' => [
        'valor_tonelada_max' => (float) env('FRETE_VALOR_TONELADA_MAX', 100000),
        'valor_frete_max' => (float) env('FRETE_VALOR_FRETE_MAX', 10000000),
        'quantidade_kg_max' => (float) env('FRETE_QUANTIDADE_KG_MAX', 999999999),
        'valor_nf_max' => (float) env('FRETE_VALOR_NF_MAX', 100000000),
        'distancia_km_max' => (int) env('FRETE_DISTANCIA_KM_MAX', 10000),
        'prazo_dias_max' => (int) env('FRETE_PRAZO_DIAS_MAX', 90),
        'prazo_coleta_dias_max' => (int) env('FRETE_PRAZO_COLETA_DIAS_MAX', 90),
        'validade_dias_max' => (int) env('FRETE_VALIDADE_DIAS_MAX', 365),
    ],

    'idempotencia_segundos' => (int) env('FRETE_IDEMPOTENCIA_SEGUNDOS', 10),
    'cotacao_repetida_dias' => (int) env('FRETE_COTACAO_REPETIDA_DIAS', 60),

    'erp' => [
        'versao_normalizador' => 1,
        'lock_minutos' => 30,
        'full_horas' => 24,
        'frescor_horas' => 36,
        'situacao' => ['A' => true, 'I' => false],
        'produto' => [
            'prefixos' => ['STK'],
            'ruidos' => ['ESTOQUE', 'DOLAR', 'IMPORTADO', 'NACIONAL', 'IMP', 'NAC'],
            'marcas' => ['DTX', 'T', 'F', 'TP'],
            'equivalencias' => ['HIDROXIDO DE SODIO SOLUCAO' => 'HIDROXIDO DE SODIO SOLUCAO 49%'],
            'etiquetas' => ['T', 'F', 'TP', 'A', 'B', 'DTX', 'DOLAR'],
            'locais' => [
                'PORTO ALFA', 'PORTO BETA', 'PORTO GAMA', 'VILA NORTE', 'TERMINAL GAMA', 'CIDADE DELTA', 'CIDADE OMEGA', 'SAO JOAO DO SUL',
            ],
        ],
        'embalagem_alias' => [
            'CONTAINERS' => 'CONTAINER',
            'CONTAINER' => 'CONTAINER',
            'BOMBONA' => 'BOMBONA',
            'SACARIA' => 'SACARIA',
        ],
    ],

    'log_sistema_dias' => 90,
    'log_debug' => env('FRETE_LOG_DEBUG', false),
];
