<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Um código de produto do ERP e o produto local a que pertence (N códigos → 1 produto). Escrito só pelo espelho. */
class ProdutoErp extends Model
{
    protected $table = 'produto_erp';

    protected $primaryKey = 'codigo_erp';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['ativo' => 'boolean'];
}
