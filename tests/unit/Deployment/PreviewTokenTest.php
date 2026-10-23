<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use Ahc\Jwt\JWT;
use Appwrite\Deployment\PreviewToken;
use Appwrite\Extend\Exception;
use PHPUnit\Framework\TestCase;

final class PreviewTokenTest extends TestCase
{
    private const string KEY = 'unit-test-key';
    private const string HOST = 'deployment1-project1.sites.localhost';

    private string|false $previous;

    protected function setUp(): void
    {
        $this->previous = \getenv('_APP_OPENSSL_KEY_V1');
        \putenv('_APP_OPENSSL_KEY_V1=' . self::KEY);
    }

    protected function tearDown(): void
    {
        \putenv($this->previous === false ? '_APP_OPENSSL_KEY_V1' : '_APP_OPENSSL_KEY_V1=' . $this->previous);
    }

    public function testIssuedTokenAuthorizesItsHost(): void
    {
        $token = PreviewToken::issue($this->consoleJwt(), self::HOST);

        $this->assertSame(['userId' => 'user1', 'sessionId' => 'session1'], PreviewToken::verify($token, self::HOST));
    }

    public function testTokenIsBoundToTheHostItWasIssuedFor(): void
    {
        $token = PreviewToken::issue($this->consoleJwt(), self::HOST);

        $this->assertNull(PreviewToken::verify($token, 'deployment2-project2.sites.localhost'));
        $this->assertNull(PreviewToken::verify($token, ''));
    }

    public function testTokenExpiresWithTheConsoleJwt(): void
    {
        $exp = \time() + 30;
        $token = PreviewToken::issue($this->consoleJwt(exp: $exp), self::HOST);

        // Decoded without verification: the signing key is the token's own
        $payload = (new JWT(self::KEY, 'HS256', 3600, 0))->decode($token, false);

        $this->assertSame($exp, $payload['exp']);
    }

    public function testRefusesExpiredConsoleJwt(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Expired');

        PreviewToken::issue($this->consoleJwt(exp: \time() - 1), self::HOST);
    }

    public function testTokenIsNotAConsoleJwt(): void
    {
        $token = PreviewToken::issue($this->consoleJwt(), self::HOST);

        // The platform key verifies console JWTs, user JWTs and ephemeral
        // keys; a preview token must fail all of them.
        $this->expectException(\Ahc\Jwt\JWTException::class);
        (new JWT(self::KEY, 'HS256', 3600, 0))->decode($token);
    }

    public function testConsoleJwtIsNotAPreviewToken(): void
    {
        $this->assertNull(PreviewToken::verify($this->consoleJwt(), self::HOST));
        $this->assertNull(PreviewToken::verify('', self::HOST));
        $this->assertNull(PreviewToken::verify('not-a-token', self::HOST));
    }

    public function testRefusesProjectJwt(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('console session JWT');

        PreviewToken::issue($this->consoleJwt(projectId: 'project1'), self::HOST);
    }

    public function testRefusesJwtWithoutSession(): void
    {
        $this->expectException(Exception::class);

        PreviewToken::issue($this->consoleJwt(sessionId: ''), self::HOST);
    }

    public function testRefusesForgedJwt(): void
    {
        $forged = (new JWT('another-key', 'HS256', 900, 0))->encode([
            'projectId' => 'console',
            'userId' => 'user1',
            'sessionId' => 'session1',
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to verify JWT');

        PreviewToken::issue($forged, self::HOST);
    }

    private function consoleJwt(string $projectId = 'console', string $sessionId = 'session1', ?int $exp = null): string
    {
        // As POST /v1/account/jwts mints one for a console session
        return (new JWT(self::KEY, 'HS256', 900, 0))->encode([
            'projectId' => $projectId,
            'userId' => 'user1',
            'sessionId' => $sessionId,
            'exp' => $exp ?? \time() + 900,
        ]);
    }
}
