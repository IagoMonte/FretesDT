<?php

use App\Mail\CargaStatusAlterado;
use App\Mail\CotacaoEncaminhada;
use App\Mail\NegociacaoFechada;
use App\Mail\RespostaRecebida;

/**
 * Mapa evento -> canais -> grupos que recebem por padrão. A tabela `notificacao_preferencia` sobrepõe estes padrões
 * (o administrador decide quem recebe o quê), só dentro dos grupos que o evento lista aqui.
 * `mailable` e `assunto` montam o e-mail; `template` é o nome do template do WhatsApp (parâmetros em `templates`).
 */
$grupoT = 'GRUPO_TRANSPORTADORA';
$email = ['VENDEDOR', 'ADMINISTRADOR', 'GESTOR', 'TRANSPORTADORA'];
$whatsapp = [...$email, $grupoT];
$canais = ['WHATSAPP' => $whatsapp, 'EMAIL' => $email];

return [
    'front_url' => env('FRONT_URL', 'http://localhost:5173'),

    'eventos' => [
        'NEGOCIACAO_ENCAMINHADA' => ['template' => 'cotacao_disponivel', 'mailable' => CotacaoEncaminhada::class, 'assunto' => 'Nova cotação para você responder', 'rota' => 'quotes',
            'canais' => $canais],
        'NEGOCIACAO_RESPONDIDA' => ['template' => 'resposta_recebida', 'mailable' => RespostaRecebida::class, 'assunto' => 'Resposta recebida', 'rota' => 'quotes',
            'canais' => $canais],
        'NEGOCIACAO_DECLINADA' => ['template' => 'resposta_recebida', 'mailable' => RespostaRecebida::class, 'assunto' => 'Cotação declinada', 'rota' => 'quotes',
            'canais' => $canais],
        'NEGOCIACAO_FECHADA' => ['template' => 'frete_fechado', 'mailable' => NegociacaoFechada::class, 'assunto' => 'Frete fechado', 'rota' => 'quotes',
            'template_por_grupo' => ['TRANSPORTADORA' => 'programar_pedido', $grupoT => 'programar_pedido'],
            'canais' => $canais],
        'COTACAO_CANCELADA' => ['template' => 'cotacao_cancelada', 'mailable' => CotacaoEncaminhada::class, 'assunto' => 'Cotação cancelada', 'rota' => 'quotes',
            'canais' => $canais],
        'NEGOCIACAO_CANCELADA' => ['template' => 'cotacao_cancelada', 'mailable' => CotacaoEncaminhada::class, 'assunto' => 'Negociação cancelada', 'rota' => 'quotes',
            'canais' => $canais],
        'PROGRAMACAO_STATUS' => ['template' => 'carga_status', 'mailable' => CargaStatusAlterado::class, 'assunto' => 'Status da carga alterado', 'rota' => 'loads',
            'canais' => $canais],
        'PROGRAMACAO_CANCELADA' => ['template' => 'cotacao_cancelada', 'mailable' => CargaStatusAlterado::class, 'assunto' => 'Carga cancelada', 'rota' => 'loads',
            'canais' => $canais],
        'PROGRAMACAO_CRIADA' => ['template' => 'programar_pedido', 'mailable' => CargaStatusAlterado::class, 'assunto' => 'Carga programada', 'rota' => 'loads',
            'canais' => ['WHATSAPP' => ['TRANSPORTADORA', $grupoT], 'EMAIL' => $email]],
        'PROGRAMACAO_QTD_EXCEDIDA' => ['template' => 'alerta_programacao', 'mailable' => CargaStatusAlterado::class, 'assunto' => 'Quantidade programada acima da cotada', 'rota' => 'loads',
            'canais' => $canais],
        'PROGRAMACAO_DIVERGE_FECHAMENTO' => ['template' => 'alerta_programacao', 'mailable' => CargaStatusAlterado::class, 'assunto' => 'Carga diverge do fechamento', 'rota' => 'loads',
            'canais' => $canais],
    ],

    'grupos' => ['VENDEDOR', 'TRANSPORTADORA', 'ADMINISTRADOR', 'GESTOR', 'GRUPO_TRANSPORTADORA'],

    'templates' => [
        'cotacao_disponivel' => ['codigo', 'rota', 'kg', 'link'],
        'resposta_recebida' => ['codigo', 'transportadora', 'valor', 'link'],
        'frete_fechado' => ['codigo', 'programacao', 'valor', 'coleta', 'link'],
        'programar_pedido' => ['pedido', 'cliente', 'modalidade', 'carga', 'rota', 'valor', 'datas', 'link'],
        'carga_status' => ['codigo', 'status', 'data', 'link'],
        'cotacao_cancelada' => ['codigo', 'link'],
        'alerta_programacao' => ['codigo', 'link'],
    ],

    'email' => [
        'NEGOCIACAO_ENCAMINHADA' => ['secao' => 'Cotação', 'etiqueta' => 'Aguardando resposta', 'pill' => 'claro', 'chamada' => 'Há uma nova cotação de frete aguardando a sua resposta.', 'botao' => 'Responder cotação', 'btn' => 'vermelho'],
        'NEGOCIACAO_RESPONDIDA' => ['secao' => 'Cotação', 'etiqueta' => 'Em negociação', 'pill' => 'claro', 'chamada' => 'Uma transportadora respondeu à cotação.', 'botao' => 'Ver resposta'],
        'NEGOCIACAO_DECLINADA' => ['secao' => 'Cotação', 'etiqueta' => 'Declinada', 'pill' => 'claro', 'chamada' => 'Uma transportadora declinou da cotação.', 'botao' => 'Ver cotação'],
        'NEGOCIACAO_FECHADA' => ['secao' => 'Cotação', 'etiqueta' => 'Programada', 'pill' => 'verde', 'chamada' => 'O frete foi fechado e a carga já está programada.', 'botao' => 'Ver fechamento'],
        'COTACAO_CANCELADA' => ['secao' => 'Cotação', 'etiqueta' => 'Cancelada', 'pill' => 'vermelho', 'chamada' => 'A cotação foi cancelada.', 'botao' => 'Ver cotação'],
        'NEGOCIACAO_CANCELADA' => ['secao' => 'Cotação', 'etiqueta' => 'Cancelada', 'pill' => 'vermelho', 'chamada' => 'A negociação foi cancelada.', 'botao' => 'Ver cotação'],
        'PROGRAMACAO_CRIADA' => ['secao' => 'Programação de cargas', 'etiqueta' => 'Programada', 'pill' => 'verde', 'chamada' => 'Uma carga foi programada.', 'botao' => 'Ver carga'],
        'PROGRAMACAO_STATUS' => ['secao' => 'Programação de cargas', 'etiqueta' => 'Status', 'pill' => 'claro_ponto', 'chamada' => 'O status da carga foi atualizado.', 'botao' => 'Acompanhar carga'],
        'PROGRAMACAO_CANCELADA' => ['secao' => 'Programação de cargas', 'etiqueta' => 'Cancelada', 'pill' => 'vermelho', 'chamada' => 'A carga foi cancelada.', 'botao' => 'Ver carga'],
        'PROGRAMACAO_QTD_EXCEDIDA' => ['secao' => 'Programação de cargas', 'etiqueta' => 'Alerta', 'pill' => 'claro', 'chamada' => 'A quantidade programada é maior que a cotada.', 'botao' => 'Revisar carga', 'btn' => 'vermelho'],
        'PROGRAMACAO_DIVERGE_FECHAMENTO' => ['secao' => 'Programação de cargas', 'etiqueta' => 'Alerta', 'pill' => 'claro', 'chamada' => 'A carga diverge do que foi fechado.', 'botao' => 'Revisar carga', 'btn' => 'vermelho'],
    ],

    'rotulos' => [
        'rota' => 'Rota', 'kg' => 'Quantidade (kg)', 'transportadora' => 'Transportadora', 'valor' => 'Valor', 'cliente' => 'Cliente', 'modalidade' => 'Modalidade',
        'carga' => 'Veículo e produto', 'datas' => 'Carregamento e entrega', 'pedido' => 'Pedido',
        'prazo_dias' => 'Prazo de entrega (dias)', 'prazo_coleta_dias' => 'Prazo de coleta (dias)', 'observacao' => 'Observação', 'programacao' => 'Carga', 'coleta' => 'Coleta prevista',
        'status' => 'Status', 'data' => 'Data', 'motivo' => 'Motivo', 'avisos' => 'Avisos',
    ],
];
