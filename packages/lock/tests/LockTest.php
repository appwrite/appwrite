<?php

declare(strict_types=1);

namespace Utopia\Lock\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Lock\Exception;
use Utopia\Lock\Exception\Contention;
use Utopia\Lock\File;
use Utopia\Lock\Lock;
use Utopia\Lock\Mutex;
use Utopia\Lock\Semaphore;

final class LockTest extends TestCase
{
    public function testAllImplementationsSatisfyInterface(): void
    {
        $this->assertInstanceOf(Lock::class, new Mutex());
        $this->assertInstanceOf(Lock::class, new Semaphore(2));
        $this->assertInstanceOf(Lock::class, new File(sys_get_temp_dir() . '/utopia-lock-iface.lock'));
    }

    public function testContentionExtendsBaseException(): void
    {
        $exception = new Contention('boom');
        $this->assertInstanceOf(Exception::class, $exception);
        $this->assertInstanceOf(\Exception::class, $exception);
    }

    public function testWithLockReturnsCallbackResult(): void
    {
        $mutex = new Mutex();
        $result = $mutex->withLock(fn(): string => 'ok');
        $this->assertSame('ok', $result);
    }

    public function testWithLockReleasesOnException(): void
    {
        $mutex = new Mutex();

        $this->expectException(\RuntimeException::class);

        try {
            $mutex->withLock(function (): never {
                throw new \RuntimeException('inner');
            });
        } finally {
            $this->assertTrue($mutex->tryAcquire(), 'Mutex should be released after exception');
        }
    }
}
