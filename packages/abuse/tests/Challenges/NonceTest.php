<?php

namespace Utopia\Abuse\Tests\Challenges;

use PHPUnit\Framework\TestCase;
use Utopia\Abuse\Challenges\Nonce\None;
use Utopia\Abuse\Tests\Challenges\Nonce\Memory;

final class NonceTest extends TestCase
{
    public function testFirstClaimSucceedsAndLaterClaimsFail(): void
    {
        $nonces = new Memory();

        $this->assertTrue($nonces->consume('abc', 60));
        $this->assertFalse($nonces->consume('abc', 60));
        $this->assertTrue($nonces->consume('def', 60));
    }

    public function testEmptyNonceNeverClaims(): void
    {
        $nonces = new Memory();

        $this->assertFalse($nonces->consume('', 60));
        $this->assertCount(0, $nonces->claimed);
    }

    public function testKeyPatternIsFilledByParamsAndTheNonceIsAppended(): void
    {
        $nonces = new Memory('pow:{project}')->withParam('{project}', 'p1');

        $this->assertSame('pow:p1:abc', $nonces->key('abc'));
        $this->assertTrue($nonces->consume('abc', 60));
        $this->assertTrue($nonces->claimed->offsetExists('pow:p1:abc'));
    }

    public function testParamsSeparateKeySpaces(): void
    {
        $nonces = new Memory('pow:{project}');

        $this->assertTrue($nonces->withParam('{project}', 'p1')->consume('abc', 60));
        $this->assertTrue($nonces->withParam('{project}', 'p2')->consume('abc', 60));
        $this->assertFalse($nonces->withParam('{project}', 'p1')->consume('abc', 60));
    }

    public function testWithParamsDoesNotChangeTheOriginal(): void
    {
        $nonces = new Memory('pow:{project}');
        $nonces->withParam('{project}', 'p1');

        $this->assertSame('pow:{project}:abc', $nonces->key('abc'));
    }

    public function testTtlIsAtLeastOneSecond(): void
    {
        $nonces = new Memory();
        $nonces->consume('abc', 0);

        $this->assertSame(1, $nonces->claimed['pow:abc']);
    }

    public function testNoneClaimsEveryNonEmptyNonce(): void
    {
        $nonces = new None();

        $this->assertTrue($nonces->consume('abc', 60));
        $this->assertTrue($nonces->consume('abc', 60));
        $this->assertFalse($nonces->consume('', 60));
    }
}
