<?php

namespace Tests\Compat\Lock;

use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;
use Tests\Compat\Adapter as Base;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\Lock\Distributed;
use Utopia\Lock\Exception\Contention;
use Utopia\Lock\File;
use Utopia\Lock\Lock;
use Utopia\Lock\Mutex;
use Utopia\Lock\Semaphore;

/**
 * Maps tests/compat/lock/spec.json operations onto utopia-php/lock. Glue only.
 */
final class Adapter implements Base
{
    public function operations(): array
    {
        return [
            'distributed.new' => fn (array $a, Session $s) => $s->handle(
                new Distributed(self::redis($s), $a['key'], ...(isset($a['ttl']) ? ['ttl' => $a['ttl']] : []))
            ),
            'mutex.new' => fn (array $a, Session $s) => $s->handle(new Mutex()),
            'semaphore.new' => fn (array $a, Session $s) => $s->handle(new Semaphore($a['permits'])),
            'file.new' => fn (array $a, Session $s) => $s->handle(new File($a['path'], ...(isset($a['mode']) ? ['mode' => $a['mode']] : []))),

            'lock.acquire' => fn (array $a, Session $s) => self::lock($a, $s)->acquire(...self::timeout($a)),
            'lock.try_acquire' => fn (array $a, Session $s) => self::lock($a, $s)->tryAcquire(),
            'lock.release' => fn (array $a, Session $s) => self::lock($a, $s)->release(),
            'lock.with_lock' => fn (array $a, Session $s) => self::lock($a, $s)->withLock(
                function () use ($a) {
                    if (isset($a['throw'])) {
                        throw new \RuntimeException($a['throw']);
                    }

                    return $a['result'] ?? null;
                },
                ...self::timeout($a),
            ),
            'lock.concurrency' => fn (array $a, Session $s) => self::concurrency(self::lock($a, $s), $a),

            'distributed.refresh' => fn (array $a, Session $s) => self::distributed($a, $s)->refresh(),
            'distributed.is_held' => fn (array $a, Session $s) => self::distributed($a, $s)->isHeld(),
            'distributed.adopt' => function (array $a, Session $s) {
                self::distributed($a, $s)->adopt($a['token']);

                return;
            },
            'distributed.token' => fn (array $a, Session $s) => self::distributed($a, $s)->token(),
            'distributed.has_token' => fn (array $a, Session $s) => self::distributed($a, $s)->token() !== null,
            'distributed.set_logger' => function (array $a, Session $s) {
                /** @var \ArrayObject<int, string> $logs */
                $logs = new \ArrayObject();
                self::distributed($a, $s)->setLogger(function (string $message) use ($logs): void {
                    $logs[] = $message;
                });
                self::$logs[spl_object_id(self::distributed($a, $s))] = $logs;

                return;
            },
            'distributed.retry_spread' => fn (array $a, Session $s) => self::retrySpread($s, $a),
            'lock.acquire_elapsed' => function (array $a, Session $s) {
                $start = microtime(true);
                $acquired = self::lock($a, $s)->acquire(...self::timeout($a));
                $elapsed = microtime(true) - $start;

                return ['acquired' => $acquired, 'within' => $elapsed >= $a['at_least'] && $elapsed < $a['below']];
            },

            // Fixtures: direct Redis access, as the PHP tests use.
            'redis.set' => fn (array $a, Session $s) => self::redis($s)->set($a['key'], $a['value'], ['EX' => $a['ex']]),
            'redis.get' => fn (array $a, Session $s) => self::redis($s)->get($a['key']),
            'redis.del' => fn (array $a, Session $s) => self::redis($s)->del($a['key']),
            'redis.ttl' => fn (array $a, Session $s) => self::redis($s)->ttl($a['key']) > 0,

            'distributed.logs' => function (array $a, Session $s) {
                $logs = self::$logs[spl_object_id(self::distributed($a, $s))] ?? new \ArrayObject();
                $unique = array_values(array_unique($logs->getArrayCopy()));
                sort($unique);

                return $unique;
            },
        ];
    }

    /**
     * Port of DistributedTest::testWaitersDoNotRetryInLockstep: the time to the
     * first retry must vary across waiters (jittered backoff).
     */
    private static function retrySpread(Session $s, array $a): array
    {
        $holder = new Distributed(self::redis($s), $a['key'], 30);
        $holder->tryAcquire();
        $observed = 0;
        $lowest = PHP_FLOAT_MAX;
        $highest = 0.0;
        for ($run = 0; $run < $a['runs']; $run++) {
            $waiter = new Distributed(self::redis($s), $a['key'], 30);
            $start = microtime(true);
            $first = null;
            $waiter->setLogger(function () use ($start, &$first): void {
                $first ??= microtime(true) - $start;
            });
            $waiter->acquire(0.05);
            if ($first !== null) {
                $observed++;
                $lowest = min($lowest, $first);
                $highest = max($highest, $first);
            }
        }
        $holder->release();

        return ['observed' => $observed > 0, 'spread' => $highest - $lowest > 0.010];
    }

    /** @var array<int, \ArrayObject<int, string>> */
    private static array $logs = [];

    private static function redis(Session $s): \Redis
    {
        $url = parse_url($s->service('redis'));
        $redis = new \Redis();
        $redis->connect($url['host'] ?? 'redis', $url['port'] ?? 6379);

        return $redis;
    }

    private static function lock(array $a, Session $s): Lock
    {
        $lock = $s->get($a['lock']);

        return $lock instanceof Lock ? $lock : throw new Fault('not a lock');
    }

    private static function distributed(array $a, Session $s): Distributed
    {
        $lock = $s->get($a['lock']);

        return $lock instanceof Distributed ? $lock : throw new Fault('not a distributed lock');
    }

    /**
     * @return array{timeout?: float}
     */
    private static function timeout(array $a): array
    {
        return isset($a['timeout']) ? ['timeout' => (float) $a['timeout']] : [];
    }

    /**
     * Runs `tasks` coroutines that each hold the lock for `hold_ms` through
     * withLock(timeout), and reports how many ran, the most that ran at once,
     * and how many hit contention.
     */
    private static function concurrency(Lock $lock, array $a): array
    {
        $stats = ['count' => 0, 'max' => 0, 'contention' => 0];
        $live = 0;
        $hold = $a['hold_ms'] / 1000;
        Coroutine\run(function () use ($lock, $a, $hold, &$stats, &$live): void {
            $group = new WaitGroup();
            for ($i = 0; $i < $a['tasks']; $i++) {
                $group->add();
                go(function () use ($lock, $a, $hold, &$stats, &$live, $group): void {
                    try {
                        $lock->withLock(function () use ($hold, &$stats, &$live): void {
                            $live++;
                            $stats['max'] = max($stats['max'], $live);
                            Coroutine::sleep($hold);
                            $live--;
                        }, ...self::timeout($a));
                        $stats['count']++;
                    } catch (Contention) {
                        $stats['contention']++;
                    } finally {
                        $group->done();
                    }
                });
            }
            $group->wait();
        });

        return $stats;
    }
}
