<?php

namespace App\Models;

use App\Models\Concerns\FreteModel;
use Illuminate\Database\Eloquent\Model;

class Fornecedor extends Model
{
    use FreteModel;

    protected $table = 'fornecedor';

    protected $guarded = [];

    protected $casts = ['ativo' => 'boolean'];

    public function getTipoAttribute(): string
    {
        return 'FORNECEDOR';
    }
}