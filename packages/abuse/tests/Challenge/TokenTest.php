<?php

namespace Utopia\Abuse\Tests\Challenge;

use Ahc\Jwt\JWT;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Abuse\Challenge\Token;

final class TokenTest extends TestCase
{
    private const string SECRET = 'token-secret';

    private function token(string $label = 'label', string $purpose = 'purpose', int $ttl = 60): Token
    {
        return new Token(self::SECRET, $label, $purpose, $ttl);
    }

    public function testIssueThenVerifyReturnsTheClaims(): void
    {
        $token = $this->token();
        $claims = $token->verify($token->issue(['user' => 'u1', 'level' => 3]));

        $this->assertIsArray($claims);
        $this->assertSame('u1', $claims['user']);
        $this->assertSame(3, $claims['level']);
        $this->assertSame('purpose', $claims[Token::PURPOSE_CLAIM]);
    }

    public function testTokenForAnotherPurposeIsRejected(): void
    {
        // Same secret and label, different purpose: the purpose claim must match.
        $issued = $this->token(purpose: 'challenge')->issue([]);

        $this->assertNull($this->token(purpose: 'clearance')->verify($issued));
    }

    public function testTokenSignedUnderAnotherLabelIsRejected(): void
    {
        $issued = $this->token(label: 'one')->issue([]);

        $this->assertNull($this->token(label: 'two')->verify($issued));
    }

    public function testSigningKeyIsDerivedNotTheSecretItself(): void
    {
        $issued = $this->token()->issue([]);

        $this->expectException(\Ahc\Jwt\JWTException::class);
        new JWT(self::SECRET, 'HS256', 60, 0)->decode($issued);
    }

    public function testTokenDecodesWithTheLabelDerivedKey(): void
    {
        $issued = $this->token(label: 'my-label')->issue(['a' => 'b']);

        $claims = new JWT(\hash_hmac('sha256', 'my-label', self::SECRET), 'HS256', 60, 0)->decode($issued);
        $this->assertSame('b', $claims['a']);
    }

    public function testTamperedTokenIsRejected(): void
    {
        $issued = $this->token()->issue([]);
        $issued[\strlen($issued) - 1] = $issued[\strlen($issued) - 1] === 'a' ? 'b' : 'a';

        $this->assertNull($this->token()->verify($issued));
    }

    public function testGarbageAndEmptyTokensAreRejected(): void
    {
        $this->assertNull($this->token()->verify(''));
        $this->assertNull($this->token()->verify('not.a.jwt'));
    }

    public function testExpiredTokenIsRejected(): void
    {
        $jwt = new JWT(\hash_hmac('sha256', 'label', self::SECRET), 'HS256', 60, 0);
        $expired = $jwt->encode([Token::PURPOSE_CLAIM => 'purpose', 'exp' => \time() - 10]);

        $this->assertNull($this->token()->verify($expired));
    }

    public function testTokenWithoutAnExpiryIsRejected(): void
    {
        // The JWT library only checks `exp` when present; a token must not live forever.
        $jwt = new JWT(\hash_hmac('sha256', 'label', self::SECRET), 'HS256', 60, 0);
        // With `iat` set the library does not add `exp` itself.
        $noExpiry = $jwt->encode([Token::PURPOSE_CLAIM => 'purpose', Token::BINDING_CLAIM => [], 'iat' => \time()]);
        $this->assertArrayNotHasKey('exp', $jwt->decode($noExpiry));

        $this->assertNull($this->token()->verify($noExpiry));
    }

    public function testDefaultTtlSetsTheExpiry(): void
    {
        $before = \time();
        $claims = $this->token(ttl: 120)->verify($this->token(ttl: 120)->issue([]));

        $this->assertIsArray($claims);
        $this->assertGreaterThanOrEqual($before + 120, $claims['exp']);
        $this->assertLessThanOrEqual(\time() + 120, $claims['exp']);
    }

    public function testTtlOverrideWins(): void
    {
        $token = $this->token(ttl: 60);
        $before = \time();
        $claims = $token->verify($token->issue([], 3600));

        // Still valid: the library's max age only applies to `iat`, which is never set.
        $this->assertIsArray($claims);
        $this->assertGreaterThanOrEqual($before + 3600, $claims['exp']);
    }

    public function testTheBindingIsStoredWithItsNames(): void
    {
        $token = $this->token();
        $claims = $token->verify($token->issue(['a' => 'b'], binding: ['ua' => 'u', 'ip' => 'i']));

        $this->assertIsArray($claims);
        $this->assertSame('i', $claims['ip']);
        $this->assertSame(['ip', 'ua'], $claims[Token::BINDING_CLAIM]);
    }

    public function testIsBoundToRequiresTheSameNamesAndValues(): void
    {
        $token = $this->token();
        $claims = $token->verify($token->issue([], binding: ['ip' => 'i', 'ua' => 'u']));
        $this->assertIsArray($claims);

        $this->assertTrue(Token::isBoundTo($claims, ['ua' => 'u', 'ip' => 'i']));
        $this->assertFalse(Token::isBoundTo($claims, ['ip' => 'i']));
        $this->assertFalse(Token::isBoundTo($claims, []));
        $this->assertFalse(Token::isBoundTo($claims, ['ip' => 'i', 'ua' => 'other']));
        $this->assertFalse(Token::isBoundTo($claims, ['ip' => 'i', 'ua' => 'u', 'extra' => 'x']));
        // A claim that is not part of the binding cannot stand in for it.
        $claims = $token->verify($token->issue(['ip' => 'i']));
        $this->assertIsArray($claims);
        $this->assertFalse(Token::isBoundTo($claims, ['ip' => 'i']));
        $this->assertTrue(Token::isBoundTo($claims, []));
    }

    public function testBindingAndClaimsMustNotShareNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->token()->issue(['ip' => 'a'], binding: ['ip' => 'b']);
    }

    #[DataProvider('reservedClaims')]
    public function testReservedClaimsAreRefused(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->token()->issue([$name => 'x']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reservedClaims(): iterable
    {
        yield 'purpose' => [Token::PURPOSE_CLAIM];
        yield 'expiry' => [Token::EXPIRY_CLAIM];
        yield 'issued at' => ['iat'];
        yield 'binding names' => [Token::BINDING_CLAIM];
    }

    /**
     * @param  array{string, string, string, int}  $arguments
     */
    #[DataProvider('invalidConfigurations')]
    public function testInvalidConfigurationIsRefused(array $arguments): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Token(...$arguments);
    }

    /**
     * @return iterable<string, array{array{string, string, string, int}}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'empty secret' => [['', 'label', 'purpose', 60]];
        yield 'empty label' => [['secret', '', 'purpose', 60]];
        yield 'empty purpose' => [['secret', 'label', '', 60]];
        yield 'zero ttl' => [['secret', 'label', 'purpose', 0]];
    }
}
