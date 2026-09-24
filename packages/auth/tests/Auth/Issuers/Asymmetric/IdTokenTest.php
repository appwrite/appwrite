<?php

declare(strict_types=1);

namespace Utopia\Tests\Auth\Issuers\Asymmetric;

use PHPUnit\Framework\TestCase;
use Utopia\Auth\Issuers\Asymmetric\IdToken;

final class IdTokenTest extends TestCase
{
    protected string $privateKey;

    protected string $publicKey;

    protected IdToken $idToken;

    protected function setUp(): void
    {
        [$this->privateKey, $this->publicKey] = IdToken::generateKeyPair();

        $this->idToken = new IdToken(
            $this->privateKey,
            $this->publicKey,
            'https://example.com/v1/oauth2/test',
        );
    }

    /**
     * Decode a JWT segment from base64url JSON into an array.
     *
     * @return array<string, mixed>
     */
    private function decodeSegment(string $segment): array
    {
        $json = base64_decode(strtr($segment, '-_', '+/'));

        /** @var array<string, mixed> $claims */
        $claims = json_decode($json, true);

        return $claims;
    }

    public function testIssueStructure(): void
    {
        $token = $this->idToken->issue('user-123', 'client-abc', 1000, 3600);

        $parts = explode('.', $token);
        $this->assertCount(3, $parts);

        $header = $this->decodeSegment($parts[0]);
        $this->assertEquals('JWT', $header['typ']);
        $this->assertEquals('RS256', $header['alg']);
        $this->assertEquals($this->idToken->getKeyId(), $header['kid']);
    }

    public function testIssueClaims(): void
    {
        $before = time();
        $token = $this->idToken->issue('user-123', 'client-abc', 1000, 3600);
        $after = time();

        $claims = $this->decodeSegment(explode('.', $token)[1]);

        $this->assertEquals('https://example.com/v1/oauth2/test', $claims['iss']);
        $this->assertEquals('user-123', $claims['sub']);
        $this->assertEquals('client-abc', $claims['aud']);
        $this->assertEquals(1000, $claims['auth_time']);
        $this->assertGreaterThanOrEqual($before, $claims['iat']);
        $this->assertLessThanOrEqual($after, $claims['iat']);
        $this->assertEquals($claims['iat'] + 3600, $claims['exp']);

        // Optional claims absent by default
        $this->assertArrayNotHasKey('nonce', $claims);
        $this->assertArrayNotHasKey('at_hash', $claims);
        $this->assertArrayNotHasKey('c_hash', $claims);
    }

    public function testSignatureIsValid(): void
    {
        $token = $this->idToken->issue('user-123', 'client-abc', 1000, 3600);

        $parts = explode('.', $token);
        $signingInput = $parts[0] . '.' . $parts[1];
        $signature = base64_decode(strtr($parts[2], '-_', '+/'));

        $result = openssl_verify(
            $signingInput,
            $signature,
            $this->publicKey,
            OPENSSL_ALGO_SHA256,
        );

        $this->assertSame(1, $result);
    }

    public function testNonceClaim(): void
    {
        $token = $this->idToken->issue('user-123', 'client-abc', 1000, 3600, 'n-0S6_WzA2Mj');
        $claims = $this->decodeSegment(explode('.', $token)[1]);

        $this->assertEquals('n-0S6_WzA2Mj', $claims['nonce']);
    }

    public function testAtHashAndCHash(): void
    {
        $accessToken = 'access-token-value';
        $code = 'authorization-code-value';

        $token = $this->idToken->issue('user-123', 'client-abc', 1000, 3600, null, $accessToken, $code);
        $claims = $this->decodeSegment(explode('.', $token)[1]);

        $expectedAtHash = $this->expectedLeftHalfHash($accessToken);
        $expectedCHash = $this->expectedLeftHalfHash($code);

        $this->assertEquals($expectedAtHash, $claims['at_hash']);
        $this->assertEquals($expectedCHash, $claims['c_hash']);
    }

    public function testHashClaimsCannotBeInjectedViaClaimsWhenAbsent(): void
    {
        // No nonce/accessToken/code passed, but a caller tries to smuggle them
        // (e.g. a forged at_hash) through the additional claims array.
        $token = $this->idToken->issue('user-123', 'client-abc', 1000, 3600, null, null, null, [
            'nonce' => 'forged-nonce',
            'at_hash' => 'forged-at-hash',
            'c_hash' => 'forged-c-hash',
        ]);
        $claims = $this->decodeSegment(explode('.', $token)[1]);

        $this->assertArrayNotHasKey('nonce', $claims);
        $this->assertArrayNotHasKey('at_hash', $claims);
        $this->assertArrayNotHasKey('c_hash', $claims);
    }

    public function testHashClaimsCannotBeOverriddenViaClaims(): void
    {
        $accessToken = 'access-token-value';
        $code = 'authorization-code-value';

        $token = $this->idToken->issue('user-123', 'client-abc', 1000, 3600, 'real-nonce', $accessToken, $code, [
            'nonce' => 'forged-nonce',
            'at_hash' => 'forged-at-hash',
            'c_hash' => 'forged-c-hash',
        ]);
        $claims = $this->decodeSegment(explode('.', $token)[1]);

        $this->assertEquals('real-nonce', $claims['nonce']);
        $this->assertEquals($this->expectedLeftHalfHash($accessToken), $claims['at_hash']);
        $this->assertEquals($this->expectedLeftHalfHash($code), $claims['c_hash']);
    }

    public function testUnrepresentableClaimThrows(): void
    {
        // Invalid UTF-8 cannot be JSON-encoded; this must fail loudly rather
        // than silently produce a token with an empty payload segment.
        $this->expectException(\JsonException::class);
        $this->idToken->issue('user-123', 'client-abc', 1000, 3600, null, null, null, [
            'bad' => "\xB1\x31",
        ]);
    }

    public function testAdditionalClaims(): void
    {
        $token = $this->idToken->issue('user-123', 'client-abc', 1000, 3600, null, null, null, [
            'email' => 'user@example.com',
            'email_verified' => true,
        ]);
        $claims = $this->decodeSegment(explode('.', $token)[1]);

        $this->assertEquals('user@example.com', $claims['email']);
        $this->assertTrue($claims['email_verified']);
    }

    public function testAdditionalClaimsCannotOverrideRegisteredClaims(): void
    {
        $token = $this->idToken->issue('user-123', 'client-abc', 1000, 3600, null, null, null, [
            'sub' => 'attacker',
            'iss' => 'https://evil.example.com',
        ]);
        $claims = $this->decodeSegment(explode('.', $token)[1]);

        $this->assertEquals('user-123', $claims['sub']);
        $this->assertEquals('https://example.com/v1/oauth2/test', $claims['iss']);
    }

    public function testKeyIdIsDeterministic(): void
    {
        $other = new IdToken($this->privateKey, $this->publicKey, 'https://example.com/v1/oauth2/test');

        $this->assertSame($this->idToken->getKeyId(), $other->getKeyId());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $this->idToken->getKeyId());
    }

    public function testCustomKeyId(): void
    {
        $idToken = new IdToken($this->privateKey, $this->publicKey, 'https://example.com', 'my-custom-kid');

        $this->assertSame('my-custom-kid', $idToken->getKeyId());

        $token = $idToken->issue('user-123', 'client-abc', 1000, 3600);
        $header = $this->decodeSegment(explode('.', $token)[0]);
        $this->assertEquals('my-custom-kid', $header['kid']);
    }

    public function testGetPublicJwk(): void
    {
        $jwk = $this->idToken->getPublicJwk();

        $this->assertEquals('RSA', $jwk['kty']);
        $this->assertEquals('sig', $jwk['use']);
        $this->assertEquals('RS256', $jwk['alg']);
        $this->assertEquals($this->idToken->getKeyId(), $jwk['kid']);
        $this->assertNotEmpty($jwk['n']);
        $this->assertNotEmpty($jwk['e']);
        // base64url: no padding, no +/ characters
        $this->assertStringNotContainsString('=', $jwk['n']);
        $this->assertStringNotContainsString('+', $jwk['n']);
        $this->assertStringNotContainsString('/', $jwk['n']);
    }

    public function testEmptyPrivateKeyThrows(): void
    {
        $this->expectException(\Exception::class);
        new IdToken('', $this->publicKey, 'https://example.com');
    }

    public function testEmptyPublicKeyThrows(): void
    {
        $this->expectException(\Exception::class);
        new IdToken($this->privateKey, '', 'https://example.com');
    }

    public function testEmptyIssuerThrows(): void
    {
        $this->expectException(\Exception::class);
        new IdToken($this->privateKey, $this->publicKey, '');
    }

    public function testGenerateKeyPair(): void
    {
        [$privateKey, $publicKey] = IdToken::generateKeyPair();

        $this->assertStringContainsString('PRIVATE KEY', $privateKey);
        $this->assertStringContainsString('PUBLIC KEY', $publicKey);

        // The generated keys are usable for issuing and verifying a token.
        $idToken = new IdToken($privateKey, $publicKey, 'https://example.com');
        $token = $idToken->issue('user-123', 'client-abc', 1000, 3600);

        $parts = explode('.', $token);
        $result = openssl_verify(
            $parts[0] . '.' . $parts[1],
            base64_decode(strtr($parts[2], '-_', '+/')),
            $publicKey,
            OPENSSL_ALGO_SHA256,
        );
        $this->assertSame(1, $result);
    }

    /**
     * Mirror of IdToken::leftHalfHash for assertion purposes.
     */
    private function expectedLeftHalfHash(string $value): string
    {
        return rtrim(strtr(base64_encode(substr(hash('sha256', $value, true), 0, 16)), '+/', '-_'), '=');
    }
}
