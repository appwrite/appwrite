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
 * What a caller's expired read deadline is allowed to do to the connection
 * everyone else is sharing.
 *
 * The stall is produced with `docker pause`, which freezes the server's process
 * while leaving the socket established: no reply, no FIN, no RST. That is the
 * shape of a slow server rather than a broken one, and telling those two apart
 * is the whole point of these tests — a stall must cost the callers waiting on
 * it and nothing more.
 */
final class MultiplexingDeadlineTest extends TestCase
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
                if ($this->adapter instanceof RedisMultiplexing) {
                    $this->adapter->disconnect();
                    $this->adapter = null;
                }
            }
        });
        if ($error instanceof \Throwable) {
            throw $error;
        }
    }

    private function makeAdapter(float $readTimeout, float $livenessTimeout): RedisMultiplexing
    {
        return $this->adapter = new RedisMultiplexing(
            Services::HOST,
            Services::REDIS_PORT,
            readTimeout: $readTimeout,
            livenessTimeout: $livenessTimeout,
        );
    }

    private bool $paused = false;

    private ?\Redis $probe = null;

    /**
     * Freezes the server without disturbing the socket. Commands sent while
     * paused sit in the kernel buffer and are answered once it resumes, so an
     * abandoned reply really does arrive late rather than never.
     */
    private function pauseServer(): void
    {
        Services::compose('pause', 'redis');
        $this->paused = true;
    }

    private function resumeServer(): void
    {
        if (! $this->paused) {
            return;
        }

        Services::compose('unpause', 'redis');
        $this->paused = false;
    }

    protected function tearDown(): void
    {
        // A test that fails mid-stall must not leave the container frozen for
        // everything that runs after it.
        $this->resumeServer();

        if ($this->probe instanceof \Redis) {
            $this->probe->close();
            $this->probe = null;
        }

        parent::tearDown();
    }

    public function testDeadlineThrowsTimeoutRatherThanConnectionFailure(): void
    {
        $this->runCo(function (): void {
            $cache = new Cache($this->makeAdapter(readTimeout: 0.05, livenessTimeout: 5.0));
            $cache->save('deadline', 'value', 'deadline');

            $this->pauseServer();

            try {
                $cache->load('deadline', 60, 'deadline');
                $this->fail('Expected the read deadline to expire while the server was stalled.');
            } catch (TimeoutException $timeout) {
                $this->assertStringContainsString('Timed out waiting for Redis response', $timeout->getMessage());
            } finally {
                $this->resumeServer();
            }
        });
    }

    /**
     * The regression this suite exists for.
     *
     * A caller that gives up leaves its slot on the pending queue, because the
     * queue's order is what pairs replies with callers. If the slot were dropped,
     * the abandoned reply would be handed to whoever asked next — so this test
     * reads a *different* key afterwards and insists on that key's own value.
     * Getting `first` back here would mean every reply after a timeout is
     * misrouted to the wrong caller.
     */
    public function testAbandonedReplyIsNotHandedToTheNextCaller(): void
    {
        $this->runCo(function (): void {
            $cache = new Cache($this->makeAdapter(readTimeout: 0.05, livenessTimeout: 5.0));
            $cache->save('first', 'first-value', 'first');
            $cache->save('second', 'second-value', 'second');

            $this->pauseServer();

            try {
                $cache->load('first', 60, 'first');
                $this->fail('Expected the read deadline to expire while the server was stalled.');
            } catch (TimeoutException) {
                // Expected: this caller abandoned its slot.
            } finally {
                $this->resumeServer();
            }

            // Let the abandoned reply arrive before asking anything else.
            Coroutine::sleep(0.3);

            $this->assertSame('second-value', $cache->load('second', 60, 'second'));
            $this->assertSame('first-value', $cache->load('first', 60, 'first'));
        });
    }

    /**
     * A stall costs the callers waiting on it, and the connection is immediately
     * usable again once the server answers — no teardown, no reconnect.
     */
    public function testConnectionSurvivesAStallAndKeepsServingAfterwards(): void
    {
        $this->runCo(function (): void {
            $cache = new Cache($this->makeAdapter(readTimeout: 0.05, livenessTimeout: 5.0));
            $cache->save('survives', 'survives-value', 'survives');

            $this->pauseServer();

            $timeouts = 0;
            $group = new WaitGroup();
            for ($i = 0; $i < 8; $i++) {
                $group->add();
                Coroutine::create(function () use ($cache, &$timeouts, $group): void {
                    try {
                        $cache->load('survives', 60, 'survives');
                    } catch (TimeoutException) {
                        $timeouts++;
                    } finally {
                        $group->done();
                    }
                });
            }
            $group->wait();

            $this->resumeServer();

            $this->assertSame(8, $timeouts, 'Expected every caller waiting on the stalled server to expire.');

            Coroutine::sleep(0.3);

            $this->assertSame('survives-value', $cache->load('survives', 60, 'survives'));
            $this->assertTrue($cache->ping());
        });
    }

    /**
     * The churn signature from the incident this change came out of.
     *
     * Redis counts every connection it accepts, so a teardown is visible from
     * outside the process. A stall must not produce any: the whole cost of one
     * slow command should be the callers that waited on it, not a rebuilt socket
     * per caller. Before this change each expired deadline closed the shared
     * connection, so a paused server produced a reconnect per caller — which is
     * what this asserts against.
     */
    public function testAStallCausesNoReconnectChurn(): void
    {
        $this->runCo(function (): void {
            $cache = new Cache($this->makeAdapter(readTimeout: 0.05, livenessTimeout: 5.0));
            $cache->save('churn', 'churn-value', 'churn');

            $before = $this->connectionsReceived();

            $this->pauseServer();

            $group = new WaitGroup();
            for ($i = 0; $i < 8; $i++) {
                $group->add();
                Coroutine::create(function () use ($cache, $group): void {
                    try {
                        $cache->load('churn', 60, 'churn');
                    } catch (TimeoutException) {
                        // Expected while the server is frozen.
                    } finally {
                        $group->done();
                    }
                });
            }
            $group->wait();

            $this->resumeServer();
            Coroutine::sleep(0.3);

            $this->assertSame(
                $before,
                $this->connectionsReceived(),
                'A stalled server must not cost the connection every caller is sharing.',
            );

            $this->assertSame('churn-value', $cache->load('churn', 60, 'churn'));
        });
    }

    /**
     * How many connections the server has accepted in its lifetime.
     *
     * Read over a probe connection opened once and reused, so the measurement
     * does not move the number it is measuring.
     */
    private function connectionsReceived(): int
    {
        if (! $this->probe instanceof \Redis) {
            $this->probe = new \Redis();
            $this->probe->connect(Services::HOST, Services::REDIS_PORT);
        }

        $stats = $this->probe->info('stats');

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_connections_received', $stats);

        return (int) $stats['total_connections_received'];
    }

    /**
     * The verdict the per-call deadline used to make. A connection that has gone
     * quiet for longer than a busy server explains is declared dead, every caller
     * on it is failed, and the next caller rebuilds it.
     */
    public function testSilenceBeyondTheLivenessTimeoutTearsTheConnectionDown(): void
    {
        $this->runCo(function (): void {
            $cache = new Cache($this->makeAdapter(readTimeout: 0.05, livenessTimeout: 0.3));
            $cache->save('liveness', 'liveness-value', 'liveness');

            $this->pauseServer();

            $sawTeardown = false;
            $deadline = microtime(true) + 3.0;
            while (microtime(true) < $deadline) {
                try {
                    $cache->load('liveness', 60, 'liveness');
                } catch (TimeoutException) {
                    continue; // Still inside the liveness budget.
                } catch (IdleConnectionException $torn) {
                    $sawTeardown = true;
                    $this->assertStringContainsString('idle for', $torn->getMessage());
                    break;
                }
            }

            $this->resumeServer();

            $this->assertTrue($sawTeardown, 'Expected a silent connection to be declared dead.');

            Coroutine::sleep(0.3);

            $this->assertSame('liveness-value', $cache->load('liveness', 60, 'liveness'));
        });
    }

    public function testLivenessTimeoutBelowReadTimeoutIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('livenessTimeout must be greater than or equal to readTimeout');

        $this->runCo(function (): void {
            $this->makeAdapter(readTimeout: 1.0, livenessTimeout: 0.5);
        });
    }
}
