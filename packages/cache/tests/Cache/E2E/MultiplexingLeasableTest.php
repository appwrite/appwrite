<?php

declare(strict_types=1);

namespace Utopia\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Redis as Redis;

use function Swoole\Coroutine\run;

use Utopia\Cache\Adapter\Redis\Multiplexing as RedisMultiplexing;
use Utopia\Cache\Cache;
use Utopia\Tests\Services;

final class MultiplexingLeasableTest extends TestCase
{
    private RedisMultiplexing $adapter;

    /**
     * Run the entire test body inside a Swoole coroutine context, surfacing any
     * exception as a real test failure and disconnecting the multiplexed
     * connection afterwards.
     */
    private function runCo(callable $fn): void
    {
        $error = null;
        run(function () use ($fn, &$error): void {
            try {
                $fn();
            } catch (\Throwable $th) {
                $error = $th;
            } finally {
                $this->close();
            }
        });
        if ($error instanceof \Throwable) {
            throw $error;
        }
    }

    private function makeCache(): Cache
    {
        $this->adapter = new RedisMultiplexing(Services::HOST, Services::REDIS_PORT);

        return new Cache($this->adapter);
    }

    private function graceCache(int $milliseconds): Cache
    {
        $this->adapter = new RedisMultiplexing(Services::HOST, Services::REDIS_PORT);
        $this->adapter->setLeaseGraceWindow($milliseconds);

        return new Cache($this->adapter);
    }

    private function close(): void
    {
        if (isset($this->adapter)) {
            $this->adapter->disconnect();
        }
    }

    public function testFreshKeyHasZeroGeneration(): void
    {
        $this->runCo(function (): void {
            $cache = $this->makeCache();
            $cache->flush();

            $this->assertSame('0', $cache->getGeneration('doc:1'));
        });
    }

    public function testSaveWithLeaseStoresWhenGenerationUnchanged(): void
    {
        $this->runCo(function (): void {
            $cache = $this->makeCache();
            $cache->flush();

            $generation = $cache->getGeneration('doc:1');

            $this->assertNotFalse($cache->saveWithLease('doc:1', ['v' => 1], 'doc:1', $generation));
            $this->assertSame(['v' => 1], $cache->load('doc:1', 60, 'doc:1'));
        });
    }

    public function testPurgeAdvancesGeneration(): void
    {
        $this->runCo(function (): void {
            $cache = $this->makeCache();
            $cache->flush();

            $before = $cache->getGeneration('doc:1');
            $cache->saveWithLease('doc:1', ['v' => 1], 'doc:1', $before);
            $cache->purge('doc:1');

            $this->assertNotSame($before, $cache->getGeneration('doc:1'));
        });
    }

    public function testHashPurgeAdvancesGeneration(): void
    {
        $this->runCo(function (): void {
            $cache = $this->makeCache();
            $cache->flush();

            $captured = $cache->getGeneration('doc:1');
            $this->assertNotFalse($cache->saveWithLease('doc:1', ['v' => 1], 'field-a', $captured));

            // A field-level purge must advance the per-key generation, just like a
            // full purge, or the lease is bypassed for hashed entries.
            $this->assertTrue($cache->purge('doc:1', 'field-a'));
            $this->assertNotSame($captured, $cache->getGeneration('doc:1'));

            // A reader holding the pre-purge generation can no longer re-cache a
            // stale field via saveWithLease.
            $this->assertFalse($cache->saveWithLease('doc:1', ['stale' => true], 'field-a', $captured));
            $this->assertFalse($cache->load('doc:1', 60, 'field-a'));
        });
    }

    /**
     * The core read-after-write guarantee: a reader that captured the generation
     * BEFORE a concurrent purge must NOT be able to re-cache its now-stale value
     * AFTER the purge. Ordering here simulates the race deterministically.
     */
    public function testStaleSaveAfterPurgeIsRejected(): void
    {
        $this->runCo(function (): void {
            $cache = $this->makeCache();
            $cache->flush();

            $captured = $cache->getGeneration('doc:1');

            // A writer commits and purges; the generation advances.
            $cache->purge('doc:1');

            // The reader tries to re-cache the value it read before the purge.
            $result = $cache->saveWithLease('doc:1', ['stale' => true], 'doc:1', $captured);

            $this->assertFalse($result, 'Stale re-cache must be rejected after a purge advanced the generation');
            $this->assertFalse($cache->load('doc:1', 60, 'doc:1'), 'Cache must not hold the stale value');
        });
    }

    public function testFreshSaveAfterPurgeSucceeds(): void
    {
        $this->runCo(function (): void {
            $cache = $this->makeCache();
            $cache->flush();

            $cache->purge('doc:1');

            $generation = $cache->getGeneration('doc:1');
            $this->assertNotFalse($cache->saveWithLease('doc:1', ['v' => 2], 'doc:1', $generation));
            $this->assertSame(['v' => 2], $cache->load('doc:1', 60, 'doc:1'));
        });
    }

    /**
     * The hot path sends EVALSHA (script digest) rather than the full body. After
     * the server drops its script cache — a failover, restart, or SCRIPT FLUSH —
     * that EVALSHA replies NOSCRIPT; leaseRun() must resend the body via EVAL so
     * the lease and purge operations keep working instead of silently failing.
     */
    public function testLeaseSurvivesScriptCacheFlush(): void
    {
        $this->runCo(function (): void {
            $cache = $this->makeCache();
            $cache->flush();

            $generation = $cache->getGeneration('doc:1');
            $this->assertNotFalse($cache->saveWithLease('doc:1', ['v' => 1], 'doc:1', $generation));

            // Evict every cached script server-side; the next EVALSHA now misses.
            $raw = new Redis();
            $raw->connect(Services::HOST, Services::REDIS_PORT);
            $raw->script('flush');
            $raw->close();

            $this->assertTrue($cache->purge('doc:1'), 'purge must fall back to EVAL after NOSCRIPT');
            $this->assertNotSame($generation, $cache->getGeneration('doc:1'));

            $fresh = $cache->getGeneration('doc:1');
            $this->assertNotFalse(
                $cache->saveWithLease('doc:1', ['v' => 2], 'doc:1', $fresh),
                'saveWithLease must fall back to EVAL after NOSCRIPT',
            );
            $this->assertSame(['v' => 2], $cache->load('doc:1', 60, 'doc:1'));
        });
    }

    public function testPurgePreservesDeletionResult(): void
    {
        $this->runCo(function (): void {
            $cache = $this->makeCache();
            $cache->flush();

            $this->assertFalse($cache->purge('doc:absent'), 'Purging a missing key returns false');

            $cache->saveWithLease('doc:1', ['v' => 1], 'doc:1', $cache->getGeneration('doc:1'));
            $this->assertTrue($cache->purge('doc:1'), 'Purging an existing key returns true');
        });
    }

    public function testPurgeDropsValueButKeepsGenerationField(): void
    {
        $this->runCo(function (): void {
            $cache = $this->makeCache();
            $cache->flush();

            $cache->saveWithLease('doc:1', ['v' => 1], 'doc:1', $cache->getGeneration('doc:1'));
            $this->assertSame(['v' => 1], $cache->load('doc:1', 60, 'doc:1'));

            $cache->purge('doc:1');

            // The value is gone, but the key keeps its in-hash generation so a stale
            // reader is still rejected. The residual hash therefore persists and is
            // counted by getSize() — the documented trade-off of storing the
            // generation in-hash rather than in a separately-countable sidecar key.
            $this->assertFalse($cache->load('doc:1', 60, 'doc:1'));
            $this->assertSame('1', $cache->getGeneration('doc:1'));
            $this->assertSame(1, $cache->getSize());
        });
    }

    public function testReservedGenerationFieldIsProtected(): void
    {
        $this->runCo(function (): void {
            $cache = $this->makeCache();
            $cache->flush();

            $cache->saveWithLease('doc:1', ['v' => 1], 'doc:1', $cache->getGeneration('doc:1'));
            $cache->purge('doc:1');
            $this->assertSame('1', $cache->getGeneration('doc:1'));

            // Reading, writing, or deleting the reserved generation field through the
            // public API must be rejected, so a caller can't clobber it and reset the
            // generation sequence (which would revive stale lease tokens).
            $this->assertFalse($cache->save('doc:1', 'attacker', '__utopia_gen__'));
            $this->assertFalse($cache->saveWithLease('doc:1', 'attacker', '__utopia_gen__', '1'));
            $this->assertFalse($cache->purge('doc:1', '__utopia_gen__'));
            $this->assertFalse($cache->load('doc:1', 60, '__utopia_gen__'));

            // Generation is untouched, so an old token is still rejected.
            $this->assertSame('1', $cache->getGeneration('doc:1'));
            $this->assertFalse($cache->saveWithLease('doc:1', 'stale', 'doc:1', '0'));
        });
    }

    public function testListHidesGenerationField(): void
    {
        $this->runCo(function (): void {
            $cache = $this->makeCache();
            $cache->flush();

            $cache->saveWithLease('doc:1', ['v' => 1], 'field-a', $cache->getGeneration('doc:1'));
            $this->assertSame(['field-a'], $cache->list('doc:1'));

            // After a purge the key holds only its internal generation field, which
            // must not surface as a listable cache field.
            $cache->purge('doc:1');
            $this->assertSame([], $cache->list('doc:1'));
        });
    }

    public function testLeaseGraceWindowDefaultsToZero(): void
    {
        $this->runCo(function (): void {
            $this->adapter = new RedisMultiplexing(Services::HOST, Services::REDIS_PORT);
            $this->assertSame(0, $this->adapter->getLeaseGraceWindow());
        });
    }

    /**
     * A token-valid save (generation captured post-purge) must still be refused
     * within the grace window, then allowed once the deadline passes.
     */
    public function testTombstoneRejectsTokenValidSaveWithinGraceWindow(): void
    {
        $this->runCo(function (): void {
            $cache = $this->graceCache(500);
            $cache->flush();

            $cache->purge('doc:1');

            $generation = $cache->getGeneration('doc:1');
            $this->assertFalse(
                $cache->saveWithLease('doc:1', ['stale' => true], 'doc:1', $generation),
                'A token-valid save inside the grace window must be refused by the tombstone',
            );
            $this->assertFalse($cache->load('doc:1', 60, 'doc:1'), 'Cache must not hold the stale value');

            // Expire the tombstone directly (no sleep) to resume saves. The
            // multiplexing adapter exposes no raw write, so use a temporary
            // synchronous php-redis client for this out-of-band poke.
            $raw = new Redis();
            $raw->connect(Services::HOST, Services::REDIS_PORT);
            // Derive from the Redis server clock (what the tombstone Lua uses via
            // TIME), not the PHP wall clock, so a client/server skew can't flake it.
            $past = ((int) $raw->time()[0] - 60) * 1000000;
            $raw->hSet('doc:1', '__utopia_tomb__', (string) $past);
            $raw->close();

            $this->assertNotFalse(
                $cache->saveWithLease('doc:1', ['fresh' => true], 'doc:1', $generation),
                'After the grace window a token-valid save must succeed',
            );
            $this->assertSame(['fresh' => true], $cache->load('doc:1', 60, 'doc:1'));

            $raw = new Redis();
            $raw->connect(Services::HOST, Services::REDIS_PORT);
            $lingering = (bool) $raw->hExists('doc:1', '__utopia_tomb__');
            $raw->close();
            $this->assertFalse(
                $lingering,
                'The spent tombstone must be dropped on the next save, not linger in the hash',
            );
        });
    }

    /** A field-level purge must open the tombstone too. */
    public function testFieldPurgeAlsoOpensTombstone(): void
    {
        $this->runCo(function (): void {
            $cache = $this->graceCache(500);
            $cache->flush();

            $cache->saveWithLease('doc:1', ['v' => 1], 'field-a', $cache->getGeneration('doc:1'));
            $cache->purge('doc:1', 'field-a');

            $generation = $cache->getGeneration('doc:1');
            $this->assertFalse(
                $cache->saveWithLease('doc:1', ['stale' => true], 'field-a', $generation),
                'A token-valid field save inside the grace window must be refused',
            );
            $this->assertFalse($cache->load('doc:1', 60, 'field-a'));
        });
    }

    public function testListHidesTombstoneField(): void
    {
        $this->runCo(function (): void {
            $cache = $this->graceCache(500);
            $cache->flush();

            $cache->saveWithLease('doc:1', ['v' => 1], 'field-a', $cache->getGeneration('doc:1'));
            $cache->purge('doc:1');

            $this->assertSame([], $cache->list('doc:1'));
        });
    }

    public function testReservedTombstoneFieldIsProtected(): void
    {
        $this->runCo(function (): void {
            $cache = $this->graceCache(500);
            $cache->flush();

            $this->assertFalse($cache->save('doc:1', 'attacker', '__utopia_tomb__'));
            $this->assertFalse($cache->saveWithLease('doc:1', 'attacker', '__utopia_tomb__', '0'));
            $this->assertFalse($cache->purge('doc:1', '__utopia_tomb__'));
            $this->assertFalse($cache->load('doc:1', 60, '__utopia_tomb__'));
        });
    }

    /**
     * A deadline more than the window ahead of now (a since-rewound clock) must
     * be ignored, not wedge saves.
     */
    public function testTombstoneIgnoresImplausibleFutureDeadline(): void
    {
        $this->runCo(function (): void {
            $cache = $this->graceCache(500);
            $cache->flush();

            $cache->purge('doc:1');
            $generation = $cache->getGeneration('doc:1');

            // 1h-ahead deadline (µs), far beyond the window: a since-rewound clock.
            // The multiplexing adapter exposes no raw write, so stamp it via a
            // temporary synchronous php-redis client.
            $raw = new Redis();
            $raw->connect(Services::HOST, Services::REDIS_PORT);
            $farFutureMicros = ((int) $raw->time()[0] + 3600) * 1000000;
            $raw->hSet('doc:1', '__utopia_tomb__', (string) $farFutureMicros);
            $raw->close();

            $this->assertNotFalse(
                $cache->saveWithLease('doc:1', ['v' => 1], 'doc:1', $generation),
                'An implausibly far-future deadline (backward clock step) must be ignored, not wedge saves',
            );
            $this->assertSame(['v' => 1], $cache->load('doc:1', 60, 'doc:1'));
        });
    }
}
