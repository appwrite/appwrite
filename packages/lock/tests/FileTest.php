<?php

declare(strict_types=1);

namespace Utopia\Lock\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Lock\Exception\Contention;
use Utopia\Lock\File;

final class FileTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'utopia-lock-');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->path)) {
            @unlink($this->path);
        }
    }

    public function testAcquireAndRelease(): void
    {
        $lock = new File($this->path);
        $this->assertTrue($lock->tryAcquire());
        $lock->release();

        $other = new File($this->path);
        $this->assertTrue($other->tryAcquire());
        $other->release();
    }

    public function testTryAcquireFailsWhenHeldByAnotherProcess(): void
    {
        if (! \function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork required');
        }

        $ready = tempnam(sys_get_temp_dir(), 'utopia-lock-ready-');
        $done = tempnam(sys_get_temp_dir(), 'utopia-lock-done-');

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'Failed to fork');

        if ($pid === 0) {
            $child = new File($this->path);
            $acquired = $child->tryAcquire();
            file_put_contents($ready, $acquired ? '1' : '0');

            while (! file_exists($done) || file_get_contents($done) !== '1') {
                usleep(10_000);
            }

            $child->release();
            exit(0);
        }

        try {
            $deadline = microtime(true) + 5.0;
            while (microtime(true) < $deadline && file_get_contents($ready) === '') {
                usleep(10_000);
            }

            $this->assertSame('1', file_get_contents($ready), 'Child failed to acquire');

            $parent = new File($this->path);
            $this->assertFalse($parent->tryAcquire(), 'Parent must not acquire while child holds');

            $start = microtime(true);
            $this->assertFalse($parent->acquire(0.1));
            $elapsed = microtime(true) - $start;
            $this->assertGreaterThanOrEqual(0.05, $elapsed);
        } finally {
            file_put_contents($done, '1');
            pcntl_waitpid($pid, $status);
            @unlink($ready);
            @unlink($done);
        }
    }

    public function testWithLockReleasesOnException(): void
    {
        $lock = new File($this->path);

        $this->expectException(\RuntimeException::class);

        try {
            $lock->withLock(function (): never {
                throw new \RuntimeException('inner');
            });
        } finally {
            $other = new File($this->path);
            $this->assertTrue($other->tryAcquire());
            $other->release();
        }
    }

    public function testContentionThrownOnTimeout(): void
    {
        if (! \function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork required');
        }

        $ready = tempnam(sys_get_temp_dir(), 'utopia-lock-ready-');
        $done = tempnam(sys_get_temp_dir(), 'utopia-lock-done-');

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);

        if ($pid === 0) {
            $child = new File($this->path);
            $child->tryAcquire();
            file_put_contents($ready, '1');
            while (file_get_contents($done) !== '1') {
                usleep(10_000);
            }
            $child->release();
            exit(0);
        }

        try {
            while (file_get_contents($ready) !== '1') {
                usleep(10_000);
            }

            $parent = new File($this->path);
            $this->expectException(Contention::class);
            try {
                $parent->withLock(fn(): null => null, timeout: 0.1);
            } finally {
                file_put_contents($done, '1');
            }
        } finally {
            pcntl_waitpid($pid, $status);
            @unlink($ready);
            @unlink($done);
        }
    }
}
