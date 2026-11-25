<?php

namespace App\Models;

use App\Models\Concerns\FreteModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CotacaoItem extends Model
{
    use FreteModel;

    protected $table = 'cotacao_item';

    protected $guarded = [];

    protected $casts = ['quantidade_kg' => 'decimal:3', 'paletes' => 'integer', 'sequencia' => 'integer', 'volumes' => 'integer', 'paletizado' => 'boolean', 'remontado' => 'boolean'];

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class);
    }
}