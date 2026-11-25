<?php

namespace App\Models;

use App\Enums\Operacao;
use App\Enums\StatusProgramacao;
use App\Models\Concerns\FreteModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Programacao extends Model
{
    use FreteModel;

    protected $table = 'programacao';

    protected $guarded = [];

    protected $casts = [
        'status' => StatusProgramacao::class,
        'operacao' => Operacao::class,
        'valor_tonelada' => 'decimal:2',
        'valor_total' => 'decimal:2',
        'prazo_dias' => 'integer',
        'prazo_coleta_dias' => 'integer',
        'entregue_em' => 'datetime',
    ];

    public function cotacao(): BelongsTo
    {
        return $this->belongsTo(Cotacao::class);
    }

    public function representante(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'representante_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class);
    }

    public function transportadora(): BelongsTo
    {
        return $this->belongsTo(Transportadora::class);
    }

    public function itens(): HasMany
    {
        return $this->hasMany(ProgramacaoItem::class)->orderBy('sequencia');
    }

    public function parte(): Cliente|Fornecedor|null
    {
        return $this->operacao === Operacao::Carregamento ? $this->cliente : $this->fornecedor;
    }

    public function scopeVisivelPara(Builder $q, Usuario $u): Builder
    {
        $p = $u->permissao('PROGRAMACAO');
        if (! $p['ver']) {
            return $q->whereRaw('1 = 0');
        }

        return match ($p['escopo']) {
            'TODOS' => $q,
            'TRANSPORTADORA' => $u->transportadora_id
                ? $q->where('transportadora_id', $u->transportadora_id)
                : $q->whereRaw('1 = 0'),
            default => $q->where('representante_id', $u->id),
        };
    }
}