<?php

namespace Utopia\Abuse\Tests\E2E\Challenges\Nonce;

use PHPUnit\Framework\TestCase;
use Utopia\Abuse\Challenges\Nonce;

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
        \sleep(2);
        $this->assertTrue($nonces->consume($nonce, 1));
    }

    public function testEmptyNonceNeverClaims(): void
    {
        $this->assertFalse($this->getNonce('nonce-' . \uniqid())->consume('', 60));
    }
}
