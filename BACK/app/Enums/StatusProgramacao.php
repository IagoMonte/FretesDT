<?php

namespace App\Enums;

use App\Exceptions\RegraNegocioException;

enum StatusProgramacao: string
{
    case EmCotacao = 'EM_COTACAO';
    case AguardandoCarregamento = 'AGUARDANDO_CARREGAMENTO';
    case EmTransito = 'EM_TRANSITO';
    case Entregue = 'ENTREGUE';
    case Cancelado = 'CANCELADO';
    case Devolvido = 'DEVOLVIDO';

    /** @return array<int, self> */
    public function proximos(): array
    {
        return match ($this) {
            self::EmCotacao => [self::AguardandoCarregamento, self::Cancelado],
            self::AguardandoCarregamento => [self::EmTransito, self::Cancelado],
            self::EmTransito => [self::Entregue, self::Cancelado],
            self::Entregue => [self::Devolvido],
            self::Cancelado, self::Devolvido => [],
        };
    }

    public function podeIr(self $para): bool
    {
        return in_array($para, $this->proximos(), true);
    }

    public function exigirTransicao(self $para): void
    {
        if (! $this->podeIr($para)) {
            throw new RegraNegocioException($this->mensagemTransicao($para));
        }
    }

    public function rotulo(): string
    {
        return match ($this) {
            self::EmCotacao => 'em cotação',
            self::AguardandoCarregamento => 'aguardando carregamento',
            self::EmTransito => 'em trânsito',
            self::Entregue => 'entregue',
            self::Cancelado => 'cancelada',
            self::Devolvido => 'devolvida',
        };
    }

    private function mensagemTransicao(self $para): string
    {
        return match (true) {
            $this === self::Entregue && $para === self::Entregue => 'Esta carga já foi entregue.',
            $this === $para => "Esta carga já está {$this->rotulo()}.",
            $this->final() => "Esta carga está {$this->rotulo()} e não pode mais mudar de situação.",
            default => "Não é possível passar a carga de {$this->rotulo()} para {$para->rotulo()}.",
        };
    }

    public function final(): bool
    {
        return $this->proximos() === [];
    }
}