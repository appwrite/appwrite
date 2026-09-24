<?php

declare(strict_types=1);

namespace Utopia\Tests\Auth\Proofs;

use PHPUnit\Framework\TestCase;
use Utopia\Auth\Hashes\Sha;
use Utopia\Auth\Proofs\Token;

final class TokenTest extends TestCase
{
    protected Token $token;

    protected function setUp(): void
    {
        $this->token = new Token(32);
        $this->token->setHash(new Sha());
        /** @var Sha */
        $hash = $this->token->getHash();
        $hash->setVersion('sha256');
    }

    public function testGenerate(): void
    {
        $proof = $this->token->generate();

        $this->assertNotEmpty($proof);
        $this->assertSame(32, \strlen($proof)); // Default token length
    }

    public function testHash(): void
    {
        $proof = $this->token->generate();
        $hash = $this->token->hash($proof);

        $this->assertNotEmpty($hash);
        $this->assertSame(64, \strlen($hash)); // SHA-256 produces a 64-character hex string
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash); // SHA-256 hex format
    }

    public function testVerify(): void
    {
        $proof = $this->token->generate();
        $hash = $this->token->hash($proof);

        $this->assertTrue($this->token->verify($proof, $hash));
        $this->assertFalse($this->token->verify('wrongtoken', $hash));
    }

    public function testGetLength(): void
    {
        $this->assertSame(32, $this->token->getLength());

        $token = new Token(64);
        $this->assertSame(64, $token->getLength());
    }

    public function testSetLength(): void
    {
        $this->token->setLength(64);
        $this->assertSame(64, $this->token->getLength());

        $proof = $this->token->generate();
        $this->assertSame(64, \strlen($proof));
    }

    public function testSetLengthInvalid(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Token length must be greater than 0');
        $this->token->setLength(0);
    }
}
