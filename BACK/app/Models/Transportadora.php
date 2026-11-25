<?php

namespace App\Models;

use App\Models\Concerns\FreteModel;
use Illuminate\Database\Eloquent\Model;

class Transportadora extends Model
{
    use FreteModel;

    protected $table = 'transportadora';

    protected $guarded = [];

    protected $casts = ['ativo' => 'boolean'];
}