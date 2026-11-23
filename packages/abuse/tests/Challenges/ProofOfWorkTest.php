<?php

namespace Utopia\Abuse\Tests\Challenges;

use Ahc\Jwt\JWT;
use Ahc\Jwt\JWTException;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Abuse\Challenges\Clearance;
use Utopia\Abuse\Challenges\ProofOfWork;
use Utopia\Abuse\Challenges\ProofOfWork\Nonce\Outcome;
use Utopia\Abuse\Challenges\ProofOfWork\Status;
use Utopia\Abuse\Tests\Challenges\ProofOfWork\Nonce\InMemoryConsumer;

final class ProofOfWorkTest extends TestCase
{
    private const string SECRET = 'test-secret-key';
    private const string IP_PREFIX = '203.0.113.0/24';
    private const string UA_HASH = 'ua-hash-abc';

    // --- leadingZeroBits -----------------------------------------------------

    public function testLeadingZeroBitsCountsWholeZeroBytes(): void
    {
        $this->assertSame(256, ProofOfWork::leadingZeroBits(\str_repeat("\x00", 32)));
        $this->assertSame(0, ProofOfWork::leadingZeroBits("\xff" . \str_repeat("\x00", 31)));
        $this->assertSame(8, ProofOfWork::leadingZeroBits("\x00\xff" . \str_repeat("\x00", 30)));
        $this->assertSame(20, ProofOfWork::leadingZeroBits("\x00\x00\x0f" . \str_repeat("\x00", 29)));
        $this->assertSame(4, ProofOfWork::leadingZeroBits("\x08" . \str_repeat("\x00", 31)));
    }

    /**
     * The server counts leading zero bits over raw digest bytes; the browser
     * counts over the hex string. Prove the two agree on every digest so a
     * client-found solution always satisfies the server's check.
     */
    public function testByteAndHexCountingAgree(): void
    {
        for ($i = 0; $i < 500; $i++) {
            $digest = \hash('sha256', 'sample:' . $i, true);
            $this->assertSame(
                ProofOfWork::leadingZeroBits($digest),
                $this->leadingZeroBitsOverHex(\bin2hex($digest)),
                'Mismatch at sample ' . $i,
            );
        }
    }

    // --- meetsDifficulty -----------------------------------------------------

    public function testMeetsDifficultyAcceptsAValidSolutionAndRejectsOthers(): void
    {
        $nonce = 'abcdef0123456789';
        $solution = $this->solve($nonce, 8);

        $this->assertTrue(ProofOfWork::meetsDifficulty($nonce, $solution, 8));
        // A solution valid at difficulty 8 need not hold at a much harder target.
        $this->assertFalse(ProofOfWork::meetsDifficulty($nonce, $solution, 32));
        // Difficulty 0 is trivially satisfied by anything.
        $this->assertTrue(ProofOfWork::meetsDifficulty($nonce, '0', 0));
    }

    // --- issue ---------------------------------------------------------------

    public function testIssueProducesABoundToken(): void
    {
        $pow = new ProofOfWork(self::SECRET, new InMemoryConsumer());
        $issued = $pow->issue('proj1', self::IP_PREFIX, self::UA_HASH, 12);

        $this->assertSame(12, $issued['difficulty']);
        $this->assertSame(32, \strlen($issued['nonce'])); // 16 bytes hex
        $this->assertCount(3, \explode('.', $issued['token']));

        $claims = new JWT($this->key(), 'HS256', ProofOfWork::DEFAULT_TTL, 0)->decode($issued['token']);
        $this->assertSame('proj1', $claims['projectId']);
        $this->assertSame(self::IP_PREFIX, $claims['ipPrefix']);
        $this->assertSame(self::UA_HASH, $claims['uaHash']);
        $this->assertSame($issued['nonce'], $claims['nonce']);
    }

    // --- bitsForLevel --------------------------------------------------------

    public function testBitsForLevelMapsTheOneToFiveScale(): void
    {
        $this->assertSame(14, ProofOfWork::bitsForLevel(1));
        $this->assertSame(16, ProofOfWork::bitsForLevel(2));
        $this->assertSame(18, ProofOfWork::bitsForLevel(3));
        $this->assertSame(20, ProofOfWork::bitsForLevel(4));
        $this->assertSame(21, ProofOfWork::bitsForLevel(5));
    }

    #[DataProvider('unsolvableOrTrivialDifficulties')]
    public function testIssueRejectsDifficultyOutsideTheDigestRange(int $difficulty): void
    {
        // Below 0 every solution passes; above 256 (the SHA-256 width) none can.
        $pow = new ProofOfWork(self::SECRET, new InMemoryConsumer());

        $this->expectException(\InvalidArgumentException::class);
        $pow->issue('proj1', self::IP_PREFIX, self::UA_HASH, $difficulty);
    }

    /**
     * @return Iterator<string, array{int}>
     */
    public static function unsolvableOrTrivialDifficulties(): Iterator
    {
        yield 'negative' => [-1];
        yield 'wider than the digest' => [257];
    }

    public function testIssueAcceptsTheDigestRangeBounds(): void
    {
        $pow = new ProofOfWork(self::SECRET, new InMemoryConsumer());

        $this->assertSame(0, $pow->issue('proj1', self::IP_PREFIX, self::UA_HASH, 0)['difficulty']);
        $this->assertSame(256, $pow->issue('proj1', self::IP_PREFIX, self::UA_HASH, 256)['difficulty']);
    }

    public function testBitsForLevelFallsBackToDefaultForUnknownLevels(): void
    {
        // 0 (unset) and out-of-range levels fall back to the default difficulty.
        $this->assertSame(ProofOfWork::DEFAULT_DIFFICULTY, ProofOfWork::bitsForLevel(0));
        $this->assertSame(ProofOfWork::DEFAULT_DIFFICULTY, ProofOfWork::bitsForLevel(6));
        $this->assertSame(ProofOfWork::DEFAULT_DIFFICULTY, ProofOfWork::bitsForLevel(-1));
    }

    // --- clearance TTL claim -------------------------------------------------

    public function testClearanceTtlRoundTripsFromIssueToVerify(): void
    {
        $pow = new ProofOfWork(self::SECRET, new InMemoryConsumer());
        $issued = $pow->issue('proj1', self::IP_PREFIX, self::UA_HASH, 8, 3600);
        $solution = $this->solve($issued['nonce'], 8);

        $result = $pow->verify($issued['token'], $solution, self::IP_PREFIX, self::UA_HASH);

        $this->assertSame(Status::Valid, $result->status);
        $this->assertSame(3600, $result->clearanceTtl);
    }

    public function testClearanceTtlDefaultsToZeroWhenNotIssued(): void
    {
        $pow = new ProofOfWork(self::SECRET, new InMemoryConsumer());
        $issued = $pow->issue('proj1', self::IP_PREFIX, self::UA_HASH, 8);
        $solution = $this->solve($issued['nonce'], 8);

        $result = $pow->verify($issued['token'], $solution, self::IP_PREFIX, self::UA_HASH);

        $this->assertSame(0, $result->clearanceTtl);
    }

    // --- verify: happy path --------------------------------------------------

    public function testVerifyAcceptsAValidSolution(): void
    {
        $pow = new ProofOfWork(self::SECRET, new InMemoryConsumer());
        $issued = $pow->issue('proj1', self::IP_PREFIX, self::UA_HASH, 8);
        $solution = $this->solve($issued['nonce'], 8);

        $result = $pow->verify($issued['token'], $solution, self::IP_PREFIX, self::UA_HASH);

        $this->assertSame(Status::Valid, $result->status);
        $this->assertTrue($result->isValid());
        $this->assertSame('proj1', $result->projectId);
    }

    // --- verify: replay (nonce burned) --------------------------------------

    public function testVerifyRejectsReplayOfSameSubmission(): void
    {
        $pow = new ProofOfWork(self::SECRET, new InMemoryConsumer());
        $issued = $pow->issue('proj1', self::IP_PREFIX, self::UA_HASH, 8);
        $solution = $this->solve($issued['nonce'], 8);

        $first = $pow->verify($issued['token'], $solution, self::IP_PREFIX, self::UA_HASH);
        $second = $pow->verify($issued['token'], $solution, self::IP_PREFIX, self::UA_HASH);

        $this->assertSame(Status::Valid, $first->status);
        $this->assertSame(Status::Replay, $second->status);
    }

    public function testNonceIsBurnedEvenWhenTheSolutionIsWrong(): void
    {
        // A bad-solution attempt still consumes the nonce, so a follow-up with the
        // correct solution on the same token is rejected as replay. This is what
        // makes one issued challenge worth exactly one attempt (blocks probing).
        $pow = new ProofOfWork(self::SECRET, new InMemoryConsumer());
        $issued = $pow->issue('proj1', self::IP_PREFIX, self::UA_HASH, 12);

        $bad = $pow->verify($issued['token'], $this->wrong($issued['nonce'], 12), self::IP_PREFIX, self::UA_HASH);
        $this->assertSame(Status::InvalidSolution, $bad->status);

        $good = $pow->verify($issued['token'], $this->solve($issued['nonce'], 12), self::IP_PREFIX, self::UA_HASH);
        $this->assertSame(Status::Replay, $good->status);
    }

    // --- verify: binding -----------------------------------------------------

    public function testVerifyRejectsIpBindingMismatchWithoutBurningNonce(): void
    {
        $consumer = new InMemoryConsumer();
        $pow = new ProofOfWork(self::SECRET, $consumer);
        $issued = $pow->issue('proj1', self::IP_PREFIX, self::UA_HASH, 8);
        $solution = $this->solve($issued['nonce'], 8);

        $mismatch = $pow->verify($issued['token'], $solution, '198.51.100.0/24', self::UA_HASH);
        $this->assertSame(Status::BindingMismatch, $mismatch->status);

        // Binding is checked before the burn, so the legitimate client can still
        // redeem the same token from the correct IP prefix.
        $ok = $pow->verify($issued['token'], $solution, self::IP_PREFIX, self::UA_HASH);
        $this->assertSame(Status::Valid, $ok->status);
    }

    public function testVerifyRejectsUaBindingMismatch(): void
    {
        $pow = new ProofOfWork(self::SECRET, new InMemoryConsumer());
        $issued = $pow->issue('proj1', self::IP_PREFIX, self::UA_HASH, 8);
        $solution = $this->solve($issued['nonce'], 8);

        $result = $pow->verify($issued['token'], $solution, self::IP_PREFIX, 'different-ua-hash');
        $this->assertSame(Status::BindingMismatch, $result->status);
    }

    // --- verify: bad tokens --------------------------------------------------

    public function testVerifyRejectsGarbageToken(): void
    {
        $pow = new ProofOfWork(self::SECRET, new InMemoryConsumer());
        $result = $pow->verify('not.a.jwt', '0', self::IP_PREFIX, self::UA_HASH);
        $this->assertSame(Status::InvalidToken, $result->status);
    }

    public function testVerifyRejectsTamperedToken(): void
    {
        $pow = new ProofOfWork(self::SECRET, new InMemoryConsumer());
        $issued = $pow->issue('proj1', self::IP_PREFIX, self::UA_HASH, 8);

        // Flip the last character of the signature.
        $token = $issued['token'];
        $token[\strlen($token) - 1] = $token[\strlen($token) - 1] === 'a' ? 'b' : 'a';

        $result = $pow->verify($token, $this->solve($issued['nonce'], 8), self::IP_PREFIX, self::UA_HASH);
        $this->assertSame(Status::InvalidToken, $result->status);
    }

    public function testVerifyRejectsTokenSignedWithAnotherSecret(): void
    {
        $foreign = new ProofOfWork('a-different-secret', new InMemoryConsumer());
        $issued = $foreign->issue('proj1', self::IP_PREFIX, self::UA_HASH, 8);

        $pow = new ProofOfWork(self::SECRET, new InMemoryConsumer());
        $result = $pow->verify($issued['token'], $this->solve($issued['nonce'], 8), self::IP_PREFIX, self::UA_HASH);
        $this->assertSame(Status::InvalidToken, $result->status);
    }

    public function testVerifyRejectsClearanceCookie(): void
    {
        // A clearance cookie shares the secret and binding claims but is not a challenge.
        $cookie = new Clearance(self::SECRET)->issue('proj1', '', self::IP_PREFIX, self::UA_HASH, ProofOfWork::DEFAULT_DIFFICULTY);

        $pow = new ProofOfWork(self::SECRET, new InMemoryConsumer());
        $result = $pow->verify($cookie, '0', self::IP_PREFIX, self::UA_HASH);
        $this->assertSame(Status::InvalidToken, $result->status);
    }

    /**
     * @return Iterator<string, array{?string}>
     */
    public static function otherPurposes(): Iterator
    {
        yield 'clearance purpose' => [Clearance::PURPOSE];
        yield 'no purpose' => [null];
    }

    #[DataProvider('otherPurposes')]
    public function testVerifyRejectsTokenWithoutTheChallengePurpose(?string $purpose): void
    {
        // Every challenge claim is valid except the purpose; the nonce must stay unburned.
        $nonce = \bin2hex(\random_bytes(16));
        $claims = [
            'nonce' => $nonce,
            'difficulty' => 8,
            'projectId' => 'proj1',
            'resourceId' => '',
            'ipPrefix' => self::IP_PREFIX,
            'uaHash' => self::UA_HASH,
            'iatMs' => (int) (\microtime(true) * 1000),
        ];
        if ($purpose !== null) {
            $claims[ProofOfWork::PURPOSE_CLAIM] = $purpose;
        }
        $token = new JWT($this->key(), 'HS256', ProofOfWork::DEFAULT_TTL, 0)->encode($claims);

        $consumer = new InMemoryConsumer();
        $pow = new ProofOfWork(self::SECRET, $consumer);
        $result = $pow->verify($token, $this->solve($nonce, 8), self::IP_PREFIX, self::UA_HASH);
        $this->assertSame(Status::InvalidToken, $result->status);
        $this->assertSame(Outcome::Fresh, $consumer->consume('proj1', $nonce, ProofOfWork::DEFAULT_TTL));
    }

    public function testVerifyRejectsTokenSignedWithTheConfiguredSecret(): void
    {
        // Only the challenge-derived key is accepted, never the configured secret itself.
        $nonce = \bin2hex(\random_bytes(16));
        $token = new JWT(self::SECRET, 'HS256', ProofOfWork::DEFAULT_TTL, 0)->encode([
            ProofOfWork::PURPOSE_CLAIM => ProofOfWork::PURPOSE,
            'nonce' => $nonce,
            'difficulty' => 8,
            'projectId' => 'proj1',
            'resourceId' => '',
            'ipPrefix' => self::IP_PREFIX,
            'uaHash' => self::UA_HASH,
            'iatMs' => (int) (\microtime(true) * 1000),
        ]);

        $consumer = new InMemoryConsumer();
        $pow = new ProofOfWork(self::SECRET, $consumer);
        $result = $pow->verify($token, $this->solve($nonce, 8), self::IP_PREFIX, self::UA_HASH);
        $this->assertSame(Status::InvalidToken, $result->status);
        $this->assertSame(Outcome::Fresh, $consumer->consume('proj1', $nonce, ProofOfWork::DEFAULT_TTL));
    }

    public function testIssuedTokenIsNotSignedWithTheConfiguredSecret(): void
    {
        $issued = new ProofOfWork(self::SECRET, new InMemoryConsumer())->issue('proj1', self::IP_PREFIX, self::UA_HASH, 8);

        $this->expectException(JWTException::class);
        new JWT(self::SECRET, 'HS256', ProofOfWork::DEFAULT_TTL, 0)->decode($issued['token']);
    }

    public function testVerifyRejectsExpiredToken(): void
    {
        // Mint a token whose iat/exp are in the past by spoofing the JWT clock.
        $jwt = new JWT($this->key(), 'HS256', ProofOfWork::DEFAULT_TTL, 0);
        $jwt->setTestTimestamp(\time() - 10_000);
        $token = $jwt->encode([
            ProofOfWork::PURPOSE_CLAIM => ProofOfWork::PURPOSE,
            'nonce' => \bin2hex(\random_bytes(16)),
            'difficulty' => 8,
            'projectId' => 'proj1',
            'ipPrefix' => self::IP_PREFIX,
            'uaHash' => self::UA_HASH,
        ]);

        $pow = new ProofOfWork(self::SECRET, new InMemoryConsumer());
        $result = $pow->verify($token, '0', self::IP_PREFIX, self::UA_HASH);
        $this->assertSame(Status::InvalidToken, $result->status);
    }

    public function testVerifyFailsOpenOnNonceWhenStoreIsUnavailable(): void
    {
        $pow = new ProofOfWork(self::SECRET, new InMemoryConsumer(unavailable: true));
        $issued = $pow->issue('proj1', self::IP_PREFIX, self::UA_HASH, 8);
        $solution = $this->solve($issued['nonce'], 8);

        // A store outage must NOT be treated as a replay: that would loop a
        // legitimate solver forever (403 → re-challenge → solve → still down …).
        // The replay guard is skipped, but the solution is still validated, so a
        // correct solve passes and the client is let through.
        $result = $pow->verify($issued['token'], $solution, self::IP_PREFIX, self::UA_HASH);
        $this->assertSame(Status::Valid, $result->status);
    }

    public function testVerifyStillRejectsBadSolutionWhenStoreIsUnavailable(): void
    {
        // Failing open on the nonce only skips the replay guard — an incorrect
        // solution is still rejected, so the outage does not open the gate.
        $pow = new ProofOfWork(self::SECRET, new InMemoryConsumer(unavailable: true));
        $issued = $pow->issue('proj1', self::IP_PREFIX, self::UA_HASH, 12);

        $result = $pow->verify($issued['token'], $this->wrong($issued['nonce'], 12), self::IP_PREFIX, self::UA_HASH);
        $this->assertSame(Status::InvalidSolution, $result->status);
    }

    // --- helpers -------------------------------------------------------------

    private function key(): string
    {
        return \hash_hmac('sha256', ProofOfWork::KEY_LABEL, self::SECRET);
    }

    private function solve(string $nonce, int $difficulty): string
    {
        $counter = 0;
        while (!ProofOfWork::meetsDifficulty($nonce, (string) $counter, $difficulty)) {
            $counter++;
        }

        return (string) $counter;
    }

    /**
     * A solution this nonce provably does not accept, chosen without asking
     * the predicate under test.
     *
     * A fixed string like 'definitely-wrong' is not one: the nonce is random,
     * so at difficulty 12 it satisfies the target once every 4096 issues and
     * the assertion reads Valid instead.
     *
     * The candidate is judged against the rule the challenge is specified by —
     * the leading zero bits of SHA-256(nonce ":" solution), counted with this
     * file's own hex counter, the independent implementation
     * {@see testByteAndHexCountingAgree()} already holds the server's to. So a
     * `meetsDifficulty()` that accepted everything would be caught by the
     * assertion rather than consulted for the fixture, and the search is
     * bounded so it can never hang either way.
     */
    private function wrong(string $nonce, int $difficulty): string
    {
        for ($counter = 0; $counter < 64; $counter++) {
            $candidate = 'wrong-' . $counter;
            if ($this->leadingZeroBitsOverHex(\hash('sha256', $nonce . ':' . $candidate)) < $difficulty) {
                return $candidate;
            }
        }

        $this->fail("No candidate misses a {$difficulty}-bit target for nonce {$nonce}, which SHA-256 makes a 1-in-2^64 event");
    }

    private function leadingZeroBitsOverHex(string $hex): int
    {
        $bits = 0;
        $length = \strlen($hex);
        for ($i = 0; $i < $length; $i++) {
            $value = \intval($hex[$i], 16);
            if ($value === 0) {
                $bits += 4;

                continue;
            }
            // Leading zeros within this 4-bit nibble.
            for ($mask = 0x8; $mask > 0; $mask >>= 1) {
                if (($value & $mask) !== 0) {
                    return $bits;
                }
                $bits++;
            }
        }

        return $bits;
    }
}
