<?php

namespace Utopia\Abuse\Tests\E2E\Challenge\Nonce;

use PHPUnit\Framework\TestCase;
use Utopia\Abuse\Challenge\Nonce;

abstract class Base extends TestCase
{
    abstract public function getNonce(string $key): Nonce;

    public function testFirstClaimSucceedsAndLaterClaimsFail(): void
    {
        $nonces = $this->getNonce('nonce-' . \uniqid());
        $nonce = \bin2hex(\random_bytes(16));

        $this->assertTrue($nonces->consume($nonce, 60));
        $this->assertFalse($nonces->consume($nonce, 60));
    }

    public function testParamsSeparateKeySpaces(): void
    {
        $nonces = $this->getNonce('nonce-' . \uniqid() . ':{project}');
        $nonce = \bin2hex(\random_bytes(16));

        $this->assertTrue($nonces->withParam('{project}', 'p1')->consume($nonce, 60));
        $this->assertTrue($nonces->withParam('{project}', 'p2')->consume($nonce, 60));
        $this->assertFalse($nonces->withParam('{project}', 'p1')->consume($nonce, 60));
    }

    public function testClaimExpires(): void
    {
        $nonces = $this->getNonce('nonce-' . \uniqid());
        $nonce = \bin2hex(\random_bytes(16));

        $this->assertTrue($nonces->consume($nonce, 1));
        $this->assertFalse($nonces->consume($nonce, 1));

        // Poll rather than sleep: the claim becomes available once Redis expires it.
        $deadline = \microtime(true) + 5;
        while (!$nonces->consume($nonce, 1)) {
            $this->assertLessThan($deadline, \microtime(true), 'The claim did not expire within 5 seconds');
            \usleep(50_000);
        }
    }

    public function testEmptyNonceNeverClaims(): void
    {
        $this->assertFalse($this->getNonce('nonce-' . \uniqid())->consume('', 60));
    }
}
