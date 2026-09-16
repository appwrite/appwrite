<?php

declare(strict_types=1);

namespace Utopia\Lock\Tests;

use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

use function Swoole\Coroutine\run;

use Swoole\Coroutine\System;
use Utopia\Lock\Exception\Contention;
use Utopia\Lock\Mutex;

final class MutexTest extends TestCase
{
    public function testSerializesEightCoroutines(): void
    {
        $mutex = new Mutex();
        $concurrent = 0;
        $max = 0;
        $count = 0;

        run(function () use ($mutex, &$concurrent, &$max, &$count): void {
            for ($i = 0; $i < 8; $i++) {
                Coroutine::create(function () use ($mutex, &$concurrent, &$max, &$count): void {
                    $mutex->withLock(function () use (&$concurrent, &$max, &$count): void {
                        $concurrent++;
                        $max = max($max, $concurrent);
                        System::sleep(0.01);
                        $count++;
                        $concurrent--;
                    }, timeout: 5.0);
                });
            }
        });

        $this->assertSame(8, $count);
        $this->assertSame(1, $max, 'At most one coroutine should hold the mutex at a time');
    }

    public function testTimesOutUnderContention(): void
    {
        $mutex = new Mutex();
        $threw = false;

        run(function () use ($mutex, &$threw): void {
            Coroutine::create(function () use ($mutex): void {
                $mutex->acquire();
                System::sleep(0.5);
                $mutex->release();
            });

            Coroutine::create(function () use ($mutex, &$threw): void {
                System::sleep(0.01);
                try {
                    $mutex->withLock(fn(): null => null, timeout: 0.05);
                } catch (Contention) {
                    $threw = true;
                }
            });
        });

        $this->assertTrue($threw, 'Contention should have been thrown');
    }

    public function testTryAcquireFailsWhenHeld(): void
    {
        $mutex = new Mutex();

        run(function () use ($mutex): void {
            Coroutine::create(function () use ($mutex): void {
                $this->assertTrue($mutex->acquire());
                System::sleep(0.1);
                $mutex->release();
            });

            Coroutine::create(function () use ($mutex): void {
                System::sleep(0.01);
                $this->assertFalse($mutex->tryAcquire());
            });
        });
    }

    public function testReleaseIsIdempotent(): void
    {
        $mutex = new Mutex();
        run(function () use ($mutex): void {
            $mutex->acquire();
            $mutex->release();
            $mutex->release();
            $this->assertTrue($mutex->tryAcquire());
            $mutex->release();
        });
    }
}
