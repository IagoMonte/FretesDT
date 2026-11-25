<?php

namespace App\Models\Concerns;

use App\Exceptions\ConflitoException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * Base de todos os models do domínio:
 * UUID v7, timestamps em português com milissegundos, auditoria por usuário
 * e lock otimista pela coluna `versao`.
 */
trait FreteModel
{
    use HasUuids;

    public static function bootFreteModel(): void
    {
        static::creating(function ($model) {
            $uid = auth()->id();
            $model->criado_por ??= $uid;
            $model->atualizado_por ??= $uid;
            $model->versao ??= 0;
        });

        static::updating(function ($model) {
            $atual = $model->newModelQuery()->whereKey($model->getKey())->lockForUpdate()->value('versao');
            if ($atual !== null && (int) $atual !== (int) $model->getOriginal('versao')) {
                throw new ConflitoException;
            }
            $model->atualizado_por = auth()->id();
            $model->versao = (int) $model->getOriginal('versao') + 1;
        });
    }

    public function initializeFreteModel(): void
    {
        $this->casts['criado_em'] = 'datetime';
        $this->casts['atualizado_em'] = 'datetime';
    }

    public function getCreatedAtColumn(): string
    {
        return 'criado_em';
    }

    public function getUpdatedAtColumn(): string
    {
        return 'atualizado_em';
    }

    /** Milissegundos: vale para DATETIME(3) (MySQL) e DATETIME2(3) (SQL Server). */
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.v';
    }

    /** Força um UPDATE (e o incremento de `versao`) quando só os filhos mudaram. */
    public function tocar(): bool
    {
        $agora = $this->freshTimestamp();
        if ($this->atualizado_em && $agora->lte($this->atualizado_em)) {
            $agora = $this->atualizado_em->copy()->addMillisecond();
        }
        $this->atualizado_em = $agora;

        return $this->save();
    }

    /** Confere a `versao` enviada pelo cliente antes de gravar. */
    public function exigirVersao(mixed $versao): void
    {
        if ((int) $versao !== (int) $this->versao) {
            throw new ConflitoException;
        }
    }
}
