<?php

namespace App\Models;

use App\Models\Concerns\FreteModel;
use App\Services\PermissaoService;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Usuario extends Model implements AuthenticatableContract
{
    use Authenticatable, FreteModel;

    protected $table = 'usuario';

    protected $guarded = [];

    protected $hidden = ['senha_hash'];

    public function getAuthPasswordName(): string
    {
        return 'senha_hash';
    }

    protected $casts = [
        'token_versao' => 'integer',
        'liberado_em' => 'datetime',
        'ultimo_acesso_em' => 'datetime',
        'decidido_em' => 'datetime',
    ];

    private ?array $efetivas = null;

    public function grupos(): BelongsToMany
    {
        return $this->belongsToMany(Grupo::class, 'usuario_grupo', 'usuario_id', 'grupo_id');
    }

    public function transportadora(): BelongsTo
    {
        return $this->belongsTo(Transportadora::class);
    }

    public function decisor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'decidido_por');
    }

    public function ativo(): bool
    {
        return $this->situacao === 'ATIVO';
    }

    public function ehTransportadora(): bool
    {
        return $this->transportadora_id !== null;
    }

    /** Permissões efetivas (união dos grupos), memoizadas na instância. */
    public function efetivas(): array
    {
        return $this->efetivas ??= app(PermissaoService::class)->efetivas($this);
    }

    public function limparPermissoes(): void
    {
        $this->efetivas = null;
    }

    /** @return array{ver: bool, inserir: bool, alterar: bool, cancelar: bool, escopo: string} */
    public function permissao(string $recurso): array
    {
        return $this->efetivas()['permissoes'][$recurso]
            ?? ['ver' => false, 'inserir' => false, 'alterar' => false, 'cancelar' => false, 'escopo' => 'PROPRIOS'];
    }

    public function escopo(string $recurso): string
    {
        return $this->permissao($recurso)['escopo'];
    }

    /** Habilidade "RECURSO.operacao" (ver/inserir/alterar/cancelar) ou "RECURSO.ACAO". */
    public function pode(string $habilidade): bool
    {
        [$recurso, $op] = array_pad(explode('.', $habilidade, 2), 2, '');
        if (in_array($op, ['ver', 'inserir', 'alterar', 'cancelar'], true)) {
            return $this->permissao($recurso)[$op];
        }

        return in_array($habilidade, $this->efetivas()['acoes'], true);
    }

    public function podeAlgum(string ...$habilidades): bool
    {
        foreach ($habilidades as $h) {
            if ($this->pode($h)) {
                return true;
            }
        }

        return false;
    }
}