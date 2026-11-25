<?php

namespace App\Enums;

enum StatusNegociacao: string
{
    case Aberta = 'ABERTA';
    case Fechada = 'FECHADA';
    case Cancelada = 'CANCELADA';
}