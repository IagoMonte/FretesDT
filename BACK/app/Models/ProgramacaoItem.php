<?php

namespace App\Models;

use App\Models\Concerns\FreteModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProgramacaoItem extends Model
{
    use FreteModel;

    protected $table = 'programacao_item';

    protected $guarded = [];

    protected $casts = ['quantidade_kg' => 'decimal:3', 'sequencia' => 'integer', 'volumes' => 'integer'];

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class);
    }

    public function programacao(): BelongsTo
    {
        return $this->belongsTo(Programacao::class);
    }
}