<?php

namespace App\Enums;

enum Escopo: string
{
    case Proprios = 'PROPRIOS';
    case Transportadora = 'TRANSPORTADORA';
    case Todos = 'TODOS';
}