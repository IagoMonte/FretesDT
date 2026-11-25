<?php

namespace App\Enums;

enum StatusResposta: string
{
    case Aguardando = 'AGUARDANDO';
    case Respondida = 'RESPONDIDA';
    case Declinada = 'DECLINADA';
}