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
 * reader parked in recv() is one. These tests pin both sides of the reader's
 * life: it outlives the last reply by the idle grace, so steady traffic does
 * not pay for a coroutine per command, and once the grace has passed nothing
 * of the adapter is still running. Neither costs a reconnect.
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

    private function makeCache(float $readTimeout = 0.25, float $livenessTimeout = 5.0, float $idleGrace = 0.2): Cache
    {
        $this->adapter = new RedisMultiplexing(
            Services::HOST,
            Services::REDIS_PORT,
            readTimeout: $readTimeout,
            livenessTimeout: $livenessTimeout,
            idleGrace: $idleGrace,
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

    public function testReaderRetiresOnceTheConnectionHasBeenIdle(): void
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
                    $this->assertSame('value', $cache->load('reader', 60, 'reader'));
                    $this->assertSame(1, $this->otherCoroutines(), 'the reader must outlive the last reply by the grace');
                    Coroutine::sleep(0.3);
                    $this->assertSame(0, $this->otherCoroutines(), 'the reader must retire once the grace has passed');
                    $this->assertSame($clients, $connected(), 'retiring the reader must not close the connection');
                }
            });
        } finally {
            $probe->close();
        }
    }

    /**
     * The regression this guards: a reader that retired with the last reply
     * was spawned again by the very next command, and at production
     * concurrency that was a coroutine per cache load, ten to fifteen per
     * request, worth more CPU than the loads themselves.
     */
    public function testCommandsWithinTheGraceShareOneReader(): void
    {
        $this->runCo(function (): void {
            $cache = $this->makeCache(idleGrace: 0.5);
            $cache->save('steady', 'value', 'steady');

            $spawned = Coroutine::stats()['coroutine_last_cid'];
            for ($i = 0; $i < 50; $i++) {
                $this->assertSame('value', $cache->load('steady', 60, 'steady'));
                Coroutine::sleep(0.005);
            }

            $this->assertSame($spawned, Coroutine::stats()['coroutine_last_cid'], 'steady traffic must not spawn a reader per command');
            $this->assertSame(1, $this->otherCoroutines());
        });
    }

    /**
     * The retirement decision and a new command race for the same instant.
     * A command that lands while the reader is deciding must either be read
     * by that reader or spawn its own; a reply with nobody to read it would
     * strand the caller until its deadline.
     */
    public function testCommandLandingAsTheReaderRetiresIsStillAnswered(): void
    {
        $this->runCo(function (): void {
            $cache = $this->makeCache(idleGrace: 0.05);
            $cache->save('race', 'value', 'race');

            for ($i = 0; $i < 40; $i++) {
                // Sweep the gap across the grace boundary a millisecond at a time.
                Coroutine::sleep(0.04 + $i * 0.0005);
                $this->assertSame('value', $cache->load('race', 60, 'race'), "command {$i} must be answered");
            }
        });
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
            $this->assertSame(1, $this->otherCoroutines());
            Coroutine::sleep(0.3);
            $this->assertSame(0, $this->otherCoroutines());
        });
    }

    /**
     * Liveness is measured from the moment a caller starts waiting. Counting
     * the idle gap before it would tear down a healthy connection on its first
     * slow reply, failing every caller queued behind it. The gap here is
     * shorter than the grace, so the parked reader is the one that serves.
     */
    public function testIdleGapIsNotMistakenForADeadConnection(): void
    {
        $this->runCo(function (): void {
            $cache = $this->makeCache(readTimeout: 0.1, livenessTimeout: 0.3, idleGrace: 1.0);
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
