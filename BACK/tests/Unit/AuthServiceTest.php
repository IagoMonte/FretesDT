<?php

namespace Tests\Unit;

use App\Services\AuthService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** v1.1 item 3: login sem diferenciar maiúsculas. */
class AuthServiceTest extends TestCase
{
    public static function logins(): array
    {
        return [
            'maiuscula' => ['ADMIN', 'admin'],
            'mista' => ['MaRcOs', 'marcos'],
            'espacos' => ["  marcos \t", 'marcos'],
            'acentuado' => ['JOÃO', 'joão'],
            'ja_normal' => ['admin', 'admin'],
            'vazio' => ['   ', ''],
        ];
    }

    #[DataProvider('logins')]
    public function test_normalizar_login(string $entrada, string $esperado): void
    {
        $this->assertSame($esperado, AuthService::normalizarLogin($entrada));
    }

    public function test_normalizacao_e_idempotente(): void
    {
        $uma = AuthService::normalizarLogin('  JoÃo.Silva ');

        $this->assertSame($uma, AuthService::normalizarLogin($uma));
    }
}
