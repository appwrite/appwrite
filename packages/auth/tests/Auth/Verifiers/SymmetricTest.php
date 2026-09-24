<?php

declare(strict_types=1);

namespace Utopia\Tests\Auth\Verifiers;

use PHPUnit\Framework\TestCase;
use Utopia\Auth\Issuers\Symmetric\RefreshToken;
use Utopia\Auth\Verifiers\Symmetric;
use Utopia\Auth\Verifiers\VerificationException;

final class SymmetricTest extends TestCase
{
    protected string $secret;

    protected RefreshToken $issuer;

    protected Symmetric $verifier;

    protected string $iss = 'https://example.com/v1/oauth2/test';

    protected function setUp(): void
    {
        $this->secret = RefreshToken::generateSecret();
        $this->issuer = new RefreshToken($this->secret, $this->iss);
        $this->verifier = new Symmetric($this->secret);
    }

    public function testVerifiesIssuedToken(): void
    {
        $token = $this->issuer->issue('user-123', 'https://example.com/token', 'client-abc', 3600, ['offline_access']);
        $claims = (new Symmetric($this->secret, issuer: $this->iss, audience: 'https://example.com/token'))->verify($token);

        $this->assertSame('user-123', $claims['sub']);
        $this->assertSame('client-abc', $claims['client_id']);
        $this->assertSame('offline_access', $claims['scope']);
    }

    public function testWrongSecretRejected(): void
    {
        $token = $this->issuer->issue('u', 'aud', 'c', 3600);

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('Signature verification failed');
        (new Symmetric(RefreshToken::generateSecret()))->verify($token);
    }

    public function testExpiredTokenRejected(): void
    {
        $token = $this->issuer->issue('u', 'aud', 'c', -3600);

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('Token has expired');
        $this->verifier->verify($token);
    }

    public function testAudienceMismatchRejected(): void
    {
        $token = $this->issuer->issue('u', 'aud', 'c', 3600);

        $this->expectException(VerificationException::class);
        $this->expectExceptionMessage('Unexpected token audience');
        (new Symmetric($this->secret, audience: 'other'))->verify($token);
    }

    public function testLeewayAllowsRecentlyExpired(): void
    {
        // Expired 10 seconds ago, but a 60s leeway tolerates the skew.
        $token = $this->issuer->issue('u', 'aud', 'c', -10);
        $claims = (new Symmetric($this->secret, leeway: 60))->verify($token);

        $this->assertSame('u', $claims['sub']);
    }
}
