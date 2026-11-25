<?php

namespace App\Models;

use App\Models\Concerns\FreteModel;
use Illuminate\Database\Eloquent\Model;

/** Histórico/fila de notificações: 1 linha por destinatário e canal. */
class Notificacao extends Model
{
    use FreteModel;

    protected $table = 'notificacao';

    protected $guarded = [];

    protected $casts = ['enviado_em' => 'datetime', 'tentativas' => 'integer'];
}
