<?php

namespace App\Services;

use App\Models\Usuario;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Support\Str;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Exception as JwtException;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Throwable;

/** Access token JWT RS256 emitido pela própria API. Sem permissões no token. */
class JwtService
{
    private ?Configuration $config = null;

    private function config(): Configuration
    {
        if ($this->config) {
            return $this->config;
        }
        $privada = $this->ler(config('frete.jwt.chave_privada'));
        $publica = $this->ler(config('frete.jwt.chave_publica'));

        return $this->config = Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::plainText($privada),
            InMemory::plainText($publica),
        );
    }

    private function ler(string $caminho): string
    {
        $abs = preg_match('#^([A-Za-z]:[\\\\/]|/)#', $caminho) ? $caminho : base_path($caminho);
        if (! is_file($abs)) {
            throw new RuntimeException("Chave JWT ausente em {$abs}. Execute: php artisan frete:jwt-keys");
        }

        return file_get_contents($abs);
    }

    public function emitir(Usuario $usuario, ?string $familiaId = null): string
    {
        $agora = new DateTimeImmutable;
        $token = $this->config()->builder()
            ->issuedBy(config('frete.jwt.emissor'))
            ->relatedTo($usuario->id)
            ->identifiedBy((string) Str::uuid())
            ->issuedAt($agora)
            ->canOnlyBeUsedAfter($agora)
            ->expiresAt($agora->modify('+'.config('frete.jwt.access_segundos').' seconds'))
            ->withClaim('tv', (int) $usuario->token_versao)
            ->withClaim('fam', $familiaId)
            ->withHeader('kid', config('frete.jwt.kid'))
            ->getToken($this->config()->signer(), $this->config()->signingKey());

        return $token->toString();
    }

    /** @return array{sub: string, tv: int, fam: ?string}|null */
    public function validar(string $jwt): ?array
    {
        try {
            $token = $this->config()->parser()->parse($jwt);
            if (! $token instanceof UnencryptedToken) {
                return null;
            }
            $relogio = new class implements ClockInterface
            {
                public function now(): DateTimeImmutable
                {
                    return new DateTimeImmutable;
                }
            };
            $ok = $this->config()->validator()->validate(
                $token,
                new SignedWith($this->config()->signer(), $this->config()->verificationKey()),
                new IssuedBy(config('frete.jwt.emissor')),
                new StrictValidAt($relogio, new DateInterval('PT10S')),
            );
            if (! $ok) {
                return null;
            }

            return [
                'sub' => (string) $token->claims()->get('sub'),
                'tv' => (int) $token->claims()->get('tv'),
                'fam' => $token->claims()->get('fam') ?: null,
            ];
        } catch (JwtException) {
            return null;
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable) {
            return null;
        }
    }
}
