<?php

namespace App\Models;

use App\Models\Concerns\FreteModel;
use Illuminate\Database\Eloquent\Model;

class Cliente extends Model
{
    use FreteModel;

    protected $table = 'cliente';

    protected $guarded = [];

    protected $casts = ['ativo' => 'boolean'];

    public function getTipoAttribute(): string
    {
        return 'CLIENTE';
    }
}