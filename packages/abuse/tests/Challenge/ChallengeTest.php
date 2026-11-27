<?php

namespace Utopia\Abuse\Tests\Challenge;

use PHPUnit\Framework\TestCase;
use Utopia\Abuse\Challenge\Status;

/**
 * The shared base, exercised through the smallest possible challenge.
 */
final class ChallengeTest extends TestCase
{
    private const array CLIENT = ['ip' => '203.0.113.0/24', 'ua' => 'ua-hash'];

    private function riddle(string $secret = 'secret'): Riddle
    {
        return new Riddle($secret, 'echo');
    }

    public function testANewKindNeedsOnlyIssueAndVerify(): void
    {
        $riddle = $this->riddle()->withParams(self::CLIENT);
        $token = $riddle->issue(['project' => 'p1']);

        $valid = $riddle->verify($token->value, 'echo');
        $this->assertSame(Status::Valid, $valid->status);
        $this->assertSame('p1', $valid->claims['project']);
        $this->assertSame(Status::Rejected, $riddle->verify($token->value, 'wrong')->status);
    }

    public function testParamsAreEnforcedByTheBase(): void
    {
        $token = $this->riddle()->withParams(self::CLIENT)->issue();

        $this->assertSame(Status::BindingMismatch, $this->riddle()->verify($token->value, 'echo')->status);
        $this->assertSame(Status::BindingMismatch, $this->riddle()->withParam('ip', '203.0.113.0/24')->verify($token->value, 'echo')->status);
        $this->assertSame(Status::Valid, $this->riddle()->withParam('ip', '203.0.113.0/24')->withParam('ua', 'ua-hash')->verify($token->value, 'echo')->status);
    }

    public function testWithParamsReturnsACopy(): void
    {
        $riddle = $this->riddle();
        $riddle->withParams(self::CLIENT);

        // The original stayed unbound, so its tokens carry no binding.
        $this->assertSame(Status::Valid, $riddle->verify($riddle->issue()->value, 'echo')->status);
    }

    public function testInvalidTokensAreRejectedByTheBase(): void
    {
        $this->assertSame(Status::InvalidToken, $this->riddle()->verify('not.a.jwt', 'echo')->status);
        $this->assertSame(Status::InvalidToken, $this->riddle()->verify($this->riddle('other')->issue()->value, 'echo')->status);
    }

    public function testTheKindsOwnClaimsCannotBeOverridden(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->riddle()->issue(['answer' => 'x']);
    }

    public function testParamsCannotReuseTheKindsOwnClaims(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->riddle()->withParam('answer', 'x')->issue();
    }
}
