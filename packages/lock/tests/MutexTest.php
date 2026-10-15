<?php

declare(strict_types=1);

namespace Utopia\Lock\Tests;

use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\System;
use Utopia\Lock\Exception\Contention;
use Utopia\Lock\Mutex;

use function Swoole\Coroutine\run;

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
                    $mutex->withLock(fn (): null => null, timeout: 0.05);
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


    public function testNegativeTimeoutWaitsForReleaseInCoroutine(): void
    {
        $mutex = new Mutex();
        $acquired = false;
        $waited = 0.0;

        run(function () use ($mutex, &$acquired, &$waited): void {
            Coroutine::create(function () use ($mutex): void {
                $mutex->acquire();
                System::sleep(0.3);
                $mutex->release();
            });

            System::sleep(0.05);

            $start = microtime(true);
            $acquired = $mutex->acquire(-1.0);
            $waited = microtime(true) - $start;
            $mutex->release();
        });

        $this->assertTrue($acquired, 'Negative timeout must wait until the holder releases');
        $this->assertGreaterThanOrEqual(0.2, $waited, 'Acquire must have blocked while the mutex was held');
    }

    public function testNegativeTimeoutAcquiresImmediatelyOutsideCoroutine(): void
    {
        $mutex = new Mutex();

        $start = microtime(true);
        $this->assertTrue($mutex->acquire(-1.0));
        $this->assertLessThan(0.05, microtime(true) - $start, 'Uncontended acquire must not wait');

        $mutex->release();
    }
}
