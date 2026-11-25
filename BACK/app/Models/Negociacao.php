<?php

namespace App\Models;

use App\Enums\StatusNegociacao;
use App\Models\Concerns\FreteModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Negociacao extends Model
{
    use FreteModel;

    protected $table = 'negociacao';

    protected $guarded = [];

    protected $casts = [
        'status' => StatusNegociacao::class,
        'valor_tonelada' => 'decimal:2',
        'valor_frete' => 'decimal:2',
        'eixos' => 'integer',
        'dedicado' => 'boolean',
        'piso_antt_valor' => 'decimal:2',
        'piso_antt_km' => 'decimal:1',
        'prazo_dias' => 'integer',
        'prazo_coleta_dias' => 'integer',
        'fechado_em' => 'datetime',
    ];

    public function cotacao(): BelongsTo
    {
        return $this->belongsTo(Cotacao::class);
    }

    public function respostas(): HasMany
    {
        return $this->hasMany(NegociacaoResposta::class)->orderBy('encaminhado_em');
    }

    public function transportadora(): BelongsTo
    {
        return $this->belongsTo(Transportadora::class);
    }

    public function fechadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'fechado_por_id');
    }
}