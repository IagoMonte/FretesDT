<?php

/**
 * Corpo de cada template (mesmo texto aprovado na Meta). A Cloud API manda o nome do template e os parâmetros; a Evolution (Baileys)
 * manda texto livre, então o texto é montado aqui. {{n}} é o parâmetro posicional n (ordem de config/notificacoes.php -> templates).
 * cotacao_cancelada e alerta_programacao não têm WhatsApp ligado e não existem na Meta: os textos abaixo valem só para a Evolution.
 */
return [
    'cotacao_disponivel' => 'Nova cotação {{1}} aguardando sua resposta. Rota: {{2}}, carga de {{3}} kg. Responda pelo link {{4}} quando puder.',
    'resposta_recebida' => 'A transportadora {{2}} respondeu à cotação {{1}}: {{3}}. Veja os detalhes em {{4}} quando puder.',
    'frete_fechado' => 'Frete fechado na cotação {{1}}. Programação {{2}}, valor: {{3}}, coleta em {{4}}. Detalhes em {{5}} para conferência.',
    'programar_pedido' => "Favor programar pedido: {{1}},\n\nCliente: {{2}}\nModalidade: {{3}}.\n\nCarga: {{4}}\nRota: {{5}}.\n\nValor: {{6}}\n\nCarregamento e entrega: {{7}}\n\nDetalhes em: {{8}}\nFretesDT - Gestão de Fretes",
    'carga_status' => 'A carga {{1}} mudou para o status {{2}} em {{3}}. Acompanhe pelo link {{4}} a qualquer momento.',
    'cotacao_cancelada' => 'O registro {{1}} foi cancelado. Detalhes em {{2}}.',
    'alerta_programacao' => 'Atenção na carga {{1}}. Revise em {{2}}.',
];
