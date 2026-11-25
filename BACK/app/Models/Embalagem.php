<?php

namespace App\Models;

use App\Models\Concerns\FreteModel;
use Illuminate\Database\Eloquent\Model;

class Embalagem extends Model
{
    use FreteModel;

    protected $table = 'embalagem';

    protected $primaryKey = 'codigo';

    protected $guarded = [];

    protected $casts = ['ativo' => 'boolean', 'permite_paletizar' => 'boolean', 'permite_remontar' => 'boolean', 'granel' => 'boolean', 'fracionada' => 'boolean', 'revisao_pendente' => 'boolean'];
}
