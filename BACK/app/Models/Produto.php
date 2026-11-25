<?php

namespace App\Models;

use App\Models\Concerns\FreteModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Produto extends Model
{
    use FreteModel;

    protected $table = 'produto';

    protected $guarded = [];

    protected $casts = ['ativo' => 'boolean', 'densidade' => 'decimal:3'];

    /** Nome exibido à transportadora: a descrição, ou o nome quando não houver. */
    public function nomeParaTransportadora(): string
    {
        $d = trim((string) $this->descricao);

        return $d !== '' ? $d : $this->nome;
    }

    /** Resumo do produto num item; a transportadora nunca recebe o nome real quando há descrição. */
    public function resumo(bool $transportadora): array
    {
        return [
            'id' => $this->id,
            'nome' => $transportadora ? $this->nomeParaTransportadora() : $this->nome,
            'descricao' => $transportadora ? null : $this->descricao,
            'onu' => $this->onu,
            'risco' => $this->risco,
        ];
    }

    /** Códigos do ERP que o produto agrupa (um por local/estoque). */
    public function codigosErp(): HasMany
    {
        return $this->hasMany(ProdutoErp::class, 'produto_id');
    }

    public function embalagens(): BelongsToMany
    {
        return $this->belongsToMany(Embalagem::class, 'produto_embalagem', 'produto_id', 'embalagem_codigo', 'id', 'codigo')->withPivot('kg_por_volume', 'kg_por_palete');
    }
}
