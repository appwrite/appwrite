<?php

declare(strict_types=1);

namespace Utopia\Tests\Auth\Issuers\Symmetric;

use PHPUnit\Framework\TestCase;
use Utopia\Auth\Issuers\Symmetric\RefreshToken;

final class RefreshTokenTest extends TestCase
{
    protected string $secret;

    protected RefreshToken $refreshToken;

    protected function setUp(): void
    {
        $this->secret = RefreshToken::generateSecret();

        $this->refreshToken = new RefreshToken(
            $this->secret,
            'https://example.com/v1/oauth2/test',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeSegment(string $segment): array
    {
        /** @var array<string, mixed> $claims */
        $claims = json_decode(base64_decode(strtr($segment, '-_', '+/')), true);

        return $claims;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public function testHeaderUsesHs256AndNoKidByDefault(): void
    {
        $token = $this->refreshToken->issue('user-123', 'https://example.com/token', 'client-abc', 1209600);
        $header = $this->decodeSegment(explode('.', $token)[0]);

        $this->assertEquals('JWT', $header['typ']);
        $this->assertEquals('HS256', $header['alg']);
        $this->assertArrayNotHasKey('kid', $header);
    }

    public function testClaims(): void
    {
        $before = time();
        $token = $this->refreshToken->issue('user-123', 'https://example.com/token', 'client-abc', 1209600, ['read', 'offline_access']);
        $after = time();

        $claims = $this->decodeSegment(explode('.', $token)[1]);

        $this->assertEquals('https://example.com/v1/oauth2/test', $claims['iss']);
        $this->assertEquals('https://example.com/token', $claims['aud']);
        $this->assertEquals('user-123', $claims['sub']);
        $this->assertEquals('client-abc', $claims['client_id']);
        $this->assertEquals('read offline_access', $claims['scope']);
        $this->assertGreaterThanOrEqual($before, $claims['iat']);
        $this->assertLessThanOrEqual($after, $claims['iat']);
        $this->assertEquals($claims['iat'] + 1209600, $claims['exp']);
        $jti = $claims['jti'];
        \assert(\is_string($jti));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $jti);

        // Refresh tokens carry no auth_time.
        $this->assertArrayNotHasKey('auth_time', $claims);
    }

    public function testSignatureIsValidHmac(): void
    {
        $token = $this->refreshToken->issue('user-123', 'aud', 'client-abc', 1209600);

        $parts = explode('.', $token);
        $expected = $this->base64UrlEncode(hash_hmac('sha256', $parts[0] . '.' . $parts[1], $this->secret, true));

        $this->assertSame($expected, $parts[2]);
    }

    public function testSignatureFailsWithWrongSecret(): void
    {
        $token = $this->refreshToken->issue('user-123', 'aud', 'client-abc', 1209600);

        $parts = explode('.', $token);
        $wrong = $this->base64UrlEncode(hash_hmac('sha256', $parts[0] . '.' . $parts[1], 'not-the-secret', true));

        $this->assertNotSame($wrong, $parts[2]);
    }

    public function testScopeOmittedWhenEmpty(): void
    {
        $token = $this->refreshToken->issue('user-123', 'aud', 'client-abc', 1209600);
        $claims = $this->decodeSegment(explode('.', $token)[1]);

        $this->assertArrayNotHasKey('scope', $claims);
    }

    public function testScopeCannotBeInjectedViaClaimsWhenEmpty(): void
    {
        $token = $this->refreshToken->issue('user-123', 'aud', 'client-abc', 1209600, [], null, [
            'scope' => 'admin',
        ]);
        $claims = $this->decodeSegment(explode('.', $token)[1]);

        $this->assertArrayNotHasKey('scope', $claims);
    }

    public function testScopeCannotBeOverriddenViaClaims(): void
    {
        $token = $this->refreshToken->issue('user-123', 'aud', 'client-abc', 1209600, ['read'], null, [
            'scope' => 'admin',
        ]);
        $claims = $this->decodeSegment(explode('.', $token)[1]);

        $this->assertEquals('read', $claims['scope']);
    }

    public function testJtiIsGeneratedAndUnique(): void
    {
        $first = $this->decodeSegment(explode('.', $this->refreshToken->issue('u', 'a', 'c', 100))[1]);
        $second = $this->decodeSegment(explode('.', $this->refreshToken->issue('u', 'a', 'c', 100))[1]);

        $this->assertNotEquals($first['jti'], $second['jti']);
    }

    public function testCustomJti(): void
    {
        $token = $this->refreshToken->issue('u', 'a', 'c', 100, [], 'fixed-jti');
        $claims = $this->decodeSegment(explode('.', $token)[1]);

        $this->assertEquals('fixed-jti', $claims['jti']);
    }

    public function testKidHeaderWhenConfigured(): void
    {
        $refreshToken = new RefreshToken($this->secret, 'https://example.com/v1/oauth2/test', 'secret-v2');

        $this->assertSame('secret-v2', $refreshToken->getKeyId());

        $header = $this->decodeSegment(explode('.', $refreshToken->issue('u', 'a', 'c', 100))[0]);
        $this->assertEquals('secret-v2', $header['kid']);
    }

    public function testAdditionalClaimsCannotOverrideRegisteredClaims(): void
    {
        $token = $this->refreshToken->issue('user-123', 'aud', 'client', 100, [], null, [
            'sub' => 'attacker',
            'iss' => 'https://evil.example.com',
        ]);
        $claims = $this->decodeSegment(explode('.', $token)[1]);

        $this->assertEquals('user-123', $claims['sub']);
        $this->assertEquals('https://example.com/v1/oauth2/test', $claims['iss']);
    }

    public function testGenerateSecret(): void
    {
        $secret = RefreshToken::generateSecret();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $secret);
        $this->assertNotSame($secret, RefreshToken::generateSecret());
    }

    public function testEmptySecretThrows(): void
    {
        $this->expectException(\Exception::class);
        new RefreshToken('', 'https://example.com/v1/oauth2/test');
    }

    public function testEmptyIssuerThrows(): void
    {
        $this->expectException(\Exception::class);
        new RefreshToken($this->secret, '');
    }
}
