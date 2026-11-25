<?php

namespace App\Support;

use App\Services\AnttService;

/** Sanitização do dado cru do ERP. Tudo é decidido deste lado: a API-ponte entrega o valor como está no ERP. */
class Normaliza
{
    public const UFS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];

    /** Sem controle, espaços repetidos nem pontas; vazio (a ponte manda " ") vira null. */
    public static function texto(mixed $v, int $max = 255): ?string
    {
        if ($v === null) {
            return null;
        }
        $t = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\p{C}]+/u', ' ', (string) $v)));

        return $t === '' ? null : mb_substr($t, 0, $max);
    }

    /**
     * CNPJ de 14 dígitos: a ponte manda número, então o zero à esquerda se perdeu. Null se vazio, só zeros ou com mais de 14 dígitos.
     * O dígito verificador NÃO é exigido aqui (use {@see Fmt::cnpjValido()} para avisar): o ERP guarda cadastros antigos fora do padrão.
     */
    public static function cnpj(mixed $v): ?string
    {
        $d = Fmt::cnpj($v === null ? null : (string) $v);
        if ($d === null || trim($d, '0') === '' || strlen($d) > 14) {
            return null;
        }

        return str_pad($d, 14, '0', STR_PAD_LEFT);
    }

    public static function uf(mixed $v): ?string
    {
        $u = mb_strtoupper(trim((string) $v));

        return in_array($u, self::UFS, true) ? $u : null;
    }

    /** Primeiro endereço válido, minúsculo. */
    public static function email(mixed $v, int $max = 200): ?string
    {
        foreach (preg_split('/[\s;,]+/', (string) $v, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $e) {
            if (filter_var($e, FILTER_VALIDATE_EMAIL) && mb_strlen($e) <= $max) {
                return mb_strtolower($e);
            }
        }

        return null;
    }

    public static function fone(mixed $v, int $max = 20): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $v);

        return $d === '' ? null : substr($d, 0, $max);
    }

    /** Chave de comparação de nomes (cidade, embalagem): sem acento, maiúscula, só letras e números. */
    public static function chave(mixed $v): string
    {
        return preg_replace('/[^A-Z0-9]+/', '', AnttService::normalizar((string) $v));
    }

    /** Código local de embalagem a partir do nome do ERP: ASCII, maiúsculo, `_`, até 30. */
    public static function codigoEmbalagem(string $nome): string
    {
        $c = trim((string) preg_replace('/[^A-Z0-9]+/', '_', AnttService::normalizar($nome)), '_');

        return substr($c, 0, 30);
    }
}
