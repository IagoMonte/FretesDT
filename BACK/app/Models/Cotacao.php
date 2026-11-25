<?php

namespace App\Models;

use App\Enums\Operacao;
use App\Enums\StatusCotacao;
use App\Enums\StatusResposta;
use App\Models\Concerns\FreteModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Cotacao extends Model
{
    use FreteModel;

    protected $table = 'cotacao';

    protected $guarded = [];

    protected $casts = [
        'status' => StatusCotacao::class,
        'operacao' => Operacao::class,
        'fracionada' => 'boolean',
        'dedicado' => 'boolean',
        'valor_nf' => 'decimal:2',
        'distancia_km' => 'decimal:1',
    ];

    /** Frete mínimo ANTT (e eixos/tipo de carga na resposta) só vale se a carga não é fracionada ou a transportadora a atende como dedicada. */
    public function pisoValido(bool $dedicado = false): bool
    {
        return ! $this->fracionada || $dedicado;
    }

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'vendedor_id');
    }

    /** Cotação original cujas respostas esta enxerga ao vivo (cotação repetida). */
    public function origem(): BelongsTo
    {
        return $this->belongsTo(self::class, 'cotacao_origem_id');
    }

    /** Origem cujas respostas ainda valem: a original cancelada continua vinculada, mas não fornece respostas. */
    public function origemValida(): ?self
    {
        $origem = $this->origem;

        return $origem && $origem->status !== StatusCotacao::Cancelada ? $origem : null;
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class);
    }

    public function tipoVeiculo(): BelongsTo
    {
        return $this->belongsTo(TipoVeiculo::class, 'tipo_veiculo_codigo', 'codigo');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(CotacaoItem::class)->orderBy('sequencia');
    }

    public function negociacao(): HasOne
    {
        return $this->hasOne(Negociacao::class);
    }

    public function programacoes(): HasMany
    {
        return $this->hasMany(Programacao::class)->orderBy('codigo');
    }

    public function parte(): Cliente|Fornecedor|null
    {
        return $this->operacao === Operacao::Carregamento ? $this->cliente : $this->fornecedor;
    }

    /**
     * Status que a transportadora enxerga (nunca o real): EM_NEGOCIACAO, RESPONDIDA, PROGRAMADA
     * (só a vencedora) ou ENCERRADA (perdeu, não respondeu, declinou, encerrada ou cancelada).
     */
    public function statusParaTransportadora(string $transportadoraId): string
    {
        $negociacao = $this->negociacao;
        $ganhou = $negociacao?->transportadora_id === $transportadoraId;

        $minha = $negociacao?->respostas->firstWhere('transportadora_id', $transportadoraId)
            ?? $this->origemValida()?->negociacao?->respostas->firstWhere('transportadora_id', $transportadoraId);

        return match (true) {
            in_array($this->status, [StatusCotacao::Fechada, StatusCotacao::Programada], true) => $ganhou ? 'PROGRAMADA' : 'ENCERRADA',
            in_array($this->status, [StatusCotacao::Cancelada, StatusCotacao::Encerrada], true) => 'ENCERRADA',
            $minha?->status === StatusResposta::Declinada => 'ENCERRADA',
            $minha?->status === StatusResposta::Respondida => 'RESPONDIDA',
            default => 'EM_NEGOCIACAO',
        };
    }

    /** Regras de visibilidade. Fora do escopo = não existe (404). */
    public function scopeVisivelPara(Builder $q, Usuario $u): Builder
    {
        $p = $u->permissao('COTACAO');
        if (! $p['ver']) {
            return $q->whereRaw('1 = 0');
        }

        return match ($p['escopo']) {
            'TODOS' => $q,
            'TRANSPORTADORA' => $u->transportadora_id
                ? $q->where(fn ($w) => $w
                    ->whereHas('negociacao.respostas', fn ($r) => $r->where('transportadora_id', $u->transportadora_id))
                    ->orWhereHas('origem', fn ($o) => $o->where('status', '<>', StatusCotacao::Cancelada->value)
                        ->whereHas('negociacao.respostas', fn ($r) => $r->where('transportadora_id', $u->transportadora_id))))
                : $q->whereRaw('1 = 0'),
            default => $q->where('vendedor_id', $u->id),
        };
    }
}