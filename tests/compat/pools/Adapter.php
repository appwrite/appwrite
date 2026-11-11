<?php

namespace Tests\Compat\Pools;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\Pools\Adapter as PoolAdapter;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Adapter\Swoole;
use Utopia\Pools\Connection;
use Utopia\Pools\Group;
use Utopia\Pools\Pool;
use Utopia\Telemetry\Adapter\Test;

/**
 * Maps tests/compat/pools/spec.json operations onto utopia-php/pools. Glue only.
 *
 * Every operation runs in a coroutine (the Swoole adapter needs one).
 * Resources are made by `init`, a list of creation behaviours indexed by the
 * creation count (the last repeats): {value} (a string), {object, reconnect?}
 * (an object named `object`, whose reconnect() returns true or false, throws
 * ("throw"), or returns nothing ("void")), {ticking: true, tick_throws?},
 * {stream} (a php://temp stream holding the text), {throw, class} (creation
 * fails), each with an optional `delay_ms` spent creating it.
 */
final class Adapter implements Base, Benchmarks
{
    /**
     * The callbacks a Test backend's observable gauge holds (an anonymous class).
     *
     * @return list<\Closure>
     */
    public static function callbacks(?object $gauge): array
    {
        $callbacks = $gauge === null ? [] : (get_object_vars($gauge)['callbacks'] ?? []);

        return \is_array($callbacks) ? array_values(array_filter($callbacks, static fn ($c) => $c instanceof \Closure)) : [];
    }

    public function benchmarks(): array
    {
        return [
            'pop_reclaim_stack' => ['Pop and reclaim a connection of a Stack pool of 8', function (Session $s) {
                $pool = new Pool(new Stack(), 'bench', 8, fn () => 'x', 1.0);

                return function () use ($pool): void {
                    $pool->reclaim($pool->pop());
                };
            }],
            'pop_reclaim_channel' => ['Pop and reclaim a connection of a waiting (Swoole) pool of 8, 100 times in one coroutine', function (Session $s) {
                $inner = null;
                Coroutine\run(function () use (&$inner): void {
                    $inner = new Pool(new Swoole(), 'bench', 8, fn () => 'x', 1.0);
                });

                return function () use ($inner): void {
                    Coroutine\run(function () use ($inner): void {
                        for ($i = 0; $i < 100; $i++) {
                            $inner->reclaim($inner->pop());
                        }
                    });
                };
            }],
            'use_stack' => ['Run a callback with a connection of a Stack pool of 8', function (Session $s) {
                $pool = new Pool(new Stack(), 'bench', 8, fn () => 'x', 1.0);

                return fn () => $pool->use(fn (string $r) => $r);
            }],
            'pop_reclaim_telemetry' => ['Pop and reclaim a connection of a Stack pool recording telemetry', function (Session $s) {
                $pool = new Pool(new Stack(), 'bench', 8, fn () => 'x', 1.0, new Test());

                return function () use ($pool): void {
                    $pool->reclaim($pool->pop());
                };
            }],
        ];
    }

    public function operations(): array
    {
        $ops = [
            'pool.new' => fn (array $a, Session $s) => $s->handle(self::pool($a, $s)),
            'pool.info' => fn (array $a, Session $s) => ['name' => self::of($a, $s)->name, 'size' => self::of($a, $s)->size, 'timeout' => self::of($a, $s)->timeout],
            'pool.pop' => fn (array $a, Session $s) => $s->handle(self::of($a, $s)->pop()),
            'pool.push' => function (array $a, Session $s) {
                self::of($a, $s)->push(self::connection($a, $s));

                return;
            },
            'pool.reclaim' => function (array $a, Session $s) {
                self::of($a, $s)->reclaim(isset($a['connection']) ? self::connection($a, $s) : null);

                return;
            },
            'pool.destroy' => function (array $a, Session $s) {
                self::of($a, $s)->destroy(isset($a['connection']) ? self::connection($a, $s) : null);

                return;
            },
            'pool.release' => function (array $a, Session $s) {
                self::of($a, $s)->release(self::connection($a, $s), $a['failed'] ?? false);

                return;
            },
            'pool.maintain' => function (array $a, Session $s) {
                self::of($a, $s)->maintain();

                return;
            },
            'pool.counts' => fn (array $a, Session $s) => self::counts(self::of($a, $s)),
            'pool.use' => fn (array $a, Session $s) => self::of($a, $s)->use(fn ($resource) => self::work([$resource], $a, $s)),
            'connection.get' => function (array $a, Session $s) {
                $c = self::connection($a, $s);

                return ['resource' => self::describe($c->resource), 'id' => self::id($c->id, $a['pool_name'] ?? '')];
            },
            'connection.reclaim' => function (array $a, Session $s) {
                self::connection($a, $s)->reclaim();

                return;
            },
            'connection.destroy' => function (array $a, Session $s) {
                self::connection($a, $s)->destroy();

                return;
            },
            'connection.orphan' => function (array $a, Session $s) {
                $connection = self::pool($a, $s)->pop();
                gc_collect_cycles();
                $connection->reclaim();
                $connection->destroy();

                return self::describe($connection->resource);
            },
            'pool.dropped_frees' => function (array $a, Session $s) {
                $freed = new \ArrayObject();
                (function () use ($a, $freed): void {
                    $pool = new Pool(self::adapter($a['adapter']), 'lifetime', 3, fn () => new TrackedResource($freed), 1.0);
                    $connections = [$pool->pop(), $pool->pop(), $pool->pop()];
                    foreach ($connections as $connection) {
                        $connection->reclaim();
                    }
                })();
                gc_collect_cycles();

                return \count($freed);
            },
            'adapter.new' => function (array $a, Session $s) {
                $adapter = self::adapter($a['adapter']);
                $adapter->initialize($a['size'] ?? 1);

                return $s->handle($adapter);
            },
            'adapter.push' => function (array $a, Session $s) {
                self::adapterOf($a, $s)->push(self::connection($a, $s));

                return;
            },
            'adapter.pop' => function (array $a, Session $s) {
                $c = self::adapterOf($a, $s)->pop($a['timeout'] ?? 0.0);

                return $c instanceof Connection ? self::describe($c->resource) : null;
            },
            'adapter.count' => fn (array $a, Session $s) => self::adapterOf($a, $s)->count(),
            'adapter.unblock' => function (array $a, Session $s) {
                self::adapterOf($a, $s)->unblock();

                return;
            },
            'adapter.synchronized' => fn (array $a, Session $s) => self::adapterOf($a, $s)->synchronized(fn () => $a['value'] ?? null),
            'group.new' => fn (array $a, Session $s) => $s->handle(new Group()),
            'group.add' => function (array $a, Session $s) {
                self::group($a, $s)->add(self::of($a, $s));

                return;
            },
            'group.get' => fn (array $a, Session $s) => self::group($a, $s)->get($a['name'])->name,
            'group.remove' => function (array $a, Session $s) {
                self::group($a, $s)->remove($a['name']);

                return;
            },
            'group.reclaim' => function (array $a, Session $s) {
                self::group($a, $s)->reclaim();

                return;
            },
            'group.use' => fn (array $a, Session $s) => self::group($a, $s)->use($a['names'], fn (...$resources) => self::work($resources, $a, $s)),
            'telemetry.new' => fn (array $a, Session $s) => $s->handle(new Test()),
            'telemetry.dump' => function (array $a, Session $s) {
                $t = $s->get($a['telemetry']);

                return [
                    'histograms' => array_map(null, array_keys($t->histograms), array_map(static fn ($h) => \count($h->values), array_values($t->histograms))) ?: [],
                    'observableGauges' => array_map(null, array_keys($t->observableGauges), array_map(static fn ($g) => \count(self::callbacks($g)), array_values($t->observableGauges))) ?: [],
                ];
            },
            'telemetry.observe' => function (array $a, Session $s) {
                $t = $s->get($a['telemetry']);
                $series = [];
                foreach ($t->observableGauges[$a['gauge']]->callbacks ?? [] as $callback) {
                    $callback(function (float|int $value, iterable $attributes = []) use (&$series): void {
                        $pairs = [];
                        foreach ($attributes as $k => $v) {
                            $pairs[] = [$k, $v];
                        }
                        $series[] = [$value, $pairs];
                    });
                }

                return $series;
            },
            'pool.concurrency' => fn (array $a, Session $s) => self::concurrency($a, $s),
            'waiter.handoff' => fn (array $a, Session $s) => Waiters::handoff(),
            'waiter.discard' => fn (array $a, Session $s) => Waiters::discard(),
            'waiter.creation' => fn (array $a, Session $s) => Waiters::creation(),
            'waiter.deadline' => fn (array $a, Session $s) => Waiters::deadline(),
            'waiter.capacity' => fn (array $a, Session $s) => Waiters::capacity(),
            // A pool made like pool.new, then each of `steps` on it, holding the
            // connections popped (last first for reclaim, destroy, release):
            // [what the step returned, count] after each.
            'pool.scenario' => function (array $a, Session $s) {
                $pool = self::pool($a, $s);
                $held = [];
                $out = [];
                foreach ($a['steps'] as $step) {
                    try {
                        $result = match ($step) {
                            'pop' => self::describe(($held[] = $pool->pop())->resource),
                            'reclaim' => $held === [] ? null : self::none($pool->reclaim(array_pop($held))),
                            'destroy' => $held === [] ? null : self::none($pool->destroy(array_pop($held))),
                            'release_ok' => $held === [] ? null : self::none($pool->release(array_pop($held))),
                            'release_failed' => $held === [] ? null : self::none($pool->release(array_pop($held), true)),
                            'reclaim_all' => self::none([$pool->reclaim(), $held = []]),
                            'destroy_all' => self::none([$pool->destroy(), $held = []]),
                            'maintain' => self::none($pool->maintain()),
                            'use_ok' => $pool->use(fn ($r) => self::describe($r)),
                            default => $pool->use(fn ($r) => throw new \RuntimeException('failed')),
                        };
                    } catch (\Throwable $e) {
                        $result = ['$error' => ['class' => $e::class, 'message' => $e->getMessage()]];
                    }
                    $out[] = [$result, $pool->count()];
                }

                return $out;
            },
            'pool.pop_elapsed' => function (array $a, Session $s) {
                $start = microtime(true);
                try {
                    self::of($a, $s)->pop();
                    $error = null;
                } catch (\Throwable $e) {
                    $error = ['$error' => ['class' => $e::class, 'message' => $e->getMessage()]];
                }
                $elapsed = microtime(true) - $start;

                return ['error' => $error, 'within' => $elapsed >= $a['at_least'] && $elapsed < $a['below']];
            },
        ];

        // The Swoole adapter needs a coroutine; run every operation in one.
        return array_map(static fn (\Closure $op) => static fn (array $a, Session $s) => self::coroutine(fn () => $op($a, $s)), $ops);
    }

    /**
     * The callback of pool.use / group.use: runs `during` actions
     * ({op: counts, pool} or {op: sleep, ms}), then throws `throw`
     * ({class, message}) or returns the resources it was given.
     */
    private static function work(array $resources, array $a, Session $s): mixed
    {
        $during = [];
        foreach ($a['during'] ?? [] as $action) {
            $during[] = match ($action['op']) {
                'counts' => self::counts(self::of($action, $s)),
                'sleep' => (static function () use ($action) {
                    Coroutine::sleep($action['ms'] / 1000);

                    return;
                })(),
                // PoolTestScope's failing lock: the pool's next synchronized() fails.
                'arm' => (static function () {
                    FailingStack::$armed = true;

                    return;
                })(),
                default => throw new Fault('unknown action'),
            };
        }
        if (isset($a['throw'])) {
            $class = $a['throw']['class'] ?? 'RuntimeException';
            throw new $class($a['throw']['message'] ?? '');
        }
        $described = array_map(self::describe(...), $resources);

        return ['resources' => $described, 'during' => $during];
    }

    /** Discards a fluent return value. */
    private static function none(mixed $ignored): null
    {
        return null;
    }

    private static function counts(Pool $pool): array
    {
        return ['count' => $pool->count(), 'empty' => $pool->isEmpty(), 'full' => $pool->isFull()];
    }

    private static function id(string $id, string $name): array
    {
        return ['prefixed' => str_starts_with($id, $name . '-'), 'length' => \strlen($id) - \strlen($name) - 1];
    }

    public static function describe(mixed $resource): mixed
    {
        if (\is_resource($resource)) {
            rewind($resource);

            return ['stream' => stream_get_contents($resource)];
        }
        if ($resource instanceof TickingResource) {
            return ['serial' => $resource->serial, 'ticks' => $resource->ticks];
        }
        if ($resource instanceof \Stringable) {
            return ['object' => (string) $resource];
        }

        return $resource;
    }

    private static function pool(array $a, Session $s): Pool
    {
        $created = 0;
        $init = ($a['init'] ?? []) ?: [['value' => 'x']];
        $make = function () use (&$created, $init) {
            ++$created;
            $spec = $init[min($created, \count($init)) - 1];
            if (isset($spec['delay_ms'])) {
                Coroutine::sleep($spec['delay_ms'] / 1000);
            }
            if (isset($spec['throw'])) {
                $class = $spec['class'] ?? 'Exception';
                throw new $class($spec['throw']);
            }
            if (isset($spec['object'])) {
                return \array_key_exists('reconnect', $spec)
                    ? new ReconnectingResource($spec['object'] . '-' . $created, $spec['reconnect'])
                    : new ObjectResource($spec['object'] . '-' . $created);
            }
            if (isset($spec['ticking'])) {
                return new TickingResource($created, $spec['tick_throws'] ?? false);
            }
            if (isset($spec['stream'])) {
                $stream = fopen('php://temp', 'r+');
                fwrite($stream, $spec['stream'] . '-' . $created);

                return $stream;
            }

            return $spec['value'];
        };
        $adapter = isset($a['fail_synchronized']) ? new FailingStack() : self::adapter($a['adapter'] ?? 'stack');

        return new Pool(
            $adapter,
            $a['name'] ?? 'test',
            $a['size'] ?? 5,
            $make(...),
            (float) ($a['timeout'] ?? 0.0),
            isset($a['telemetry']) ? $s->get($a['telemetry']) : null,
        );
    }

    private static function adapter(string $kind): PoolAdapter
    {
        return $kind === 'swoole' ? new Swoole() : new Stack();
    }

    /**
     * Runs `tasks` coroutines on one pool: `mode` pop_reclaim (pop, hold,
     * reclaim), use (use, holding inside), or hold (pop and keep). Reports
     * successes, errors, resources created, the pool's count after, and how
     * many distinct resources were handed out at once (hold).
     */
    private static function concurrency(array $a, Session $s): array
    {
        $created = 0;
        $pool = new Pool(self::adapter($a['adapter']), $a['name'] ?? 'concurrent', $a['size'], function () use (&$created): string {
            ++$created;

            return "connection-{$created}";
        }, (float) $a['timeout']);
        $success = 0;
        $errors = 0;
        $seen = [];
        $duplicates = 0;
        $channels = [];
        for ($i = 0; $i < $a['tasks']; ++$i) {
            $channels[$i] = new Channel(1);
            Coroutine::create(function () use ($pool, $a, $i, &$success, &$errors, &$seen, &$duplicates, $channels): void {
                $hold = static fn () => Coroutine::sleep(($a['random_hold'] ?? false) ? 0.001 * random_int(1, 5) : $a['hold_ms'] / 1000);
                try {
                    if ($a['mode'] === 'use') {
                        $pool->use(function ($resource) use ($hold) {
                            $hold();

                            return $resource;
                        });
                    } else {
                        $connection = $pool->pop();
                        if (isset($seen[$connection->resource])) {
                            ++$duplicates;
                        }
                        $seen[$connection->resource] = true;
                        $hold();
                        if ($a['mode'] === 'pop_reclaim') {
                            $pool->reclaim($connection);
                        }
                    }
                    ++$success;
                } catch (\Exception) {
                    ++$errors;
                }
                $channels[$i]->push(true);
            });
        }
        foreach ($channels as $channel) {
            $channel->pop();
        }

        return ['success' => $success, 'errors' => $errors, 'created' => $created, 'count' => $pool->count(), 'distinct' => \count($seen), 'duplicates' => $duplicates];
    }

    private static function coroutine(callable $fn): mixed
    {
        if (Coroutine::getCid() > 0) {
            return $fn();
        }
        $result = null;
        $error = null;
        Coroutine\run(function () use ($fn, &$result, &$error): void {
            try {
                $result = $fn();
            } catch (\Throwable $e) {
                $error = $e;
            }
        });
        if ($error !== null) {
            throw $error;
        }

        return $result;
    }

    private static function of(array $a, Session $s): Pool
    {
        $p = $s->get($a['pool']);

        return $p instanceof Pool ? $p : throw new Fault('not a pool');
    }

    private static function connection(array $a, Session $s): Connection
    {
        $c = $s->get($a['connection']);

        return $c instanceof Connection ? $c : throw new Fault('not a connection');
    }

    private static function adapterOf(array $a, Session $s): PoolAdapter
    {
        $c = $s->get($a['adapter']);

        return $c instanceof PoolAdapter ? $c : throw new Fault('not an adapter');
    }

    private static function group(array $a, Session $s): Group
    {
        $g = $s->get($a['group']);

        return $g instanceof Group ? $g : throw new Fault('not a group');
    }
}

/** An object resource without recovery hooks. */
final class ObjectResource implements \Stringable
{
    public function __construct(private string $name)
    {
    }

    public function __toString(): string
    {
        return $this->name;
    }
}

/** An object resource whose reconnect() returns true or false, throws, or returns nothing. */
final class ReconnectingResource implements \Stringable
{
    public function __construct(private string $name, private bool|string $mode)
    {
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function reconnect(): ?bool
    {
        return match ($this->mode) {
            'throw' => throw new \RuntimeException('Recovery failed'),
            'void' => null,
            default => (bool) $this->mode,
        };
    }
}

/** PoolTestScope's TickingResource. */
final class TickingResource
{
    public int $ticks = 0;

    public function __construct(public readonly int $serial, private bool $throws = false)
    {
    }

    public function tick(): void
    {
        ++$this->ticks;
        if ($this->throws) {
            throw new \RuntimeException('server closed the connection');
        }
    }
}

/** ConnectionTestScope's TrackedResource. */
final class TrackedResource
{
    public function __construct(private \ArrayObject $freed)
    {
    }

    public function __destruct()
    {
        $this->freed[] = true;
    }
}

/** PoolTestScope's Stack whose synchronized() fails once, after the callback fails. */
final class FailingStack extends Stack
{
    public static bool $armed = false;

    public function synchronized(callable $callback): mixed
    {
        if (self::$armed) {
            self::$armed = false;
            throw new \RuntimeException('Lock failed');
        }

        return parent::synchronized($callback);
    }
}

/**
 * Ports of WaiterTest: each runs the test's scenario and returns what it asserts.
 */
final class Waiters
{
    public static function handoff(): array
    {
        $telemetry = new Test();
        $adapter = new Swoole();
        $created = 0;
        $pool = new Pool($adapter, 'handoff', 1, static function () use (&$created): object {
            ++$created;

            return new \stdClass();
        }, 0.1, $telemetry);
        $failure = new \RuntimeException('successor failed');
        $results = [];
        $snapshots = [];
        $identities = [];
        $sample = static function () use ($telemetry, $pool, $adapter): array {
            $active = null;
            foreach (Adapter::callbacks($telemetry->observableGauges['pool.connection.active.count'] ?? null) as $callback) {
                $callback(static function (float|int $value) use (&$active): void {
                    $active = $value;
                });
            }

            return [$active, $adapter->count(), $pool->count()];
        };
        $first = $pool->pop();
        $identities[] = $first->resource;
        $completed = new Channel(2);
        $entered = new Channel(1);
        Coroutine::create(static function () use ($pool, $failure, $completed, $entered, &$identities): void {
            try {
                $pool->use(static function (object $resource) use ($failure, $entered, &$identities): never {
                    $identities[] = $resource;
                    $entered->push(true);
                    Coroutine::sleep(0.02);
                    throw $failure;
                });
            } catch (\Throwable $error) {
                $completed->push($error);
            }
        });
        Coroutine::create(static function () use ($pool, $completed, &$identities): void {
            try {
                $completed->push($pool->use(static function (object $resource) use (&$identities): string {
                    $identities[] = $resource;

                    return 'replacement';
                }));
            } catch (\Throwable $error) {
                $completed->push($error);
            }
        });
        $pool->reclaim($first);
        $entered->pop(1);
        $snapshots[] = $sample();
        $results = [$completed->pop(1), $completed->pop(1)];
        $snapshots[] = $sample();

        return [
            'snapshots' => $snapshots,
            'results' => self::results($results),
            'created' => $created,
            'identities' => \count($identities),
            'first_reused' => $identities[0] === $identities[1],
            'replacement_new' => $identities[1] !== $identities[2],
        ];
    }

    public static function discard(): array
    {
        $created = 0;
        $pool = new Pool(new Swoole(), 'discard', 1, static function () use (&$created): object {
            ++$created;

            return new \stdClass();
        }, 0.5);
        $completed = new Channel(2);
        Coroutine::create(static function () use ($pool, $completed): void {
            try {
                $pool->use(static function (): never {
                    Coroutine::sleep(0.02);
                    throw new \RuntimeException('request failed');
                });
            } catch (\Throwable $error) {
                $completed->push($error);
            }
        });
        Coroutine::create(static function () use ($pool, $completed): void {
            try {
                $completed->push($pool->use(static fn (): string => 'replacement'));
            } catch (\Throwable $error) {
                $completed->push($error);
            }
        });
        $results = [$completed->pop(1), $completed->pop(1)];

        return ['results' => self::results($results), 'created' => $created, 'full' => $pool->isFull()];
    }

    public static function creation(): array
    {
        $created = 0;
        $pool = new Pool(new Swoole(), 'creation', 1, static function () use (&$created): string {
            if (++$created === 1) {
                Coroutine::sleep(0.02);
                throw new \RuntimeException('connection failed');
            }

            return 'replacement';
        }, 0.5);
        $completed = new Channel(2);
        for ($index = 0; $index < 2; ++$index) {
            Coroutine::create(static function () use ($pool, $completed): void {
                try {
                    $completed->push($pool->use(static fn (string $value): string => $value));
                } catch (\Throwable $error) {
                    $completed->push($error);
                }
            });
        }
        $results = [$completed->pop(1), $completed->pop(1)];

        return ['results' => self::results($results), 'created' => $created, 'full' => $pool->isFull()];
    }

    public static function deadline(): array
    {
        $adapter = new Swoole();
        $pool = new Pool($adapter, 'deadline', 1, static fn (): string => 'occupied', 0.04);
        $connection = $pool->pop();
        $started = microtime(true);
        Coroutine::create(static function () use ($adapter): void {
            for ($index = 0; $index < 10; ++$index) {
                Coroutine::sleep(0.01);
                $adapter->unblock();
            }
        });
        $failure = null;
        try {
            $pool->pop();
        } catch (\Exception $error) {
            $failure = $error;
        }
        $elapsed = microtime(true) - $started;
        $pool->reclaim($connection);

        return ['failure' => self::results([$failure]), 'quick' => $elapsed < 0.1, 'full' => $pool->isFull()];
    }

    public static function capacity(): array
    {
        $active = 0;
        $maximum = 0;
        $pool = new Pool(new Swoole(), 'capacity', 2, static fn (): object => new \stdClass(), 0.5);
        $completed = new Channel(8);
        for ($index = 0; $index < 8; ++$index) {
            Coroutine::create(static function () use ($pool, $completed, &$active, &$maximum): void {
                try {
                    $pool->use(static function () use (&$active, &$maximum): never {
                        ++$active;
                        $maximum = max($maximum, $active);
                        try {
                            Coroutine::sleep(0.002);
                            throw new \RuntimeException('discard');
                        } finally {
                            --$active;
                        }
                    });
                } catch (\Throwable $error) {
                    $completed->push($error);
                }
            });
        }
        $results = [];
        for ($index = 0; $index < 8; ++$index) {
            $results[] = $completed->pop(1);
        }

        return ['results' => self::results($results), 'maximum' => $maximum, 'active' => $active, 'full' => $pool->isFull()];
    }

    /** Results as values, errors as {class, message}, sorted. */
    private static function results(array $results): array
    {
        $out = array_map(static fn ($r) => $r instanceof \Throwable ? ['class' => $r::class, 'message' => $r->getMessage()] : $r, $results);
        usort($out, static fn ($x, $y) => strcmp(json_encode($x), json_encode($y)));

        return $out;
    }
}
