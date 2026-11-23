<?php

namespace Utopia\Abuse\Tests\Challenges;

use Ahc\Jwt\JWT;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Abuse\Challenges\Clearance;
use Utopia\Abuse\Challenges\Token;

final class ClearanceTest extends TestCase
{
    private const string SECRET = 'clearance-secret';

    private const array BINDING = ['project' => 'p1', 'resource' => 'r1', 'ip' => '203.0.113.0/24', 'ua' => 'ua-hash'];

    private function token(string $purpose = 'clearance'): Token
    {
        return new Token(self::SECRET, 'label', $purpose, 1800);
    }

    private function clearance(): Clearance
    {
        return new Clearance($this->token());
    }

    public function testIssueThenVerifyRoundTrips(): void
    {
        $clearance = $this->clearance();

        $this->assertTrue($clearance->verify($clearance->issue(self::BINDING, 18), self::BINDING, 18));
    }

    #[DataProvider('bindingChanges')]
    public function testAnyBindingMismatchIsRejected(string $name, string $value): void
    {
        $clearance = $this->clearance();
        $issued = $clearance->issue(self::BINDING);

        $this->assertFalse($clearance->verify($issued, [...self::BINDING, $name => $value]));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function bindingChanges(): iterable
    {
        yield 'another project' => ['project', 'p2'];
        yield 'another resource' => ['resource', 'r2'];
        yield 'another network' => ['ip', '198.51.100.0/24'];
        yield 'another user agent' => ['ua', 'other'];
        yield 'a field it was not bound to' => ['session', 's1'];
    }

    public function testVerifyingWithPartOfTheBindingIsRejected(): void
    {
        // Leaving a field out must not drop it from the check.
        $clearance = $this->clearance();
        $issued = $clearance->issue(self::BINDING);

        $partial = self::BINDING;
        unset($partial['ua']);
        $this->assertFalse($clearance->verify($issued, $partial));
        $this->assertFalse($clearance->verify($issued, []));
    }

    public function testAnUnboundClearanceOnlyVerifiesWithoutABinding(): void
    {
        $clearance = $this->clearance();
        $issued = $clearance->issue([]);

        $this->assertTrue($clearance->verify($issued, []));
        $this->assertFalse($clearance->verify($issued, ['ip' => '203.0.113.0/24']));
    }

    public function testAnEmptyBindingValueOnlyMatchesAnEmptyValue(): void
    {
        $clearance = $this->clearance();
        $issued = $clearance->issue([...self::BINDING, 'resource' => '']);

        $this->assertTrue($clearance->verify($issued, [...self::BINDING, 'resource' => '']));
        $this->assertFalse($clearance->verify($issued, self::BINDING));
    }

    public function testAClearanceEarnedAtALowerDifficultyIsRejected(): void
    {
        $clearance = $this->clearance();
        $issued = $clearance->issue(self::BINDING, 16);

        $this->assertTrue($clearance->verify($issued, self::BINDING, 14));
        $this->assertTrue($clearance->verify($issued, self::BINDING, 16));
        $this->assertFalse($clearance->verify($issued, self::BINDING, 18));
    }

    public function testMaxAgeLimitsHowOldAClearanceMayBe(): void
    {
        $old = new JWT(\hash_hmac('sha256', 'label', self::SECRET), 'HS256', 1800, 0)->encode([
            Token::PURPOSE_CLAIM => 'clearance',
            ...self::BINDING,
            Token::BINDING_CLAIM => ['ip', 'project', 'resource', 'ua'],
            Clearance::DIFFICULTY_CLAIM => 18,
            Clearance::ISSUED_AT_CLAIM => \time() - 1000,
            'exp' => \time() + 3600,
        ]);

        $clearance = $this->clearance();
        $this->assertTrue($clearance->verify($old, self::BINDING, 18, 1800));
        $this->assertFalse($clearance->verify($old, self::BINDING, 18, 900));
        // No age limit when maxAge is 0.
        $this->assertTrue($clearance->verify($old, self::BINDING, 18));
    }

    public function testATokenThatDoesNotRecordItsBindingIsRejected(): void
    {
        // Without the recorded names there is no way to tell which fields were bound.
        $legacy = new JWT(\hash_hmac('sha256', 'label', self::SECRET), 'HS256', 1800, 0)->encode([
            Token::PURPOSE_CLAIM => 'clearance',
            ...self::BINDING,
            Clearance::DIFFICULTY_CLAIM => 18,
            Clearance::ISSUED_AT_CLAIM => \time(),
            'exp' => \time() + 1800,
        ]);

        $this->assertFalse($this->clearance()->verify($legacy, self::BINDING));
    }

    public function testAClearanceMissingItsDifficultyOrIssueTimeIsRejected(): void
    {
        $clearance = $this->clearance();

        $this->assertFalse($clearance->verify($this->token()->issue([...self::BINDING, Clearance::DIFFICULTY_CLAIM => 18]), self::BINDING));
        $this->assertFalse($clearance->verify($this->token()->issue([...self::BINDING, Clearance::ISSUED_AT_CLAIM => \time()]), self::BINDING));
    }

    public function testATokenIssuedForAnotherPurposeIsNotAClearance(): void
    {
        $challenge = $this->token('challenge')->issue([...self::BINDING, Clearance::DIFFICULTY_CLAIM => 99, Clearance::ISSUED_AT_CLAIM => \time()]);

        $this->assertFalse($this->clearance()->verify($challenge, self::BINDING));
    }

    public function testTamperedForeignAndEmptyTokensAreRejected(): void
    {
        $clearance = $this->clearance();
        $issued = $clearance->issue(self::BINDING);
        $issued[\strlen($issued) - 1] = $issued[\strlen($issued) - 1] === 'a' ? 'b' : 'a';

        $this->assertFalse($clearance->verify($issued, self::BINDING));
        $this->assertFalse($clearance->verify('', self::BINDING));

        $foreign = new Clearance(new Token('other-secret', 'label', 'clearance', 1800))->issue(self::BINDING);
        $this->assertFalse($clearance->verify($foreign, self::BINDING));
    }

    public function testTtlSetsTheExpiry(): void
    {
        $before = \time();
        $issued = $this->clearance()->issue(self::BINDING, ttl: 3600);
        $claims = $this->token()->verify($issued);

        $this->assertIsArray($claims);
        $this->assertGreaterThanOrEqual($before + 3600, $claims['exp']);
        $this->assertTrue($this->clearance()->verify($issued, self::BINDING));
    }

    public function testClaimsAreCarriedButNotChecked(): void
    {
        $issued = $this->clearance()->issue(self::BINDING, claims: ['note' => 'x']);

        $this->assertSame('x', $this->token()->verify($issued)['note'] ?? null);
        $this->assertTrue($this->clearance()->verify($issued, self::BINDING));
    }

    #[DataProvider('reservedNames')]
    public function testReservedNamesAreRefused(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->clearance()->issue([$name => 'x']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reservedNames(): iterable
    {
        yield 'difficulty' => [Clearance::DIFFICULTY_CLAIM];
        yield 'issue time' => [Clearance::ISSUED_AT_CLAIM];
        yield 'purpose' => [Token::PURPOSE_CLAIM];
    }

    public function testABindingThatSharesANameWithAClaimIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->clearance()->issue(['ip' => 'a'], claims: ['ip' => 'b']);
    }
}
