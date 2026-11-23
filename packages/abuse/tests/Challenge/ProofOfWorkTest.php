<?php

namespace Utopia\Abuse\Tests\Challenge;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Abuse\Challenge\Nonce;
use Utopia\Abuse\Challenge\ProofOfWork;
use Utopia\Abuse\Challenge\Status;
use Utopia\Abuse\Challenge\Token;
use Utopia\Abuse\Tests\Challenge\Nonce\Memory;

final class ProofOfWorkTest extends TestCase
{
    private const string SECRET = 'pow-secret';

    private const array BINDING = ['ip' => '203.0.113.0/24', 'ua' => 'ua-hash'];

    private function token(string $purpose = 'challenge'): Token
    {
        return new Token(self::SECRET, 'label', $purpose, 120);
    }

    private function pow(?Nonce $nonces = null, bool $acceptWhenNoncesUnavailable = false, string $secret = self::SECRET): ProofOfWork
    {
        return new ProofOfWork($secret, $nonces ?? new Memory(), 8, 120, $acceptWhenNoncesUnavailable, 'label');
    }

    // --- difficultyOf / isSolution ------------------------------------

    public function testDifficultyOfCountsWholeZeroBytes(): void
    {
        $this->assertSame(256, ProofOfWork::difficultyOf(\str_repeat("\x00", 32)));
        $this->assertSame(0, ProofOfWork::difficultyOf("\xff" . \str_repeat("\x00", 31)));
        $this->assertSame(8, ProofOfWork::difficultyOf("\x00\xff" . \str_repeat("\x00", 30)));
        $this->assertSame(20, ProofOfWork::difficultyOf("\x00\x00\x0f" . \str_repeat("\x00", 29)));
        $this->assertSame(4, ProofOfWork::difficultyOf("\x08" . \str_repeat("\x00", 31)));
    }

    /**
     * The server counts over raw digest bytes; a browser counts over the hex
     * string. They must agree so a client-found solution always passes.
     */
    public function testByteAndHexCountingAgree(): void
    {
        for ($i = 0; $i < 500; $i++) {
            $digest = \hash('sha256', 'sample:' . $i, true);
            $this->assertSame(ProofOfWork::difficultyOf($digest), $this->difficultyOfHex(\bin2hex($digest)), 'Mismatch at sample ' . $i);
        }
    }

    public function testIsSolutionAcceptsAValidAnswerAndRejectsOthers(): void
    {
        $nonce = 'abcdef0123456789';
        $solution = $this->solve($nonce, 8);

        $this->assertTrue(ProofOfWork::isSolution($nonce, $solution, 8));
        $this->assertFalse(ProofOfWork::isSolution($nonce, $solution, 32));
        $this->assertTrue(ProofOfWork::isSolution($nonce, '0', 0));
    }

    // --- issue ----------------------------------------------------------------

    public function testIssueSignsNonceDifficultyBindingAndClaims(): void
    {
        $token = $this->pow()->withParams(self::BINDING)->issue(['project' => 'p1', 'ttl' => 3600], 12);

        $claims = $this->token()->verify($token);
        $this->assertIsArray($claims);
        $this->assertIsString($claims['nonce']);
        $this->assertSame(32, \strlen($claims['nonce']));
        $this->assertSame(12, $claims['difficulty']);
        $this->assertSame('203.0.113.0/24', $claims['ip']);
        $this->assertSame('p1', $claims['project']);
        $this->assertSame(3600, $claims['ttl']);
    }

    public function testTheSeedIsTheTokensHash(): void
    {
        $token = $this->pow()->issue();

        $this->assertSame(\hash('sha256', $token), ProofOfWork::seed($token));
        $this->assertNotSame(ProofOfWork::seed($token), ProofOfWork::seed($this->pow()->issue()));
    }

    #[DataProvider('outOfRangeDifficulties')]
    public function testIssueRejectsDifficultyOutsideTheDigestRange(int $difficulty): void
    {
        // Below 0 every solution passes; above 256 (the SHA-256 width) none can.
        $this->expectException(\InvalidArgumentException::class);
        $this->pow()->issue(difficulty: $difficulty);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function outOfRangeDifficulties(): iterable
    {
        yield 'negative' => [-1];
        yield 'wider than the digest' => [257];
    }

    #[DataProvider('outOfRangeDifficulties')]
    public function testConstructorRejectsDifficultyOutsideTheDigestRange(int $difficulty): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProofOfWork(self::SECRET, new Memory(), $difficulty);
    }

    public function testIssueUsesTheConstructorDifficultyUnlessOverridden(): void
    {
        $pow = new ProofOfWork(self::SECRET, new Memory(), 10, label: 'label');

        $this->assertSame(10, $this->difficultyIn($pow->issue()));
        $this->assertSame(12, $this->difficultyIn($pow->issue(difficulty: 12)));
    }

    public function testIssueAcceptsTheDigestRangeBounds(): void
    {
        $this->assertSame(0, $this->difficultyIn($this->pow()->issue(difficulty: 0)));
        $this->assertSame(256, $this->difficultyIn($this->pow()->issue(difficulty: 256)));
    }

    #[DataProvider('reservedNames')]
    public function testIssueRefusesReservedClaimNames(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pow()->issue([$name => 'x']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reservedNames(): iterable
    {
        yield 'nonce' => ['nonce'];
        yield 'difficulty' => ['difficulty'];
        yield 'issue time' => ['iatMs'];
        yield 'purpose' => [Token::PURPOSE_CLAIM];
    }

    public function testIssueRefusesABindingThatSharesANameWithAClaim(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pow()->withParams(['ip' => 'a'])->issue(['ip' => 'b'], 8);
    }

    // --- verify ---------------------------------------------------------------

    public function testVerifyAcceptsAValidSolutionAndReturnsTheClaims(): void
    {
        $pow = $this->pow();
        $puzzle = $pow->withParams(self::BINDING)->issue(['project' => 'p1'], 8);

        $result = $pow->withParams(self::BINDING)->verify($puzzle, $this->solve(ProofOfWork::seed($puzzle), 8));

        $this->assertSame(Status::Valid, $result->status);
        $this->assertTrue($result->isValid());
        $this->assertSame(8, $result->difficulty);
        $this->assertSame('p1', $result->claims['project']);
        $this->assertNull($result->error);
    }

    public function testVerifyRejectsReplayOfTheSameSubmission(): void
    {
        $pow = $this->pow();
        $puzzle = $pow->withParams(self::BINDING)->issue(difficulty: 8);
        $solution = $this->solve(ProofOfWork::seed($puzzle), 8);

        $this->assertSame(Status::Valid, $pow->withParams(self::BINDING)->verify($puzzle, $solution)->status);
        $this->assertSame(Status::Replay, $pow->withParams(self::BINDING)->verify($puzzle, $solution)->status);
    }

    public function testNonceIsUsedEvenWhenTheSolutionIsWrong(): void
    {
        // One puzzle buys one attempt, so a wrong guess cannot be followed by a right one.
        $pow = $this->pow();
        $puzzle = $pow->withParams(self::BINDING)->issue(difficulty: 12);

        $this->assertSame(Status::Rejected, $pow->withParams(self::BINDING)->verify($puzzle, $this->wrong(ProofOfWork::seed($puzzle), 12))->status);
        $this->assertSame(Status::Replay, $pow->withParams(self::BINDING)->verify($puzzle, $this->solve(ProofOfWork::seed($puzzle), 12))->status);
    }

    public function testBindingMismatchDoesNotUseTheNonce(): void
    {
        $pow = $this->pow();
        $puzzle = $pow->withParams(self::BINDING)->issue(difficulty: 8);
        $solution = $this->solve(ProofOfWork::seed($puzzle), 8);

        $mismatch = $pow->withParams(['ip' => '198.51.100.0/24', 'ua' => 'ua-hash'])->verify($puzzle, $solution);
        $this->assertSame(Status::BindingMismatch, $mismatch->status);
        $this->assertSame([], $mismatch->claims);

        // The owner can still redeem it.
        $this->assertSame(Status::Valid, $pow->withParams(self::BINDING)->verify($puzzle, $solution)->status);
    }

    public function testVerifyingWithPartOfTheBindingIsRejected(): void
    {
        $pow = $this->pow();
        $puzzle = $pow->withParams(self::BINDING)->issue(difficulty: 8);
        $solution = $this->solve(ProofOfWork::seed($puzzle), 8);

        $this->assertSame(Status::BindingMismatch, $pow->withParams(['ip' => self::BINDING['ip']])->verify($puzzle, $solution)->status);
        $this->assertSame(Status::BindingMismatch, $pow->verify($puzzle, $solution)->status);
        // Neither attempt used the nonce.
        $this->assertSame(Status::Valid, $pow->withParams(self::BINDING)->verify($puzzle, $solution)->status);
    }

    public function testWithParamsReturnsACopyAndMerges(): void
    {
        $pow = $this->pow();
        $bound = $pow->withParams(['ip' => self::BINDING['ip']])->withParams(['ua' => self::BINDING['ua']]);
        $puzzle = $bound->issue(difficulty: 8);
        $solution = $this->solve(ProofOfWork::seed($puzzle), 8);

        // The original stays unbound, so it cannot redeem a bound puzzle.
        $this->assertSame(Status::BindingMismatch, $pow->verify($puzzle, $solution)->status);
        $this->assertSame(Status::Valid, $pow->withParams(self::BINDING)->verify($puzzle, $solution)->status);
    }

    public function testDefaultsWork(): void
    {
        $pow = new ProofOfWork(self::SECRET, new Memory(), 8);
        $before = \time();
        $puzzle = $pow->issue(difficulty: 8);

        $this->assertSame(Status::Valid, $pow->verify($puzzle, $this->solve(ProofOfWork::seed($puzzle), 8))->status);

        // Default label and purpose are "challenge"; default lifetime is DEFAULT_TTL.
        $claims = new Token(self::SECRET, 'challenge', 'challenge', 1)->verify($puzzle);
        $this->assertIsArray($claims);
        $this->assertGreaterThanOrEqual($before + ProofOfWork::DEFAULT_TTL, $claims['exp']);
        $this->assertLessThanOrEqual(\time() + ProofOfWork::DEFAULT_TTL, $claims['exp']);
    }

    public function testBindingIsMissingFromTheToken(): void
    {
        $pow = $this->pow();
        $puzzle = $pow->issue(difficulty: 8);

        $this->assertSame(Status::BindingMismatch, $pow->withParams(self::BINDING)->verify($puzzle, $this->solve(ProofOfWork::seed($puzzle), 8))->status);
    }

    public function testNonceParamsAreFilledFromTheTokenClaims(): void
    {
        $nonces = new Memory('pow:{project}');
        $pow = $this->pow($nonces);
        $puzzle = $pow->issue(['project' => 'p1'], 8);

        $pow->verify($puzzle, $this->solve(ProofOfWork::seed($puzzle), 8));

        // The store key uses the token's random nonce claim, not the seed.
        $nonce = $this->token()->verify($puzzle)['nonce'] ?? null;
        $this->assertIsString($nonce);
        $this->assertTrue($nonces->claimed->offsetExists('pow:p1:' . $nonce));
        // The nonce stays used for the token's lifetime.
        $this->assertSame(120, $nonces->claimed['pow:p1:' . $nonce]);
    }

    public function testVerifyRejectsGarbageTamperedAndForeignTokens(): void
    {
        $pow = $this->pow();
        $this->assertSame(Status::InvalidToken, $pow->verify('not.a.jwt', '0')->status);

        $puzzle = $pow->issue(difficulty: 8);
        $tampered = $puzzle;
        $tampered[\strlen($tampered) - 1] = $tampered[\strlen($tampered) - 1] === 'a' ? 'b' : 'a';
        $this->assertSame(Status::InvalidToken, $pow->verify($tampered, $this->solve(ProofOfWork::seed($puzzle), 8))->status);

        $foreign = $this->pow(secret: 'other-secret')->issue(difficulty: 8);
        $this->assertSame(Status::InvalidToken, $pow->verify($foreign, $this->solve(ProofOfWork::seed($foreign), 8))->status);
    }

    public function testATokenIssuedForAnotherPurposeIsNotAPuzzle(): void
    {
        $other = $this->token('clearance')->issue(['nonce' => \bin2hex(\random_bytes(16)), 'difficulty' => 0]);

        $this->assertSame(Status::InvalidToken, $this->pow()->verify($other, '0')->status);
    }

    public function testATokenWithoutANonceOrWithABadDifficultyIsInvalid(): void
    {
        $this->assertSame(Status::InvalidToken, $this->pow()->verify($this->token()->issue(['difficulty' => 0]), '0')->status);
        $this->assertSame(Status::InvalidToken, $this->pow()->verify($this->token()->issue(['nonce' => 'n', 'difficulty' => -1]), '0')->status);
        $this->assertSame(Status::InvalidToken, $this->pow()->verify($this->token()->issue(['nonce' => 'n', 'difficulty' => '8']), '0')->status);
    }

    // --- nonce store outage ---------------------------------------------------

    public function testStoreOutageRejectsByDefault(): void
    {
        $error = new \RuntimeException('down');
        $pow = $this->pow(new Memory(error: $error));
        $puzzle = $pow->issue(difficulty: 8);

        $result = $pow->verify($puzzle, $this->solve(ProofOfWork::seed($puzzle), 8));

        $this->assertSame(Status::Unavailable, $result->status);
        $this->assertSame($error, $result->error);
    }

    public function testStoreOutageChecksTheSolutionWhenTheCallerAcceptsIt(): void
    {
        // Treating an outage as a replay would loop a genuine solver forever;
        // a caller may choose to skip the replay guard and still check the solution.
        $error = new \RuntimeException('down');
        $pow = $this->pow(new Memory(error: $error), acceptWhenNoncesUnavailable: true);

        $puzzle = $pow->issue(difficulty: 8);
        $valid = $pow->verify($puzzle, $this->solve(ProofOfWork::seed($puzzle), 8));
        $this->assertSame(Status::Valid, $valid->status);
        $this->assertSame($error, $valid->error);

        $puzzle = $pow->issue(difficulty: 12);
        $this->assertSame(Status::Rejected, $pow->verify($puzzle, $this->wrong(ProofOfWork::seed($puzzle), 12))->status);
    }

    // --- helpers --------------------------------------------------------------

    private function difficultyIn(string $token): int
    {
        $difficulty = $this->token()->verify($token)['difficulty'] ?? null;
        $this->assertIsInt($difficulty);

        return $difficulty;
    }

    private function solve(string $nonce, int $difficulty): string
    {
        $counter = 0;
        while (!ProofOfWork::isSolution($nonce, (string) $counter, $difficulty)) {
            $counter++;
        }

        return (string) $counter;
    }

    /**
     * A solution this nonce provably does not accept, judged by this file's own
     * hex counter so a isSolution() that accepted everything would be caught.
     */
    private function wrong(string $nonce, int $difficulty): string
    {
        for ($counter = 0; $counter < 64; $counter++) {
            $candidate = 'wrong-' . $counter;
            if ($this->difficultyOfHex(\hash('sha256', $nonce . ':' . $candidate)) < $difficulty) {
                return $candidate;
            }
        }

        $this->fail("No candidate misses a {$difficulty}-bit target for nonce {$nonce}");
    }

    private function difficultyOfHex(string $hex): int
    {
        $bits = 0;
        $length = \strlen($hex);
        for ($i = 0; $i < $length; $i++) {
            $value = \intval($hex[$i], 16);
            if ($value === 0) {
                $bits += 4;

                continue;
            }
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
