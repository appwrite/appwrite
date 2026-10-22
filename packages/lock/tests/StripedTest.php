<?php

declare(strict_types=1);

namespace Utopia\Lock\Tests;

use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\System;
use Utopia\Lock\Striped;

use function Swoole\Coroutine\run;

final class StripedTest extends TestCase
{
    public function testSerialisesSameKey(): void
    {
        $striped = new Striped();
        $concurrent = 0;
        $max = 0;
        $count = 0;

        run(function () use ($striped, &$concurrent, &$max, &$count): void {
            for ($i = 0; $i < 8; $i++) {
                Coroutine::create(function () use ($striped, &$concurrent, &$max, &$count): void {
                    $striped->withLock('same-key', function () use (&$concurrent, &$max, &$count): void {
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
        $this->assertSame(1, $max, 'The same key must serialise to one holder at a time');
    }

    public function testDistinctStripesRunConcurrently(): void
    {
        // Four stripes, keys 0..3 land on four distinct stripes.
        $striped = new Striped(4);
        $concurrent = 0;
        $max = 0;

        run(function () use ($striped, &$concurrent, &$max): void {
            for ($key = 0; $key < 4; $key++) {
                Coroutine::create(function () use ($striped, $key, &$concurrent, &$max): void {
                    $striped->withLock($key, function () use (&$concurrent, &$max): void {
                        $concurrent++;
                        $max = max($max, $concurrent);
                        System::sleep(0.02);
                        $concurrent--;
                    }, timeout: 5.0);
                });
            }
        });

        $this->assertGreaterThan(1, $max, 'Distinct stripes should allow concurrent holders');
    }

    public function testCollidingKeysShareAStripe(): void
    {
        // One stripe forces every key onto the same lock.
        $striped = new Striped(1);
        $concurrent = 0;
        $max = 0;

        run(function () use ($striped, &$concurrent, &$max): void {
            foreach (['a', 'b', 'c'] as $key) {
                Coroutine::create(function () use ($striped, $key, &$concurrent, &$max): void {
                    $striped->withLock($key, function () use (&$concurrent, &$max): void {
                        $concurrent++;
                        $max = max($max, $concurrent);
                        System::sleep(0.01);
                        $concurrent--;
                    }, timeout: 5.0);
                });
            }
        });

        $this->assertSame(1, $max, 'Keys on one stripe must serialise');
    }
}
