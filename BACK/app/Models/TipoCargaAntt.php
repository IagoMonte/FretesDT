<?php

namespace App\Models;

use App\Models\Concerns\FreteModel;
use Illuminate\Database\Eloquent\Model;

class TipoCargaAntt extends Model
{
    use FreteModel;

    protected $table = 'tipo_carga_antt';

    protected $primaryKey = 'codigo';

    protected $guarded = [];

    protected $casts = ['ativo' => 'boolean'];
}
