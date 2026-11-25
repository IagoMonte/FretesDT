<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Tabela técnica, sem bloco de auditoria. O token em si nunca é gravado, só o hash. */
class RefreshToken extends Model
{
    use HasUuids;

    protected $table = 'refresh_token';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'emitido_em' => 'datetime',
        'expira_em' => 'datetime',
        'usado_em' => 'datetime',
        'revogado_em' => 'datetime',
    ];

    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.v';
    }
}