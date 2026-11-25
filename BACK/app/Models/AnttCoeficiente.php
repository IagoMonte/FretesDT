<?php

namespace App\Models;

use App\Models\Concerns\FreteModel;
use Illuminate\Database\Eloquent\Model;

class AnttCoeficiente extends Model
{
    use FreteModel;

    protected $table = 'antt_coeficiente';

    protected $guarded = [];

    protected $casts = [
        'eixos' => 'integer',
        'ccd' => 'decimal:4',
        'cc' => 'decimal:2',
        'vigencia_inicio' => 'date:Y-m-d',
        'vigencia_fim' => 'date:Y-m-d',
        'ativo' => 'boolean',
    ];
}
