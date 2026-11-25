<?php

namespace App\Enums;

use App\Exceptions\RegraNegocioException;

enum StatusCotacao: string
{
    case Aberta = 'ABERTA';
    case EmNegociacao = 'EM_NEGOCIACAO';
    case Fechada = 'FECHADA';
    case Programada = 'PROGRAMADA';
    case Cancelada = 'CANCELADA';
    case Encerrada = 'ENCERRADA';

    /** @return array<int, self> */
    public function proximos(): array
    {
        return match ($this) {
            self::Aberta => [self::EmNegociacao, self::Fechada, self::Cancelada, self::Encerrada],
            self::EmNegociacao => [self::Fechada, self::Cancelada, self::Encerrada],
            self::Fechada => [self::Programada, self::Cancelada],
            self::Programada => [self::EmNegociacao],
            self::Cancelada, self::Encerrada => [],
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
            self::Aberta => 'aberta',
            self::EmNegociacao => 'em negociação',
            self::Fechada => 'fechada',
            self::Programada => 'programada',
            self::Cancelada => 'cancelada',
            self::Encerrada => 'encerrada',
        };
    }

    private function mensagemTransicao(self $para): string
    {
        return match (true) {
            $this === self::Cancelada => 'Esta cotação foi cancelada e não pode mais mudar de situação.',
            $this === self::Encerrada => 'Esta cotação foi encerrada e não pode mais mudar de situação.',
            $this === self::Programada || $this === self::Fechada => $para === self::Cancelada
                ? 'Esta cotação já foi fechada e não pode ser cancelada. Cancele a carga gerada para reabri-la.'
                : 'Esta cotação já foi fechada.',
            default => "Não é possível passar a cotação de {$this->rotulo()} para {$para->rotulo()}.",
        };
    }
}