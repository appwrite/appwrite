<?php

namespace Tests\Compat\Schedule;

use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\Schedule\Changes;
use Utopia\Schedule\Claim;
use Utopia\Schedule\Clock\System as SystemClock;
use Utopia\Schedule\Clock\Test as TestClock;
use Utopia\Schedule\Occurrence;
use Utopia\Schedule\Scheduler;
use Utopia\Schedule\Source;
use Utopia\Schedule\Source\Entry;
use Utopia\Schedule\Source\Row;
use Utopia\Schedule\Store;
use Utopia\Schedule\Store\Memory as MemoryStore;
use Utopia\Schedule\Store\Redis as RedisStore;
use Utopia\Schedule\Trigger;
use Utopia\Schedule\Trigger\At;
use Utopia\Schedule\Trigger\Cron;
use Utopia\Schedule\Trigger\Interval;
use Utopia\Schedule\Trigger\Shifted;
use Utopia\Telemetry\Adapter\Test as TestTelemetry;

/**
 * Maps tests/compat/schedule/spec.json operations onto utopia-php/schedule. Glue only.
 *
 * Moments are `"Y-m-d H:i:s.u"` strings (`new DateTimeImmutable($s)`, UTC) or
 * `{"time": ..., "tz": ...}`; triggers are specs (`{"cron": ...}`,
 * `{"interval": n, "anchor": moment}`, `{"at": moment}`, `{"in": n, "from":
 * moment}`, `{"shifted": trigger, "seconds": n}`); a source is a script
 * (Script) whose rows, change feed, failures and `make` results come from the
 * case.
 */
final class Adapter implements Base, Benchmarks
{
    public const string FORMAT = 'Y-m-d H:i:s.u T';

    public function benchmarks(): array
    {
        $at = fn (string $s) => new \DateTimeImmutable($s);

        return [
            'cron_parse' => ['Parse a cron expression with lists, ranges, steps and names', fn (Session $s) => fn () => new Cron('*/15 9-17 * JAN-OCT MON-FRI')],
            'cron_day' => ['List a day of */5 cron occurrences (288)', function (Session $s) use ($at) {
                $cron = new Cron('*/5 * * * *');
                [$start, $end] = [$at('2026-08-17 00:00:00'), $at('2026-08-18 00:00:00')];

                return fn () => $cron->occurrencesBetween($start, $end);
            }],
            'interval_day' => ['List a day of one-minute interval occurrences (1440)', function (Session $s) use ($at) {
                $interval = new Interval(60);
                [$start, $end] = [$at('2026-08-17 00:00:00'), $at('2026-08-18 00:00:00')];

                return fn () => $interval->occurrencesBetween($start, $end);
            }],
            'tick' => ['Tick and commit a minute over 1000 minutely schedules (1000 occurrences)', function (Session $s) use ($at) {
                $clock = new TestClock($at('2026-08-18 03:00:30'));
                $source = new Script(['rows' => [['prefix' => 's', 'count' => 1000, 'version' => 'v1']], 'trigger' => ['interval' => 60]], $s);
                $scheduler = new Scheduler(source: $source, tickSeconds: 60, leaseSeconds: 600, token: 'bench', clock: $clock);
                $scheduler->reconcile();
                $scheduler->tick();
                $scheduler->commit();

                return function () use ($scheduler, $clock) {
                    $clock->advance(60.0);
                    $due = $scheduler->tick();
                    $scheduler->commit();

                    return $due;
                };
            }],
        ];
    }

    public function operations(): array
    {
        return [
            'trigger.new' => function (array $a, Session $s) {
                self::trigger($a['trigger']);

                return true;
            },
            'trigger.occurrences' => function (array $a, Session $s) {
                $trigger = self::trigger($a['trigger']);
                $start = self::moment($a['start']);
                $end = isset($a['window']) ? $start->modify($a['window']) : self::moment($a['end']);
                $dues = array_map(
                    fn (\DateTimeImmutable $due) => $due->format($a['format'] ?? self::FORMAT),
                    $trigger->occurrencesBetween($start, $end),
                );

                return isset($a['limit'])
                    ? ['count' => \count($dues), 'head' => \array_slice($dues, 0, $a['limit']), 'digest' => md5(implode("\n", $dues))]
                    : $dues;
            },
            'trigger.recurring' => fn (array $a, Session $s) => self::trigger($a['trigger'])->recurring(),

            'clock.system' => function (array $a, Session $s) {
                $clock = new SystemClock();
                $before = $clock->now();
                $clock->sleep((float) $a['seconds']);
                $after = $clock->now();
                $elapsed = (float) $after->format('U.u') - (float) $before->format('U.u');
                $expected = max(0.0, (float) $a['seconds']);

                return ['zone' => $after->format('e'), 'slept' => $elapsed >= $expected - 0.001, 'bounded' => $elapsed < $expected + 0.5];
            },
            'clock.new' => fn (array $a, Session $s) => $s->handle(new TestClock(self::moment($a['start']))),
            'clock.now' => fn (array $a, Session $s) => self::clock($a, $s)->now()->format($a['format'] ?? self::FORMAT),
            'clock.advance' => function (array $a, Session $s) {
                self::clock($a, $s)->advance((float) $a['seconds']);

                return;
            },
            'clock.sleep' => function (array $a, Session $s) {
                self::clock($a, $s)->sleep((float) $a['seconds']);

                return;
            },

            'store.memory' => fn (array $a, Session $s) => $s->handle(new MemoryStore()),
            'store.redis' => fn (array $a, Session $s) => $s->handle(new RedisStore(self::redis($s, $a['name'] ?? ''), $a['key'])),
            'store.load' => fn (array $a, Session $s) => self::claim(self::store($a, $s)->load()),
            'store.swap' => fn (array $a, Session $s) => self::store($a, $s)->swap($a['expected'] ?? null, new Claim(
                $a['claim']['token'],
                (float) $a['claim']['expiresAt'],
                isset($a['claim']['coveredUntil']) ? (float) $a['claim']['coveredUntil'] : null,
                isset($a['claim']['syncedUntil']) ? (float) $a['claim']['syncedUntil'] : null,
            )),
            'store.race' => fn (array $a, Session $s) => self::race($a, $s),
            'redis.ttl' => fn (array $a, Session $s) => self::redis($s)->ttl($a['key']),
            'redis.kill' => fn (array $a, Session $s) => self::kill($s, $a['name']),

            'source.new' => fn (array $a, Session $s) => $s->handle(($a['since'] ?? null) !== null ? new ChangesScript($a, $s) : new Script($a, $s)),
            'source.set' => function (array $a, Session $s) {
                self::source($a, $s)->set($a);

                return;
            },
            'source.made' => fn (array $a, Session $s) => self::source($a, $s)->made,

            'scheduler.new' => fn (array $a, Session $s) => $s->handle(self::scheduler($a, $s)),
            'scheduler.reconcile' => function (array $a, Session $s) {
                self::get($a, $s)->scheduler->reconcile($a['full'] ?? false);

                return;
            },
            'scheduler.tick' => fn (array $a, Session $s) => self::occurrences(self::get($a, $s)->scheduler->tick(), $a['summary'] ?? false),
            'scheduler.commit' => fn (array $a, Session $s) => self::get($a, $s)->scheduler->commit(),
            'scheduler.count' => fn (array $a, Session $s) => self::get($a, $s)->scheduler->count(),
            'scheduler.is_current' => fn (array $a, Session $s) => self::get($a, $s)->scheduler->isCurrent(
                new Occurrence($a['id'], self::moment($a['due']), null, $a['version'] ?? ''),
            ),
            'scheduler.run' => fn (array $a, Session $s) => self::run(self::get($a, $s), $a),
            'scheduler.stop' => function (array $a, Session $s) {
                self::get($a, $s)->scheduler->stop();

                return;
            },
            'scheduler.errors' => fn (array $a, Session $s) => self::get($a, $s)->errors->getArrayCopy(),
            'scheduler.telemetry' => fn (array $a, Session $s) => self::telemetry(self::get($a, $s)->telemetry),

            'occurrence.key' => fn (array $a, Session $s) => (new Occurrence($a['id'], self::moment($a['due']), $a['payload'] ?? null))->key(),
        ];
    }

    /** `"Y-m-d H:i:s.u"` (default timezone, UTC) or `{"time": ..., "tz": ...}`. */
    public static function moment(mixed $m): \DateTimeImmutable
    {
        if (\is_array($m)) {
            return new \DateTimeImmutable($m['time'], isset($m['tz']) ? new \DateTimeZone($m['tz']) : null);
        }

        return new \DateTimeImmutable($m);
    }

    public static function trigger(array $t): Trigger
    {
        return match (true) {
            isset($t['cron']) => new Cron($t['cron']),
            isset($t['interval']) => new Interval($t['interval'], isset($t['anchor']) ? self::moment($t['anchor']) : null),
            isset($t['at']) => new At(self::moment($t['at'])),
            isset($t['in']) => At::in($t['in'], self::moment($t['from'])),
            isset($t['shifted']) => new Shifted(self::trigger($t['shifted']), $t['seconds']),
            default => throw new Fault('unknown trigger ' . json_encode($t)),
        };
    }

    public static function claim(?Claim $claim): ?array
    {
        return $claim === null ? null : [
            'token' => $claim->token,
            'expiresAt' => $claim->expiresAt,
            'coveredUntil' => $claim->coveredUntil,
            'syncedUntil' => $claim->syncedUntil,
        ];
    }

    /** A scripted failure: `{"class": ..., "message": ...}`. */
    public static function error(array $e): \Throwable
    {
        return match ($e['class']) {
            'InvalidArgumentException' => new \InvalidArgumentException($e['message']),
            default => new \RuntimeException($e['message']),
        };
    }

    /**
     * @param list<Occurrence> $occurrences
     */
    private static function occurrences(array $occurrences, bool $summary = false): array
    {
        if ($summary) {
            $keys = array_map(fn (Occurrence $o) => $o->key(), $occurrences);

            return ['count' => \count($keys), 'distinct' => \count(array_unique($keys)), 'digest' => md5(implode("\n", $keys))];
        }

        return array_map(fn (Occurrence $o) => [
            'id' => $o->id,
            'due' => $o->due->format(self::FORMAT),
            'payload' => $o->payload,
            'version' => $o->version,
            'key' => $o->key(),
        ], $occurrences);
    }

    private static function scheduler(array $a, Session $s): Instance
    {
        $telemetry = ($a['telemetry'] ?? false) ? new TestTelemetry() : null;
        /** @var \ArrayObject<int, list<mixed>> $errors */
        $errors = new \ArrayObject();
        $store = isset($a['store']) ? $s->get($a['store']) : new MemoryStore();
        $clock = self::clock($a, $s);
        $source = self::source($a, $s);
        $scheduler = new Scheduler(
            source: $source,
            store: $store,
            tickSeconds: $a['tick'] ?? 1,
            syncSeconds: $a['sync'] ?? 10,
            leadSeconds: $a['lead'] ?? 0,
            snapshotSeconds: $a['snapshot'] ?? null,
            recoverSeconds: $a['recover'] ?? null,
            leaseSeconds: $a['lease'] ?? null,
            token: $a['token'] ?? null,
            clock: $clock,
            telemetry: $telemetry ?? new \Utopia\Telemetry\Adapter\None(),
            onError: ($a['errors'] ?? false) ? function (\Throwable $error, ?Row $row) use ($errors): void {
                $errors[] = [$error::class, $error->getMessage(), $row?->id, $row?->data];
            } : null,
        );
        $source->scheduler = $scheduler;

        return new Instance($scheduler, $store, $clock, $telemetry, $errors);
    }

    /**
     * `run()` with a handler that records each hand-over (`at`: the clock,
     * `covered`: the stored watermark, `occurrences`) and stops after
     * `stop_after` occurrences or `stop_batches` hand-overs (default: the
     * first), never with `never`, or throws `throw`.
     */
    private static function run(Instance $i, array $a): array
    {
        $batches = [];
        $seen = 0;
        $i->scheduler->run(function (array $occurrences) use ($i, $a, &$batches, &$seen): void {
            $batches[] = [
                'at' => $i->clock->now()->format(self::FORMAT),
                'covered' => $i->store->load()?->coveredUntil,
                'occurrences' => self::occurrences($occurrences),
            ];
            $seen += \count($occurrences);
            if (isset($a['throw'])) {
                throw self::error($a['throw']);
            }
            if ($a['never'] ?? false) {
                return;
            }
            if ((isset($a['stop_after']) && $seen >= $a['stop_after'])
                || (isset($a['stop_batches']) && \count($batches) >= $a['stop_batches'])
                || (!isset($a['stop_after']) && !isset($a['stop_batches']))) {
                $i->scheduler->stop();
            }
        });

        return $batches;
    }

    /** What the Test telemetry backend recorded; durations as counts (wall time). */
    private static function telemetry(?TestTelemetry $telemetry): array
    {
        if ($telemetry === null) {
            return [];
        }
        $values = fn (object $instrument): array => get_object_vars($instrument)['values'] ?? [];
        $out = ['counters' => [], 'histograms' => [], 'gauges' => []];
        foreach ($telemetry->counters as $name => $counter) {
            $out['counters'][$name] = $values($counter);
        }
        foreach ($telemetry->histograms as $name => $histogram) {
            $out['histograms'][$name] = str_ends_with($name, '.duration') ? \count($values($histogram)) : $values($histogram);
        }
        foreach ($telemetry->gauges as $name => $gauge) {
            $out['gauges'][$name] = $values($gauge);
        }

        return $out;
    }

    /**
     * Port of RedisStoreTest::testOnlyOneOfManyContendersTakesAFreshClaim:
     * `contenders` coroutines, each on its own connection, race to take a
     * fresh key, `rounds` times; the number of winners. (The PHP test forks
     * processes; the dev image has no pcntl, and hooked coroutines race the
     * same way.)
     */
    private static function race(array $a, Session $s): int
    {
        $winners = 0;
        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        try {
            \Swoole\Coroutine\run(function () use ($a, $s, &$winners): void {
                for ($round = 0; $round < $a['rounds']; ++$round) {
                    $key = "{$a['key']}:{$round}";
                    $group = new \Swoole\Coroutine\WaitGroup();
                    $stores = [];
                    for ($contender = 0; $contender < $a['contenders']; ++$contender) {
                        $stores[] = new RedisStore(self::redis($s), $key);
                    }
                    foreach ($stores as $contender => $store) {
                        $group->add();
                        go(function () use ($store, $round, $contender, $group, &$winners): void {
                            if ($store->swap(null, new Claim("token-{$round}-{$contender}", 100.0, null))) {
                                ++$winners;
                            }
                            $group->done();
                        });
                    }
                    $group->wait();
                }
            });
        } finally {
            \Swoole\Runtime::enableCoroutine(0);
        }

        return $winners;
    }

    private static function redis(Session $s, string $name = ''): \Redis
    {
        $url = parse_url($s->service('redis'));
        $redis = new \Redis();
        $redis->connect($url['host'] ?? 'redis', $url['port'] ?? 6379);
        if ($name !== '') {
            $redis->client('setname', $name);
        }

        return $redis;
    }

    /** Drops the named connections from the server's side; how many there were. */
    private static function kill(Session $s, string $name): int
    {
        $redis = self::redis($s);
        $killed = 0;
        $clients = $redis->client('list');
        foreach (\is_array($clients) ? $clients : [] as $client) {
            if (\is_array($client) && ($client['name'] ?? '') === $name && isset($client['addr'])) {
                $redis->client('kill', $client['addr']);
                ++$killed;
            }
        }

        return $killed;
    }

    private static function clock(array $a, Session $s): TestClock
    {
        $clock = $s->get($a['clock']);

        return $clock instanceof TestClock ? $clock : throw new Fault('not a clock');
    }

    private static function store(array $a, Session $s): Store
    {
        $store = $s->get($a['store']);

        return $store instanceof Store ? $store : throw new Fault('not a store');
    }

    private static function source(array $a, Session $s): Script
    {
        $source = $s->get($a['source']);

        return $source instanceof Script ? $source : throw new Fault('not a source');
    }

    private static function get(array $a, Session $s): Instance
    {
        $instance = $s->get($a['scheduler']);

        return $instance instanceof Instance ? $instance : throw new Fault('not a scheduler');
    }
}

/** A scheduler with what the case observes of it. */
final class Instance
{
    public function __construct(
        public Scheduler $scheduler,
        public Store $store,
        public TestClock $clock,
        public ?TestTelemetry $telemetry,
        public \ArrayObject $errors,
    ) {
    }
}

/**
 * A source scripted by the case: `rows` (or `phases`, rows by clock moment),
 * `fail` (the listing throws after `after` rows), `poison` (make throws for
 * those ids), the trigger of each row (`triggers[id]`, else `trigger`, else
 * the row's data), `payload` (`id`, `data` or null) and `steal` (once the
 * clock reaches `at`, a listing hands the claim to `token` and stops the
 * scheduler, as a successor taking over would).
 */
class Script implements Source
{
    public int $made = 0;

    public ?Scheduler $scheduler = null;

    private array $spec;

    public function __construct(array $spec, private Session $session)
    {
        $this->spec = $spec;
    }

    public function set(array $a): void
    {
        foreach (['rows', 'since', 'fail', 'phases'] as $key) {
            if (\array_key_exists($key, $a)) {
                $this->spec[$key] = $a[$key];
            }
        }
    }

    public function snapshot(): iterable
    {
        if (isset($this->spec['steal'])) {
            $this->steal($this->spec['steal']);
        }
        $rows = $this->spec['rows'] ?? [];
        foreach ($this->spec['phases'] ?? [] as $phase) {
            if (!isset($phase['from']) || $this->now() >= Adapter::moment($phase['from'])) {
                $rows = $phase['rows'];
            }
        }
        $fail = $this->spec['fail'] ?? null;
        $yielded = 0;
        foreach (self::rows($rows) as $row) {
            if ($fail !== null && $yielded >= ($fail['after'] ?? 0)) {
                throw Adapter::error($fail);
            }
            yield $row;
            ++$yielded;
        }
        if ($fail !== null) {
            throw Adapter::error($fail);
        }
    }

    public function make(Row $row): Entry
    {
        ++$this->made;
        if (isset($this->spec['poison'][$row->id])) {
            throw Adapter::error($this->spec['poison'][$row->id]);
        }
        $trigger = Adapter::trigger($this->spec['triggers'][$row->id] ?? $this->spec['trigger'] ?? $row->data);

        return new Entry($trigger, match ($this->spec['payload'] ?? null) {
            'id' => $row->id,
            'data' => $row->data,
            default => null,
        });
    }

    /** @return iterable<Row> */
    protected static function rows(array $rows): iterable
    {
        foreach ($rows as $r) {
            if (isset($r['prefix'])) {
                for ($n = $r['from'] ?? 0; $n < $r['count']; ++$n) {
                    yield new Row($r['prefix'] . $n, $r['version'], $r['data'] ?? null);
                }
                continue;
            }
            yield new Row(
                $r['id'],
                $r['version'],
                $r['data'] ?? null,
                $r['active'] ?? true,
                isset($r['activeFrom']) ? Adapter::moment($r['activeFrom']) : null,
            );
        }
    }

    private function now(): \DateTimeImmutable
    {
        $clock = $this->session->get($this->spec['clock']);

        return $clock instanceof TestClock ? $clock->now() : throw new Fault('not a clock');
    }

    private function steal(array $steal): void
    {
        $store = $this->session->get($this->spec['store']);
        if (!$store instanceof Store) {
            throw new Fault('not a store');
        }
        $now = $this->now();
        if ($now >= Adapter::moment($steal['at']) && $store->load()?->token === $steal['from']) {
            $store->swap($steal['from'], new Claim($steal['token'], (float) $now->format('U.u') + $steal['lease'], null));
            $this->scheduler?->stop();
        }
    }
}

/** A scripted source with a change feed: `since` lists the changed rows. */
final class ChangesScript extends Script implements Changes
{
    private array $since;

    public function __construct(array $spec, Session $session)
    {
        parent::__construct($spec, $session);
        $this->since = $spec['since'];
    }

    public function set(array $a): void
    {
        parent::set($a);
        if (\array_key_exists('since', $a)) {
            $this->since = $a['since'];
        }
    }

    public function since(\DateTimeImmutable $moment): iterable
    {
        return self::rows($this->since);
    }
}
