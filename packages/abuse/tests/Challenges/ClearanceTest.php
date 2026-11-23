<?php

namespace Utopia\Abuse\Tests\Challenges;

use Ahc\Jwt\JWT;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Abuse\Challenges\Clearance;
use Utopia\Abuse\Challenges\ProofOfWork;
use Utopia\Abuse\Challenges\ProofOfWork\Status;
use Utopia\Abuse\Challenges\Token;
use Utopia\Abuse\Tests\Challenges\Nonce\Memory;

final class ClearanceTest extends TestCase
{
    private const string SECRET = 'clearance-secret';

    private const array BINDING = ['project' => 'p1', 'resource' => 'r1', 'ip' => '203.0.113.0/24', 'ua' => 'ua-hash'];

    private function token(string $purpose = 'clearance'): Token
    {
        return new Token(self::SECRET, 'label', $purpose, 1800);
    }

    private function clearance(string $secret = self::SECRET): Clearance
    {
        return new Clearance($secret, 1800, 'label');
    }

    public function testIssueThenVerifyRoundTrips(): void
    {
        $clearance = $this->clearance();

        $bound = $clearance->withBinding(self::BINDING);

        $this->assertTrue($bound->verify($bound->issue(18), 18));
    }

    #[DataProvider('bindingChanges')]
    public function testAnyBindingMismatchIsRejected(string $name, string $value): void
    {
        $clearance = $this->clearance();
        $issued = $clearance->withBinding(self::BINDING)->issue();

        $this->assertFalse($clearance->withBinding([...self::BINDING, $name => $value])->verify($issued));
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
        $issued = $clearance->withBinding(self::BINDING)->issue();

        $partial = self::BINDING;
        unset($partial['ua']);
        $this->assertFalse($clearance->withBinding($partial)->verify($issued));
        $this->assertFalse($clearance->withBinding([])->verify($issued));
    }

    public function testAnUnboundClearanceOnlyVerifiesWithoutABinding(): void
    {
        $clearance = $this->clearance();
        $issued = $clearance->withBinding([])->issue();

        $this->assertTrue($clearance->withBinding([])->verify($issued));
        $this->assertFalse($clearance->withBinding(['ip' => '203.0.113.0/24'])->verify($issued));
    }

    public function testAnEmptyBindingValueOnlyMatchesAnEmptyValue(): void
    {
        $clearance = $this->clearance();
        $issued = $clearance->withBinding([...self::BINDING, 'resource' => ''])->issue();

        $this->assertTrue($clearance->withBinding([...self::BINDING, 'resource' => ''])->verify($issued));
        $this->assertFalse($clearance->withBinding(self::BINDING)->verify($issued));
    }

    public function testAClearanceEarnedAtALowerDifficultyIsRejected(): void
    {
        $clearance = $this->clearance();
        $issued = $clearance->withBinding(self::BINDING)->issue(16);

        $this->assertTrue($clearance->withBinding(self::BINDING)->verify($issued, 14));
        $this->assertTrue($clearance->withBinding(self::BINDING)->verify($issued, 16));
        $this->assertFalse($clearance->withBinding(self::BINDING)->verify($issued, 18));
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
        $this->assertTrue($clearance->withBinding(self::BINDING)->verify($old, 18, 1800));
        $this->assertFalse($clearance->withBinding(self::BINDING)->verify($old, 18, 900));
        // No age limit when maxAge is 0.
        $this->assertTrue($clearance->withBinding(self::BINDING)->verify($old, 18));
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

        $this->assertFalse($this->clearance()->withBinding(self::BINDING)->verify($legacy));
    }

    public function testAClearanceMissingItsDifficultyOrIssueTimeIsRejected(): void
    {
        $clearance = $this->clearance();

        $this->assertFalse($clearance->withBinding(self::BINDING)->verify($this->token()->issue([Clearance::DIFFICULTY_CLAIM => 18], binding: self::BINDING)));
        $this->assertFalse($clearance->withBinding(self::BINDING)->verify($this->token()->issue([Clearance::ISSUED_AT_CLAIM => \time()], binding: self::BINDING)));
    }

    public function testATokenIssuedForAnotherPurposeIsNotAClearance(): void
    {
        $challenge = $this->token('challenge')->issue([Clearance::DIFFICULTY_CLAIM => 99, Clearance::ISSUED_AT_CLAIM => \time()], binding: self::BINDING);

        $this->assertFalse($this->clearance()->withBinding(self::BINDING)->verify($challenge));
    }

    public function testTamperedForeignAndEmptyTokensAreRejected(): void
    {
        $clearance = $this->clearance();
        $issued = $clearance->withBinding(self::BINDING)->issue();
        $issued[\strlen($issued) - 1] = $issued[\strlen($issued) - 1] === 'a' ? 'b' : 'a';

        $this->assertFalse($clearance->withBinding(self::BINDING)->verify($issued));
        $this->assertFalse($clearance->withBinding(self::BINDING)->verify(''));

        $foreign = $this->clearance('other-secret')->withBinding(self::BINDING)->issue();
        $this->assertFalse($clearance->withBinding(self::BINDING)->verify($foreign));
    }

    public function testWithBindingReturnsACopyAndMerges(): void
    {
        $clearance = $this->clearance();
        $issued = $clearance->withBinding(['project' => 'p1', 'resource' => 'r1'])->withBinding(['ip' => '203.0.113.0/24', 'ua' => 'ua-hash'])->issue();

        $this->assertTrue($clearance->withBinding(self::BINDING)->verify($issued));
        $this->assertFalse($clearance->verify($issued));
    }

    public function testDefaultLabelsKeepPuzzlesAndClearancesApart(): void
    {
        // Same secret, default labels and purposes: neither token passes as the other.
        $puzzle = new ProofOfWork(self::SECRET, new Memory())->issue(0);
        $clearance = new Clearance(self::SECRET);
        $issued = $clearance->issue();

        $this->assertTrue($clearance->verify($issued));
        $this->assertFalse($clearance->verify($puzzle->token));
        $this->assertSame(Status::InvalidToken, new ProofOfWork(self::SECRET, new Memory())->verify($issued, '0')->status);
    }

    public function testTtlSetsTheExpiry(): void
    {
        $before = \time();
        $issued = $this->clearance()->withBinding(self::BINDING)->issue(ttl: 3600);
        $claims = $this->token()->verify($issued);

        $this->assertIsArray($claims);
        $this->assertGreaterThanOrEqual($before + 3600, $claims['exp']);
        $this->assertTrue($this->clearance()->withBinding(self::BINDING)->verify($issued));
    }

    public function testClaimsAreCarriedButNotChecked(): void
    {
        $issued = $this->clearance()->withBinding(self::BINDING)->issue(claims: ['note' => 'x']);

        $this->assertSame('x', $this->token()->verify($issued)['note'] ?? null);
        $this->assertTrue($this->clearance()->withBinding(self::BINDING)->verify($issued));
    }

    #[DataProvider('reservedNames')]
    public function testReservedNamesAreRefused(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->clearance()->withBinding([$name => 'x'])->issue();
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
        $this->clearance()->withBinding(['ip' => 'a'])->issue(claims: ['ip' => 'b']);
    }
}
