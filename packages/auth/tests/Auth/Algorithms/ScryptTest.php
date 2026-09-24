<?php

declare(strict_types=1);

namespace Utopia\Tests\Auth\Algorithms;

use PHPUnit\Framework\TestCase;
use Utopia\Auth\Hashes\Scrypt;

final class ScryptTest extends TestCase
{
    protected Scrypt $scrypt;

    protected function setUp(): void
    {
        $this->scrypt = new Scrypt();
    }

    public function testHash(): void
    {
        $password = 'test123';
        $hash = $this->scrypt->hash($password);

        $this->assertNotEmpty($hash);
        $this->assertTrue($this->scrypt->verify($password, $hash));
        $this->assertFalse($this->scrypt->verify('wrongpassword', $hash));
    }

    public function testDefaultSaltIsGenerated(): void
    {
        $salt = $this->scrypt->getOption('salt');

        $this->assertIsString($salt);
        $this->assertNotSame('', $salt);
        $this->assertNotSame($salt, (new Scrypt())->getOption('salt'));
    }

    public function testCustomOptions(): void
    {
        $this->scrypt->setCpuCost(16)
            ->setMemoryCost(15)
            ->setParallelCost(2)
            ->setLength(128)
            ->setSalt('custom-salt');

        $password = 'test123';
        $hash = $this->scrypt->hash($password);

        $this->assertTrue($this->scrypt->verify($password, $hash));
    }

    public function testGetName(): void
    {
        $this->assertSame('scrypt', $this->scrypt->getName());
    }
}
