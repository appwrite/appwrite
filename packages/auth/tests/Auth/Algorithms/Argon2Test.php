<?php

declare(strict_types=1);

namespace Utopia\Tests\Auth\Algorithms;

use PHPUnit\Framework\TestCase;
use Utopia\Auth\Hashes\Argon2;

final class Argon2Test extends TestCase
{
    protected Argon2 $argon2;

    protected function setUp(): void
    {
        $this->argon2 = new Argon2();
    }

    public function testHash(): void
    {
        $password = 'test123';
        $hash = $this->argon2->hash($password);
        $this->assertNotEmpty($hash);
        $this->assertStringStartsWith('$argon2id$', $hash);
        $this->assertStringContainsString('m=' . $this->argon2->getOption('memory_cost'), $hash);
        $this->assertStringContainsString('t=' . $this->argon2->getOption('time_cost'), $hash);
        $this->assertStringContainsString('p=' . $this->argon2->getOption('threads'), $hash);
        $this->assertTrue($this->argon2->verify($password, $hash));
        $this->assertFalse($this->argon2->verify('wrongpassword', $hash));
    }

    public function testValidMemoryCost(): void
    {
        $cost = PASSWORD_ARGON2_DEFAULT_MEMORY_COST + 1024;
        $this->argon2->setMemoryCost($cost);

        // Test that the new memory cost is being used by verifying a hash
        $password = 'test123';
        $hash = $this->argon2->hash($password);
        $this->assertStringContainsString('m=' . $cost, $hash);
        $this->assertTrue($this->argon2->verify($password, $hash));
    }

    public function testValidTimeCost(): void
    {
        $cost = PASSWORD_ARGON2_DEFAULT_TIME_COST + 1;
        $this->argon2->setTimeCost($cost);

        // Test that the new time cost is being used by verifying a hash
        $password = 'test123';
        $hash = $this->argon2->hash($password);
        $this->assertStringContainsString('t=' . $cost, $hash);
        $this->assertTrue($this->argon2->verify($password, $hash));
    }

    public function testValidThreads(): void
    {
        $threads = PASSWORD_ARGON2_DEFAULT_THREADS + 1;
        $this->argon2->setThreads($threads);

        // Test that the new thread count is being used by verifying a hash
        $password = 'test123';
        $hash = $this->argon2->hash($password);
        $this->assertStringContainsString('p=' . $threads, $hash);
        $this->assertTrue($this->argon2->verify($password, $hash));
    }

    public function testGetName(): void
    {
        $this->assertSame('argon2', $this->argon2->getName());
    }
}
