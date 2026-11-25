<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/** Técnico e expurgável: model:prune apaga o que passou de frete.log_sistema_dias. */
class LogSistema extends Model
{
    use HasUuids, MassPrunable;

    protected $table = 'log_sistema';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['ocorrido_em' => 'datetime'];

    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.v';
    }

    public function prunable(): Builder
    {
        return static::where('ocorrido_em', '<', now()->subDays(config('frete.log_sistema_dias')));
    }
}