<?php

declare(strict_types=1);

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Schedule\Claim;
use Utopia\Schedule\Clock\Test as TestClock;
use Utopia\Schedule\Occurrence;
use Utopia\Schedule\Scheduler;
use Utopia\Schedule\Source\Entry;
use Utopia\Schedule\Source\Row;
use Utopia\Schedule\Store\Redis as RedisStore;
use Utopia\Schedule\Trigger\Cron;

final class RedisStoreTest extends TestCase
{
    private \Redis $redis;

    private string $key = 'utopia-schedule-test';

    protected function setUp(): void
    {
        if (!\extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis is not loaded');
        }

        $host = getenv('REDIS_HOST');
        $port = getenv('REDIS_PORT');

        $this->redis = new \Redis();
        $this->redis->connect($host === false ? '127.0.0.1' : $host, $port === false ? 16385 : (int) $port);
        $this->redis->del($this->key);
    }

    public function testAnEmptyStoreHoldsNoClaim(): void
    {
        $this->assertNotInstanceOf(\Utopia\Schedule\Claim::class, $this->store()->load());
    }

    public function testTheFirstClaimTakesTheKeyAndNoLaterOneCan(): void
    {
        $store = $this->store();

        $this->assertTrue($store->swap(null, new Claim('first', 100.5, null)));
        $this->assertFalse(
            $store->swap(null, new Claim('second', 200.0, null)),
            'expecting an empty store must fail once a claim exists',
        );

        $claim = $store->load();
        $this->assertInstanceOf(\Utopia\Schedule\Claim::class, $claim);
        $this->assertSame('first', $claim->token);
        $this->assertEqualsWithDelta(100.5, $claim->expiresAt, 0.000001);
        $this->assertNull($claim->windowEnd);
    }

    public function testAStaleTokenCannotOverwriteItsSuccessor(): void
    {
        $store = $this->store();
        $store->swap(null, new Claim('leader', 100.0, '1787022060.000000'));
        $this->assertTrue($store->swap('leader', new Claim('standby', 200.0, '1787022120.000000')));

        // The deposed leader's late commit: the compare fails inside the
        // script, so the successor's coverage is left exactly as it was.
        $this->assertFalse($store->swap('leader', new Claim('leader', 300.0, '1787022000.000000')));

        $claim = $store->load();
        $this->assertInstanceOf(\Utopia\Schedule\Claim::class, $claim);
        $this->assertSame('standby', $claim->token);
        $this->assertSame('1787022120.000000', $claim->windowEnd, 'a fenced write must not rewind the watermark');
    }

    public function testAReleasedClaimKeepsItsWatermark(): void
    {
        $store = $this->store();
        $store->swap(null, new Claim('leader', 100.0, '1787022060.000000'));

        // stop() releases with an empty token, which is a real value and not
        // the same as "no record".
        $this->assertTrue($store->swap('leader', new Claim('', 0.0, '1787022060.000000')));

        $claim = $store->load();
        $this->assertInstanceOf(\Utopia\Schedule\Claim::class, $claim);
        $this->assertSame('', $claim->token);
        $this->assertSame('1787022060.000000', $claim->windowEnd);
        $this->assertFalse($store->swap(null, new Claim('next', 400.0, null)), 'a released claim is still a record');
        $this->assertTrue($store->swap('', new Claim('next', 400.0, '1787022060.000000')));
    }

    public function testTheKeyNeverExpires(): void
    {
        $store = $this->store();
        $store->swap(null, new Claim('leader', 1.0, '1787022060.000000'));

        // A TTL here would take the watermark with it when the lease ran
        // out, and the next leader would start from "now", skipping
        // everything in the gap.
        $this->assertSame(-1, $this->redis->ttl($this->key));
    }

    public function testTwoSchedulersSharingRedisElectOneLeader(): void
    {
        $clock = new TestClock(new \DateTimeImmutable('2026-08-18 03:00:30.000000'));
        $store = $this->store();

        $build = fn(string $token): Scheduler => new Scheduler(
            source: new SnapshotSource(
                snapshot: fn(): array => [new Row('fn', 'v1')],
                make: fn(Row $row): Entry => new Entry(new Cron('* * * * *')),
            ),
            store: $store,
            clock: $clock,
            interval: 60,
            lease: 240,
            token: $token,
        );

        $a = $build('a');
        $b = $build('b');
        $a->reconcile();
        $b->reconcile();

        $a->tick();
        $a->commit();
        $held = $store->load();
        $this->assertInstanceOf(\Utopia\Schedule\Claim::class, $held);
        $this->assertSame('a', $held->token);

        $followerSaw = $b->tick();
        $this->assertSame([], $followerSaw, 'the follower gets nothing while the claim is live');

        $clock->advance(60.0);
        $delivered = array_map(fn(Occurrence $occurrence): string => $occurrence->due->format('H:i:s'), $a->tick());
        $a->commit();
        $this->assertSame(['03:01:00'], $delivered);

        // The leader stalls past its lease; the follower takes over through
        // Redis and resumes from the watermark its predecessor committed.
        $clock->advance(300.0);
        $recovered = array_map(fn(Occurrence $occurrence): string => $occurrence->due->format('H:i:s'), $b->tick());
        $b->commit();

        $takenOver = $store->load();
        $this->assertInstanceOf(\Utopia\Schedule\Claim::class, $takenOver);
        $this->assertSame('b', $takenOver->token);
        $this->assertContains('03:02:00', $recovered, 'coverage resumes where the predecessor stopped');

        $deposed = $a->tick();
        $this->assertSame([], $deposed, 'the deposed leader stops dispatching');
    }

    public function testOnlyOneOfManyContendersTakesAFreshClaim(): void
    {
        if (!\function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork required');
        }

        // The compare and the write have to be one operation. Read-then-write
        // passes every single-threaded assertion in this file and still lets
        // several contenders believe they lead, so the only honest test is
        // real processes racing for the same fresh key.
        $contenders = 8;
        $rounds = 5;
        $winners = 0;

        for ($round = 0; $round < $rounds; ++$round) {
            $this->redis->del($this->key);
            $startAt = microtime(true) + 0.25;
            $children = [];

            for ($contender = 0; $contender < $contenders; ++$contender) {
                $pid = pcntl_fork();
                $this->assertNotSame(-1, $pid, 'failed to fork');

                if ($pid === 0) {
                    // A forked child must not share the parent's socket.
                    $own = new \Redis();
                    $own->connect($this->redis->getHost(), $this->redis->getPort());
                    $store = new RedisStore($own, $this->key);

                    while (microtime(true) < $startAt) {
                        usleep(1000);
                    }

                    exit($store->swap(null, new Claim("token-{$round}-{$contender}", 100.0, null)) ? 0 : 1);
                }

                $children[] = $pid;
            }

            foreach ($children as $pid) {
                $status = 0;
                pcntl_waitpid($pid, $status);
                // pcntl_waitpid writes $status by reference, so it is mixed
                // until narrowed.
                if (\is_int($status) && pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0) {
                    ++$winners;
                }
            }
        }

        $this->assertSame($rounds, $winners, 'exactly one contender per round may take the claim');
    }

    private function store(): RedisStore
    {
        return new RedisStore($this->redis, $this->key);
    }
}
