<?php

namespace App\Models;

use App\Models\Concerns\FreteModel;
use Illuminate\Database\Eloquent\Model;

class Recurso extends Model
{
    use FreteModel;

    protected $table = 'recurso';

    protected $primaryKey = 'codigo';

    protected $guarded = [];

    protected $casts = ['usa_escopo' => 'boolean', 'ativo' => 'boolean'];
}