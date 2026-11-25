<?php

namespace App\Models;

use App\Models\Concerns\FreteModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Grupo extends Model
{
    use FreteModel;

    protected $table = 'grupo';

    protected $guarded = [];

    protected $casts = ['ativo' => 'boolean', 'sistema' => 'boolean'];

    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(Usuario::class, 'usuario_grupo');
    }
}