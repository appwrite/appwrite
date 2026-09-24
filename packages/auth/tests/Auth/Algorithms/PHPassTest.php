<?php

declare(strict_types=1);

namespace Utopia\Tests\Auth\Algorithms;

use PHPUnit\Framework\TestCase;
use Utopia\Auth\Hashes\PHPass;

final class PHPassTest extends TestCase
{
    protected PHPass $phpass;

    protected function setUp(): void
    {
        $this->phpass = new PHPass();
    }

    public function testHash(): void
    {
        $password = 'test123';
        $hash = $this->phpass->hash($password);

        $this->assertNotEmpty($hash);
        $this->assertTrue($this->phpass->verify($password, $hash));
        $this->assertFalse($this->phpass->verify('wrongpassword', $hash));
    }

    public function testIterationCount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->phpass->setIterationCount(3); // Should throw exception for too low iteration count
    }

    public function testValidIterationCount(): void
    {
        $this->phpass->setIterationCount(8);

        // Test that the new iteration count is being used by verifying a hash
        $password = 'test123';
        $hash = $this->phpass->hash($password);
        $this->assertTrue($this->phpass->verify($password, $hash));
    }

    public function testPortableHashes(): void
    {
        // Test with portable hashes enabled
        $this->phpass->setPortableHashes(true);
        $password = 'test123';
        $hash = $this->phpass->hash($password);
        $this->assertTrue($this->phpass->verify($password, $hash));

        // Test with portable hashes disabled
        $this->phpass->setPortableHashes(false);
        $hash = $this->phpass->hash($password);
        $this->assertTrue($this->phpass->verify($password, $hash));
    }

    public function testSpecialCharacters(): void
    {
        $password = '!@#$%^&*()_+-=[]{}|;:,.<>?';
        $hash = $this->phpass->hash($password);
        $this->assertTrue($this->phpass->verify($password, $hash));
    }

    public function testUnicodeCharacters(): void
    {
        $password = 'Hello 世界';
        $hash = $this->phpass->hash($password);
        $this->assertTrue($this->phpass->verify($password, $hash));
    }

    public function testEmptyString(): void
    {
        $password = '';
        $hash = $this->phpass->hash($password);
        $this->assertTrue($this->phpass->verify($password, $hash));
    }

    public function testLongPassword(): void
    {
        $password = str_repeat('a', 1000);
        $hash = $this->phpass->hash($password);
        $this->assertTrue($this->phpass->verify($password, $hash));
    }

    public function testGetName(): void
    {
        $this->assertSame('phpass', $this->phpass->getName());
    }
}
