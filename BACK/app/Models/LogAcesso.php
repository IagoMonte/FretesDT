<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LogAcesso extends Model
{
    use HasUuids;

    protected $table = 'log_acesso';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['ocorrido_em' => 'datetime'];

    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.v';
    }
}