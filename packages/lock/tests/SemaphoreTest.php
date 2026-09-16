<?php

declare(strict_types=1);

namespace Utopia\Lock\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

use function Swoole\Coroutine\run;

use Swoole\Coroutine\System;
use Utopia\Lock\Semaphore;

final class SemaphoreTest extends TestCase
{
    public function testCapsAtPermits(): void
    {
        $semaphore = new Semaphore(3);
        $concurrent = 0;
        $max = 0;
        $count = 0;

        run(function () use ($semaphore, &$concurrent, &$max, &$count): void {
            for ($i = 0; $i < 10; $i++) {
                Coroutine::create(function () use ($semaphore, &$concurrent, &$max, &$count): void {
                    $semaphore->withLock(function () use (&$concurrent, &$max, &$count): void {
                        $concurrent++;
                        $max = max($max, $concurrent);
                        System::sleep(0.02);
                        $count++;
                        $concurrent--;
                    }, timeout: 5.0);
                });
            }
        });

        $this->assertSame(10, $count);
        $this->assertLessThanOrEqual(3, $max);
        $this->assertGreaterThan(1, $max, 'Expected some parallelism');
    }

    public function testRejectsInvalidPermits(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Semaphore(0);
    }

    public function testSinglePermitBehavesLikeMutex(): void
    {
        $semaphore = new Semaphore(1);
        $concurrent = 0;
        $max = 0;

        run(function () use ($semaphore, &$concurrent, &$max): void {
            for ($i = 0; $i < 4; $i++) {
                Coroutine::create(function () use ($semaphore, &$concurrent, &$max): void {
                    $semaphore->withLock(function () use (&$concurrent, &$max): void {
                        $concurrent++;
                        $max = max($max, $concurrent);
                        System::sleep(0.01);
                        $concurrent--;
                    }, timeout: 5.0);
                });
            }
        });

        $this->assertSame(1, $max);
    }
}
