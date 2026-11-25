<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Somente leitura: view frete_vw_parte (clientes + fornecedores). */
class Parte extends Model
{
    protected $table = 'vw_parte';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $casts = ['ativo' => 'boolean'];
}