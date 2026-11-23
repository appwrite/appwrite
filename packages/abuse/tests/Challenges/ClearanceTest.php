<?php

namespace Utopia\Abuse\Tests\Challenges;

use Ahc\Jwt\JWT;
use Ahc\Jwt\JWTException;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Abuse\Challenges\Clearance;
use Utopia\Abuse\Challenges\ProofOfWork;
use Utopia\Abuse\Tests\Challenges\ProofOfWork\Nonce\InMemoryConsumer;

final class ClearanceTest extends TestCase
{
    private const string SECRET = 'clearance-secret';
    private const string PROJECT = 'proj1';
    private const string RESOURCE = 'site1';
    private const string IP_PREFIX = '203.0.113.0/24';
    private const string UA_HASH = 'ua-hash-abc';
    private const int DIFFICULTY = 14;
    private const int TTL = 900;

    public function testIssueThenVerifyRoundTrips(): void
    {
        $clearance = new Clearance(self::SECRET);
        $cookie = $clearance->issue(self::PROJECT, self::RESOURCE, self::IP_PREFIX, self::UA_HASH, self::DIFFICULTY);

        $this->assertTrue($clearance->verify($cookie, self::PROJECT, self::RESOURCE, self::IP_PREFIX, self::UA_HASH, self::DIFFICULTY, self::TTL));
    }

    public function testCookieMintedForAnotherProjectIsRejected(): void
    {
        $clearance = new Clearance(self::SECRET);
        $cookie = $clearance->issue(self::PROJECT, self::RESOURCE, self::IP_PREFIX, self::UA_HASH, self::DIFFICULTY);

        $this->assertFalse($clearance->verify($cookie, 'other-project', self::RESOURCE, self::IP_PREFIX, self::UA_HASH, self::DIFFICULTY, self::TTL));
    }

    public function testCookieMintedForAnotherResourceIsRejected(): void
    {
        $clearance = new Clearance(self::SECRET);
        $cookie = $clearance->issue(self::PROJECT, self::RESOURCE, self::IP_PREFIX, self::UA_HASH, self::DIFFICULTY);

        $this->assertFalse($clearance->verify($cookie, self::PROJECT, 'site2', self::IP_PREFIX, self::UA_HASH, self::DIFFICULTY, self::TTL));
    }

    public function testCookieMintedWithoutAResourceOnlyMatchesNoResource(): void
    {
        $clearance = new Clearance(self::SECRET);
        $cookie = $clearance->issue(self::PROJECT, '', self::IP_PREFIX, self::UA_HASH, self::DIFFICULTY);

        $this->assertTrue($clearance->verify($cookie, self::PROJECT, '', self::IP_PREFIX, self::UA_HASH, self::DIFFICULTY, self::TTL));
        $this->assertFalse($clearance->verify($cookie, self::PROJECT, self::RESOURCE, self::IP_PREFIX, self::UA_HASH, self::DIFFICULTY, self::TTL));
    }

    public function testCookieWithoutAResourceClaimIsRejected(): void
    {
        $cookie = new JWT($this->key(), 'HS256', Clearance::DEFAULT_TTL, 0)->encode([
            Clearance::PURPOSE_CLAIM => Clearance::PURPOSE,
            'projectId' => self::PROJECT,
            'ipPrefix' => self::IP_PREFIX,
            'uaHash' => self::UA_HASH,
            'difficulty' => self::DIFFICULTY,
            'issuedAt' => \time(),
            'exp' => \time() + Clearance::DEFAULT_TTL,
        ]);

        $this->assertFalse(new Clearance(self::SECRET)->verify($cookie, self::PROJECT, '', self::IP_PREFIX, self::UA_HASH, self::DIFFICULTY, self::TTL));
    }

    public function testCookieSolvedAtALowerDifficultyIsRejected(): void
    {
        $clearance = new Clearance(self::SECRET);
        $cookie = $clearance->issue(self::PROJECT, self::RESOURCE, self::IP_PREFIX, self::UA_HASH, 14, 86400);

        $this->assertFalse($clearance->verify($cookie, self::PROJECT, self::RESOURCE, self::IP_PREFIX, self::UA_HASH, 18, self::TTL));
    }

    public function testCookieSolvedAtTheSameOrAHigherDifficultyPasses(): void
    {
        $clearance = new Clearance(self::SECRET);
        $cookie = $clearance->issue(self::PROJECT, self::RESOURCE, self::IP_PREFIX, self::UA_HASH, 18);

        $this->assertTrue($clearance->verify($cookie, self::PROJECT, self::RESOURCE, self::IP_PREFIX, self::UA_HASH, 18, self::TTL));
        $this->assertTrue($clearance->verify($cookie, self::PROJECT, self::RESOURCE, self::IP_PREFIX, self::UA_HASH, 14, self::TTL));
    }

    public function testCookieOlderThanTheRequiredTtlIsRejected(): void
    {
        // Issued 10 minutes ago with a cookie lifetime still in the future.
        $cookie = new JWT($this->key(), 'HS256', Clearance::DEFAULT_TTL, 0)->encode([
            Clearance::PURPOSE_CLAIM => Clearance::PURPOSE,
            'projectId' => self::PROJECT,
            'resourceId' => self::RESOURCE,
            'ipPrefix' => self::IP_PREFIX,
            'uaHash' => self::UA_HASH,
            'difficulty' => self::DIFFICULTY,
            'issuedAt' => \time() - 600,
            'exp' => \time() + 3000,
        ]);

        $clearance = new Clearance(self::SECRET);
        $this->assertFalse($clearance->verify($cookie, self::PROJECT, self::RESOURCE, self::IP_PREFIX, self::UA_HASH, self::DIFFICULTY, 300));
        $this->assertTrue($clearance->verify($cookie, self::PROJECT, self::RESOURCE, self::IP_PREFIX, self::UA_HASH, self::DIFFICULTY, 900));
    }

    public function testZeroTtlFallsBackToTheDefaultTtl(): void
    {
        // Issued longer ago than the 1800s default, with a cookie lifetime still in the future.
        $cookie = new JWT($this->key(), 'HS256', Clearance::DEFAULT_TTL, 0)->encode([
            Clearance::PURPOSE_CLAIM => Clearance::PURPOSE,
            'projectId' => self::PROJECT,
            'resourceId' => self::RESOURCE,
            'ipPrefix' => self::IP_PREFIX,
            'uaHash' => self::UA_HASH,
            'difficulty' => self::DIFFICULTY,
            'issuedAt' => \time() - 2000,
            'exp' => \time() + 3000,
        ]);

        $this->assertFalse(new Clearance(self::SECRET)->verify($cookie, self::PROJECT, self::RESOURCE, self::IP_PREFIX, self::UA_HASH, self::DIFFICULTY, 0));
    }

    public function testCookieWithoutDifficultyOrIssuedAtIsRejected(): void
    {
        $cookie = new JWT($this->key(), 'HS256', Clearance::DEFAULT_TTL, 0)->encode([
            Clearance::PURPOSE_CLAIM => Clearance::PURPOSE,
            'projectId' => self::PROJECT,
            'resourceId' => self::RESOURCE,
            'ipPrefix' => self::IP_PREFIX,
            'uaHash' => self::UA_HASH,
            'exp' => \time() + Clearance::DEFAULT_TTL,
        ]);

        $this->assertFalse(new Clearance(self::SECRET)->verify($cookie, self::PROJECT, self::RESOURCE, self::IP_PREFIX, self::UA_HASH, 0, self::TTL));
    }

    public function testCookiePresentedFromAnotherIpPrefixIsRejected(): void
    {
        // The subtractive IP binding: a copied cookie fails outside the prefix it
        // was minted for (caller then re-challenges, never hard-blocks).
        $clearance = new Clearance(self::SECRET);
        $cookie = $clearance->issue(self::PROJECT, self::RESOURCE, self::IP_PREFIX, self::UA_HASH, self::DIFFICULTY);

        $this->assertFalse($clearance->verify($cookie, self::PROJECT, self::RESOURCE, '198.51.100.0/24', self::UA_HASH, self::DIFFICULTY, self::TTL));
    }

    public function testCookiePresentedWithAnotherUaIsRejected(): void
    {
        $clearance = new Clearance(self::SECRET);
        $cookie = $clearance->issue(self::PROJECT, self::RESOURCE, self::IP_PREFIX, self::UA_HASH, self::DIFFICULTY);

        $this->assertFalse($clearance->verify($cookie, self::PROJECT, self::RESOURCE, self::IP_PREFIX, 'other-ua', self::DIFFICULTY, self::TTL));
    }

    public function testTamperedCookieIsRejected(): void
    {
        $clearance = new Clearance(self::SECRET);
        $cookie = $clearance->issue(self::PROJECT, self::RESOURCE, self::IP_PREFIX, self::UA_HASH, self::DIFFICULTY);
        $cookie[\strlen($cookie) - 1] = $cookie[\strlen($cookie) - 1] === 'a' ? 'b' : 'a';

        $this->assertFalse($clearance->verify($cookie, self::PROJECT, self::RESOURCE, self::IP_PREFIX, self::UA_HASH, self::DIFFICULTY, self::TTL));
    }

    public function testCookieSignedWithAnotherSecretIsRejected(): void
    {
        $foreign = new Clearance('another-secret');
        $cookie = $foreign->issue(self::PROJECT, self::RESOURCE, self::IP_PREFIX, self::UA_HASH, self::DIFFICULTY);

        $clearance = new Clearance(self::SECRET);
        $this->assertFalse($clearance->verify($cookie, self::PROJECT, self::RESOURCE, self::IP_PREFIX, self::UA_HASH, self::DIFFICULTY, self::TTL));
    }

    public function testChallengeTokenIsRejectedAsClearance(): void
    {
        // An unsolved challenge token shares the secret and binding claims but is not a clearance.
        $issued = new ProofOfWork(self::SECRET, new InMemoryConsumer())->issue(self::PROJECT, self::IP_PREFIX, self::UA_HASH);

        $clearance = new Clearance(self::SECRET);
        $this->assertFalse($clearance->verify($issued['token'], self::PROJECT, self::RESOURCE, self::IP_PREFIX, self::UA_HASH, self::DIFFICULTY, self::TTL));
    }

    /**
     * @return Iterator<string, array{?string}>
     */
    public static function otherPurposes(): Iterator
    {
        yield 'challenge purpose' => [ProofOfWork::PURPOSE];
        yield 'no purpose' => [null];
    }

    #[DataProvider('otherPurposes')]
    public function testCookieWithoutTheClearancePurposeIsRejected(?string $purpose): void
    {
        // Signed with the clearance key and correctly bound; only the purpose is wrong.
        $claims = [
            'projectId' => self::PROJECT,
            'resourceId' => self::RESOURCE,
            'ipPrefix' => self::IP_PREFIX,
            'uaHash' => self::UA_HASH,
            'difficulty' => self::DIFFICULTY,
            'issuedAt' => \time(),
            'exp' => \time() + Clearance::DEFAULT_TTL,
        ];
        if ($purpose !== null) {
            $claims[Clearance::PURPOSE_CLAIM] = $purpose;
        }
        $cookie = new JWT($this->key(), 'HS256', Clearance::DEFAULT_TTL, 0)->encode($claims);

        $clearance = new Clearance(self::SECRET);
        $this->assertFalse($clearance->verify($cookie, self::PROJECT, self::RESOURCE, self::IP_PREFIX, self::UA_HASH, self::DIFFICULTY, self::TTL));
    }

    public function testCookieSignedWithTheChallengeKeyIsRejected(): void
    {
        // Clearance claims signed with the challenge key; keeps the two keys apart.
        $cookie = new JWT(\hash_hmac('sha256', ProofOfWork::KEY_LABEL, self::SECRET), 'HS256', Clearance::DEFAULT_TTL, 0)->encode([
            Clearance::PURPOSE_CLAIM => Clearance::PURPOSE,
            'projectId' => self::PROJECT,
            'resourceId' => self::RESOURCE,
            'ipPrefix' => self::IP_PREFIX,
            'uaHash' => self::UA_HASH,
            'difficulty' => self::DIFFICULTY,
            'issuedAt' => \time(),
            'exp' => \time() + Clearance::DEFAULT_TTL,
        ]);

        $clearance = new Clearance(self::SECRET);
        $this->assertFalse($clearance->verify($cookie, self::PROJECT, self::RESOURCE, self::IP_PREFIX, self::UA_HASH, self::DIFFICULTY, self::TTL));
    }

    public function testCookieSignedWithTheConfiguredSecretIsRejected(): void
    {
        // Only the clearance-derived key is accepted, never the configured secret itself.
        $cookie = new JWT(self::SECRET, 'HS256', Clearance::DEFAULT_TTL, 0)->encode([
            Clearance::PURPOSE_CLAIM => Clearance::PURPOSE,
            'projectId' => self::PROJECT,
            'resourceId' => self::RESOURCE,
            'ipPrefix' => self::IP_PREFIX,
            'uaHash' => self::UA_HASH,
            'difficulty' => self::DIFFICULTY,
            'issuedAt' => \time(),
            'exp' => \time() + Clearance::DEFAULT_TTL,
        ]);

        $clearance = new Clearance(self::SECRET);
        $this->assertFalse($clearance->verify($cookie, self::PROJECT, self::RESOURCE, self::IP_PREFIX, self::UA_HASH, self::DIFFICULTY, self::TTL));
    }

    public function testIssuedCookieIsNotSignedWithTheConfiguredSecret(): void
    {
        $cookie = new Clearance(self::SECRET)->issue(self::PROJECT, self::RESOURCE, self::IP_PREFIX, self::UA_HASH, self::DIFFICULTY);

        $this->expectException(JWTException::class);
        new JWT(self::SECRET, 'HS256', Clearance::DEFAULT_TTL, 0)->decode($cookie);
    }

    public function testEmptyCookieIsRejected(): void
    {
        $clearance = new Clearance(self::SECRET);
        $this->assertFalse($clearance->verify('', self::PROJECT, self::RESOURCE, self::IP_PREFIX, self::UA_HASH, self::DIFFICULTY, self::TTL));
    }

    public function testExpiredCookieIsRejected(): void
    {
        $jwt = new JWT($this->key(), 'HS256', Clearance::DEFAULT_TTL, 0);
        $jwt->setTestTimestamp(\time() - 100_000);
        $cookie = $jwt->encode([
            Clearance::PURPOSE_CLAIM => Clearance::PURPOSE,
            'projectId' => self::PROJECT,
            'resourceId' => self::RESOURCE,
            'ipPrefix' => self::IP_PREFIX,
            'uaHash' => self::UA_HASH,
            'difficulty' => self::DIFFICULTY,
            'issuedAt' => \time(),
        ]);

        $clearance = new Clearance(self::SECRET);
        $this->assertFalse($clearance->verify($cookie, self::PROJECT, self::RESOURCE, self::IP_PREFIX, self::UA_HASH, self::DIFFICULTY, self::TTL));
    }

    public function testIssueWithTtlOverrideDrivesExpiry(): void
    {
        // The default lifetime is 1800s, but a per-rule 3600s override must win.
        $clearance = new Clearance(self::SECRET);
        $before = \time();
        $cookie = $clearance->issue(self::PROJECT, self::RESOURCE, self::IP_PREFIX, self::UA_HASH, self::DIFFICULTY, 3600);

        $claims = new JWT($this->key(), 'HS256', Clearance::DEFAULT_TTL, 0)->decode($cookie);
        $this->assertGreaterThanOrEqual($before + 3600, $claims['exp']);
        $this->assertLessThanOrEqual(\time() + 3600, $claims['exp']);

        // A longer-lived cookie is still a valid, correctly-bound clearance.
        $this->assertTrue($clearance->verify($cookie, self::PROJECT, self::RESOURCE, self::IP_PREFIX, self::UA_HASH, self::DIFFICULTY, self::TTL));
    }

    public function testIssueWithoutTtlOverrideUsesDefaultLifetime(): void
    {
        $clearance = new Clearance(self::SECRET);
        $before = \time();
        $cookie = $clearance->issue(self::PROJECT, self::RESOURCE, self::IP_PREFIX, self::UA_HASH, self::DIFFICULTY);

        $claims = new JWT($this->key(), 'HS256', Clearance::DEFAULT_TTL, 0)->decode($cookie);
        $this->assertGreaterThanOrEqual($before + Clearance::DEFAULT_TTL, $claims['exp']);
        $this->assertLessThanOrEqual(\time() + Clearance::DEFAULT_TTL, $claims['exp']);
    }

    private function key(): string
    {
        return \hash_hmac('sha256', Clearance::KEY_LABEL, self::SECRET);
    }
}
