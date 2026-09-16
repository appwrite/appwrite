<?php

declare(strict_types=1);

namespace Utopia\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

use function Swoole\Coroutine\run;

use Swoole\Coroutine\WaitGroup;
use Utopia\Cache\Adapter\Redis\IdleConnectionException;
use Utopia\Cache\Adapter\Redis\Multiplexing as RedisMultiplexing;
use Utopia\Cache\Adapter\Redis\TimeoutException;
use Utopia\Cache\Cache;
use Utopia\Tests\Services;

/**
 * A Swoole worker exits only once its reactor has no events left, and a
 * reader parked in recv() is one. These tests pin that once the last
 * outstanding reply is consumed nothing of the adapter is still running, and
 * that this costs no reconnects.
 */
final class MultiplexingReaderTest extends TestCase
{
    private ?RedisMultiplexing $adapter = null;

    private function runCo(callable $fn): void
    {
        $error = null;
        run(function () use ($fn, &$error): void {
            try {
                $fn();
            } catch (\Throwable $th) {
                $error = $th;
            } finally {
                // A permanent reader would otherwise keep the scheduler from
                // returning; closing here turns that into a red assertion.
                $this->adapter?->disconnect();
                $this->adapter = null;
            }
        });
        if ($error instanceof \Throwable) {
            throw $error;
        }
    }

    private function makeCache(float $readTimeout = 0.25, float $livenessTimeout = 5.0): Cache
    {
        $this->adapter = new RedisMultiplexing(
            Services::HOST,
            Services::REDIS_PORT,
            readTimeout: $readTimeout,
            livenessTimeout: $livenessTimeout,
        );

        return new Cache($this->adapter);
    }

    /**
     * Coroutines alive besides the caller, once the scheduler has had a turn:
     * a retired reader still has to return after waking the caller.
     */
    private function otherCoroutines(): int
    {
        Coroutine::sleep(0.01);

        return \count(Coroutine::listCoroutines()) - 1;
    }

    public function testReaderLivesOnlyWhileRepliesAreOutstanding(): void
    {
        $probe = new \Redis();
        $probe->connect(Services::HOST, Services::REDIS_PORT);
        $connected = fn(): int => (int) $probe->info('clients')['connected_clients'];

        try {
            $this->runCo(function () use ($connected): void {
                $cache = $this->makeCache();
                $this->assertSame(0, $this->otherCoroutines(), 'connecting must not start a reader');

                $cache->save('reader', 'value', 'reader');
                $clients = $connected();

                for ($i = 0; $i < 3; $i++) {
                    Coroutine::sleep(0.1);
                    $this->assertSame('value', $cache->load('reader', 60, 'reader'));
                    $this->assertSame(0, $this->otherCoroutines(), 'the reader must retire with the last reply');
                    $this->assertSame($clients, $connected(), 'retiring the reader must not close the connection');
                }
            });
        } finally {
            $probe->close();
        }
    }

    public function testConcurrentCallersShareOneReader(): void
    {
        $this->runCo(function (): void {
            $cache = $this->makeCache();
            $cache->save('shared', 'value', 'shared');

            $wg = new WaitGroup();
            $peak = 0;
            $results = [];
            for ($i = 0; $i < 20; $i++) {
                $wg->add();
                Coroutine::create(function () use ($cache, $wg, &$peak, &$results): void {
                    try {
                        $results[] = $cache->load('shared', 60, 'shared');
                        $peak = max($peak, $this->otherCoroutines());
                    } finally {
                        $wg->done();
                    }
                });
            }
            $wg->wait();

            $this->assertSame(array_fill(0, 20, 'value'), $results);
            $this->assertLessThanOrEqual(21, $peak, '20 callers plus one reader, never one reader per caller');
            $this->assertSame(0, $this->otherCoroutines());
        });
    }

    /**
     * Liveness is measured from the moment a reader starts waiting. Counting
     * the idle gap before it would tear down a healthy connection on its first
     * slow reply, failing every caller queued behind it.
     */
    public function testIdleGapIsNotMistakenForADeadConnection(): void
    {
        $this->runCo(function (): void {
            $cache = $this->makeCache(readTimeout: 0.1, livenessTimeout: 0.3);
            $cache->save('gap', 'value', 'gap');
            Coroutine::sleep(0.5);

            Services::compose('pause', 'redis');
            try {
                $cache->load('gap', 60, 'gap');
                $this->fail('Expected the read deadline to expire while the server was stalled.');
            } catch (IdleConnectionException $idle) {
                $this->fail('Idle time with nothing outstanding was counted against the connection: ' . $idle->getMessage());
            } catch (TimeoutException) {
                // The caller's own deadline, nothing more.
            } finally {
                Services::compose('unpause', 'redis');
            }

            $this->assertSame('value', $cache->load('gap', 60, 'gap'), 'the same connection must still serve');
        });
    }
}
