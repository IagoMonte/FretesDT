<?php

namespace App\Models;

use App\Models\Concerns\FreteModel;
use Illuminate\Database\Eloquent\Model;

class TipoVeiculo extends Model
{
    use FreteModel;

    protected $table = 'tipo_veiculo';

    protected $primaryKey = 'codigo';

    protected $guarded = [];

    protected $casts = ['ativo' => 'boolean'];
}