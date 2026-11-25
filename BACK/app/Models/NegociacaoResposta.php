<?php

namespace App\Models;

use App\Enums\StatusResposta;
use App\Models\Concerns\FreteModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NegociacaoResposta extends Model
{
    use FreteModel;

    protected $table = 'negociacao_resposta';

    protected $guarded = [];

    protected $casts = [
        'status' => StatusResposta::class,
        'valor_tonelada' => 'decimal:2',
        'valor_frete' => 'decimal:2',
        'eixos' => 'integer',
        'dedicado' => 'boolean',
        'piso_antt_valor' => 'decimal:2',
        'piso_antt_km' => 'decimal:1',
        'prazo_dias' => 'integer',
        'prazo_coleta_dias' => 'integer',
        'validade' => 'date:Y-m-d',
        'encaminhado_em' => 'datetime',
        'respondido_em' => 'datetime',
    ];

    public function negociacao(): BelongsTo
    {
        return $this->belongsTo(Negociacao::class);
    }

    public function transportadora(): BelongsTo
    {
        return $this->belongsTo(Transportadora::class);
    }
}