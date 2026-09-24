<?php

declare(strict_types=1);

namespace Utopia\Tests\Auth\Algorithms;

use PHPUnit\Framework\TestCase;
use Utopia\Auth\Hashes\Plaintext;

final class PlaintextTest extends TestCase
{
    protected Plaintext $plaintext;

    protected function setUp(): void
    {
        $this->plaintext = new Plaintext();
    }

    public function testHash(): void
    {
        $password = 'test123';
        $hash = $this->plaintext->hash($password);

        $this->assertNotEmpty($hash);
        $this->assertSame($password, $hash);
        $this->assertTrue($this->plaintext->verify($password, $hash));
        $this->assertFalse($this->plaintext->verify('wrongpassword', $hash));
    }

    public function testSpecialCharacters(): void
    {
        $password = '!@#$%^&*()_+-=[]{}|;:,.<>?';
        $hash = $this->plaintext->hash($password);

        $this->assertSame($password, $hash);
        $this->assertTrue($this->plaintext->verify($password, $hash));
    }

    public function testUnicodeCharacters(): void
    {
        $password = 'Hello 世界';
        $hash = $this->plaintext->hash($password);

        $this->assertSame($password, $hash);
        $this->assertTrue($this->plaintext->verify($password, $hash));
    }

    public function testEmptyString(): void
    {
        $password = '';
        $hash = $this->plaintext->hash($password);

        $this->assertSame($password, $hash);
        $this->assertTrue($this->plaintext->verify($password, $hash));
    }

    public function testGetName(): void
    {
        $this->assertSame('plaintext', $this->plaintext->getName());
    }
}
