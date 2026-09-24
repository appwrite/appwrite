<?php

declare(strict_types=1);

namespace Utopia\Tests\Auth\Issuers\Asymmetric;

use PHPUnit\Framework\TestCase;
use Utopia\Auth\Issuers\Asymmetric\AccessToken;

final class AccessTokenTest extends TestCase
{
    protected string $publicKey;

    protected AccessToken $accessToken;

    protected function setUp(): void
    {
        [$privateKey, $this->publicKey] = AccessToken::generateKeyPair();

        $this->accessToken = new AccessToken(
            $privateKey,
            $this->publicKey,
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

    public function testHeaderType(): void
    {
        $token = $this->accessToken->issue('user-123', ['https://api.example.com'], 'client-abc', 1000, 3600);
        $header = $this->decodeSegment(explode('.', $token)[0]);

        // RFC 9068 §2.1: access tokens carry the "at+jwt" media type.
        $this->assertEquals('at+jwt', $header['typ']);
        $this->assertEquals('RS256', $header['alg']);
        $this->assertEquals($this->accessToken->getKeyId(), $header['kid']);
    }

    public function testClaims(): void
    {
        $before = time();
        $token = $this->accessToken->issue('user-123', ['https://api.example.com'], 'client-abc', 1000, 3600, ['read', 'write']);
        $after = time();

        $claims = $this->decodeSegment(explode('.', $token)[1]);

        $this->assertEquals('https://example.com/v1/oauth2/test', $claims['iss']);
        $this->assertEquals(['https://api.example.com'], $claims['aud']);
        $this->assertEquals('user-123', $claims['sub']);
        $this->assertEquals('client-abc', $claims['client_id']);
        $this->assertEquals('read write', $claims['scope']);
        $this->assertEquals(1000, $claims['auth_time']);
        $this->assertGreaterThanOrEqual($before, $claims['iat']);
        $this->assertLessThanOrEqual($after, $claims['iat']);
        $this->assertEquals($claims['iat'] + 3600, $claims['exp']);
        $jti = $claims['jti'];
        \assert(\is_string($jti));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $jti);
    }

    public function testAudienceClaim(): void
    {
        $audience = ['https://api.example.com', 'https://mcp.example.com'];
        $token = $this->accessToken->issue('user-123', $audience, 'client-abc', 1000, 3600);
        $claims = $this->decodeSegment(explode('.', $token)[1]);

        $this->assertEquals($audience, $claims['aud']);
    }

    public function testEmptyAudienceIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('audience must contain at least one resource server identifier.');

        $this->accessToken->issue('user-123', [], 'client-abc', 1000, 3600);
    }

    public function testSignatureIsValid(): void
    {
        $token = $this->accessToken->issue('user-123', ['https://api.example.com'], 'client-abc', 1000, 3600);

        $parts = explode('.', $token);
        $result = openssl_verify(
            $parts[0] . '.' . $parts[1],
            base64_decode(strtr($parts[2], '-_', '+/')),
            $this->publicKey,
            OPENSSL_ALGO_SHA256,
        );

        $this->assertSame(1, $result);
    }

    public function testScopeOmittedWhenEmpty(): void
    {
        $token = $this->accessToken->issue('user-123', ['https://api.example.com'], 'client-abc', 1000, 3600);
        $claims = $this->decodeSegment(explode('.', $token)[1]);

        $this->assertArrayNotHasKey('scope', $claims);
    }

    public function testScopeCannotBeInjectedViaClaimsWhenEmpty(): void
    {
        $token = $this->accessToken->issue('user-123', ['https://api.example.com'], 'client-abc', 1000, 3600, [], null, [
            'scope' => 'admin',
        ]);
        $claims = $this->decodeSegment(explode('.', $token)[1]);

        $this->assertArrayNotHasKey('scope', $claims);
    }

    public function testScopeCannotBeOverriddenViaClaims(): void
    {
        $token = $this->accessToken->issue('user-123', ['https://api.example.com'], 'client-abc', 1000, 3600, ['read'], null, [
            'scope' => 'admin',
        ]);
        $claims = $this->decodeSegment(explode('.', $token)[1]);

        $this->assertEquals('read', $claims['scope']);
    }

    public function testJtiIsGeneratedAndUnique(): void
    {
        $first = $this->decodeSegment(explode('.', $this->accessToken->issue('user-123', ['aud'], 'client', 1000, 3600))[1]);
        $second = $this->decodeSegment(explode('.', $this->accessToken->issue('user-123', ['aud'], 'client', 1000, 3600))[1]);

        $this->assertNotEquals($first['jti'], $second['jti']);
    }

    public function testCustomJti(): void
    {
        $token = $this->accessToken->issue('user-123', ['aud'], 'client', 1000, 3600, [], 'fixed-jti');
        $claims = $this->decodeSegment(explode('.', $token)[1]);

        $this->assertEquals('fixed-jti', $claims['jti']);
    }

    public function testAdditionalClaims(): void
    {
        $token = $this->accessToken->issue('user-123', ['aud'], 'client', 1000, 3600, [], null, [
            'tokenId' => 'identity-row-1',
        ]);
        $claims = $this->decodeSegment(explode('.', $token)[1]);

        $this->assertEquals('identity-row-1', $claims['tokenId']);
    }

    public function testAdditionalClaimsCannotOverrideRegisteredClaims(): void
    {
        $token = $this->accessToken->issue('user-123', ['aud'], 'client', 1000, 3600, [], null, [
            'sub' => 'attacker',
            'iss' => 'https://evil.example.com',
            'client_id' => 'evil',
        ]);
        $claims = $this->decodeSegment(explode('.', $token)[1]);

        $this->assertEquals('user-123', $claims['sub']);
        $this->assertEquals('https://example.com/v1/oauth2/test', $claims['iss']);
        $this->assertEquals('client', $claims['client_id']);
    }
}
