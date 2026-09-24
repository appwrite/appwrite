<?php

declare(strict_types=1);

namespace Utopia\Tests\Auth\Algorithms;

use PHPUnit\Framework\TestCase;
use Utopia\Auth\Hashes\MD5;

final class MD5Test extends TestCase
{
    protected MD5 $md5;

    protected function setUp(): void
    {
        $this->md5 = new MD5();
    }

    public function testHash(): void
    {
        $password = 'test123';
        $hash = $this->md5->hash($password);

        $this->assertNotEmpty($hash);
        $this->assertSame(32, \strlen($hash));
        $this->assertSame(md5($password), $hash);
        $this->assertTrue($this->md5->verify($password, $hash));
        $this->assertFalse($this->md5->verify('wrongpassword', $hash));
    }

    public function testMultipleHashes(): void
    {
        $passwords = ['test123', 'password123', '!@#$%^&*()'];

        foreach ($passwords as $password) {
            $hash = $this->md5->hash($password);
            $this->assertSame(md5($password), $hash);
            $this->assertTrue($this->md5->verify($password, $hash));
        }
    }

    public function testEmptyString(): void
    {
        $password = '';
        $hash = $this->md5->hash($password);

        $this->assertSame(md5(''), $hash);
        $this->assertTrue($this->md5->verify($password, $hash));
    }

    public function testSpecialCharacters(): void
    {
        $password = '!@#$%^&*()_+-=[]{}|;:,.<>?';
        $hash = $this->md5->hash($password);

        $this->assertSame(md5($password), $hash);
        $this->assertTrue($this->md5->verify($password, $hash));
    }

    public function testUnicodeCharacters(): void
    {
        $password = 'Hello 世界';
        $hash = $this->md5->hash($password);

        $this->assertSame(md5($password), $hash);
        $this->assertTrue($this->md5->verify($password, $hash));
    }

    public function testGetName(): void
    {
        $this->assertSame('md5', $this->md5->getName());
    }
}
