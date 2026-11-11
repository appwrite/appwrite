<?php

namespace Tests\Compat\CircuitBreaker;

use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\CircuitBreaker\Adapter as Store;
use Utopia\CircuitBreaker\Adapter\Redis;
use Utopia\CircuitBreaker\Adapter\SwooleTable;
use Utopia\CircuitBreaker\CircuitBreaker;
use Utopia\Telemetry\Adapter\Test;
use Utopia\Telemetry\Counter;
use Utopia\Telemetry\Gauge;

/**
 * Maps tests/compat/circuit-breaker/spec.json operations onto utopia-php/circuit-breaker. Glue only.
 */
final class Adapter implements Base, Benchmarks
{
    /**
     * The callbacks a Test backend's observable gauge holds (an anonymous class).
     *
     * @return list<\Closure>
     */
    private static function callbacks(?object $gauge): array
    {
        $callbacks = $gauge === null ? [] : (get_object_vars($gauge)['callbacks'] ?? []);

        return \is_array($callbacks) ? array_values(array_filter($callbacks, static fn ($c) => $c instanceof \Closure)) : [];
    }

    public function benchmarks(): array
    {
        return [
            'call_closed' => ['A successful call through a closed in-process breaker', function (Session $s) {
                $breaker = new CircuitBreaker();

                return fn () => $breaker->call(open: fn () => 'fallback', close: fn () => 'ok');
            }],
            'call_open' => ['A call short-circuited by an open in-process breaker', function (Session $s) {
                $breaker = new CircuitBreaker();
                $breaker->trip();

                return fn () => $breaker->call(open: fn () => 'fallback', close: fn () => 'ok');
            }],
            'call_telemetry' => ['A successful call through a closed breaker recording telemetry', function (Session $s) {
                $breaker = new CircuitBreaker(telemetry: new Test());

                return fn () => $breaker->call(open: fn () => 'fallback', close: fn () => 'ok');
            }],
            'state' => ['Read the state of an in-process breaker', function (Session $s) {
                $breaker = new CircuitBreaker();

                return fn () => $breaker->getState();
            }],
        ];
    }

    public function operations(): array
    {
        return [
            'store.array' => fn (array $a, Session $s) => $s->handle(new ArrayStore(self::pairs($a['values'] ?? []))),
            'store.writes' => function (array $a, Session $s) {
                $store = $s->get($a['store']);

                return $store instanceof ArrayStore ? $store->writes : throw new Fault('not an array store');
            },
            'store.table' => fn (array $a, Session $s) => $s->handle(SwooleTable::createTable($a['size'] ?? 1024, $a['value_length'] ?? 255)),
            'store.swoole_table' => fn (array $a, Session $s) => $s->handle(new SwooleTable($s->get($a['table']), $a['prefix'] ?? 'breaker:')),
            'store.redis' => fn (array $a, Session $s) => $s->handle(new Redis(self::redis($s), $a['prefix'] ?? 'breaker:')),
            'store.get' => fn (array $a, Session $s) => self::store($a, $s)->get($a['key']),
            'store.set' => function (array $a, Session $s) {
                self::store($a, $s)->set($a['key'], $a['value']);

                return;
            },
            'store.increment' => fn (array $a, Session $s) => self::store($a, $s)->increment($a['key'], ...(isset($a['by']) ? ['by' => $a['by']] : [])),
            'store.delete' => function (array $a, Session $s) {
                self::store($a, $s)->delete($a['key']);

                return;
            },
            'telemetry.new' => fn (array $a, Session $s) => $s->handle(new RecordingTelemetry()),
            'telemetry.log' => fn (array $a, Session $s) => self::telemetry($a, $s)->log,
            'telemetry.observe' => fn (array $a, Session $s) => self::observe(self::telemetry($a, $s)),
            'telemetry.callbacks' => fn (array $a, Session $s) => array_map(null, array_keys(self::telemetry($a, $s)->observableGauges), array_map(static fn ($g) => \count(self::callbacks($g)), array_values(self::telemetry($a, $s)->observableGauges))) ?: [],
            'breaker.new' => fn (array $a, Session $s) => $s->handle(self::breaker($a, $s)),
            'breaker.discarded' => function (array $a, Session $s) {
                $breaker = self::breaker($a, $s);
                $reference = \WeakReference::create($breaker);
                unset($breaker);
                gc_collect_cycles();

                return $reference->get() === null;
            },
            'breaker.set_telemetry' => function (array $a, Session $s) {
                self::of($a, $s)->setTelemetry(self::telemetry($a, $s));

                return;
            },
            'breaker.call' => fn (array $a, Session $s) => self::call(self::of($a, $s), $a, $s),
            'breaker.state' => fn (array $a, Session $s) => self::of($a, $s)->getState()->value,
            'breaker.is' => fn (array $a, Session $s) => [
                'open' => self::of($a, $s)->isOpen(),
                'closed' => self::of($a, $s)->isClosed(),
                'half_open' => self::of($a, $s)->isHalfOpen(),
            ],
            'breaker.failures' => fn (array $a, Session $s) => self::of($a, $s)->getFailureCount(),
            'breaker.successes' => fn (array $a, Session $s) => self::of($a, $s)->getSuccessCount(),
            'breaker.trip' => function (array $a, Session $s) {
                self::of($a, $s)->trip();

                return;
            },
            'sleep' => function (array $a, Session $s) {
                usleep((int) ($a['ms'] * 1000));

                return;
            },
            // A breaker made like breaker.new, then each of `steps` (succeed,
            // fail, probe: a half-open callback, trip) with the state, failure
            // and success counts after it.
            'breaker.scenario' => function (array $a, Session $s) {
                $breaker = self::breaker($a, $s);
                $out = [];
                foreach ($a['steps'] as $step) {
                    $result = match ($step) {
                        'trip' => $breaker->trip(),
                        'probe' => $breaker->call(open: static fn () => 'fallback', close: static fn () => 'closed', halfOpen: static fn () => 'probe'),
                        'fail' => $breaker->call(open: static fn () => 'fallback', close: static fn () => throw new \RuntimeException('failed')),
                        default => $breaker->call(open: static fn () => 'fallback', close: static fn () => 'ok'),
                    };
                    $out[] = [$result, $breaker->getState()->value, $breaker->getFailureCount(), $breaker->getSuccessCount()];
                }

                return $out;
            },
            // Calls with failing or succeeding callbacks, `count` times each, in order.
            'breaker.calls' => function (array $a, Session $s) {
                $breaker = self::of($a, $s);
                $out = [];
                foreach ($a['outcomes'] as $outcome) {
                    $out[] = $breaker->call(
                        open: static fn () => 'fallback',
                        close: $outcome === 'fail' ? static fn () => throw new \RuntimeException('failed') : static fn () => 'ok',
                    );
                }

                return array_count_values($out);
            },
        ];
    }

    /**
     * Runs one call: `close` (and `half_open` when given) are callbacks
     * `{result}` or `{throw}` with optional `during` actions run inside them
     * first (nested calls, state reads, gauge observations), whose results
     * are reported as `during`.
     */
    private static function call(CircuitBreaker $breaker, array $a, Session $s): mixed
    {
        $during = [];
        $callback = static function (array $spec) use (&$during, $s, $breaker): \Closure {
            return static function () use ($spec, &$during, $s, $breaker) {
                foreach ($spec['during'] ?? [] as $action) {
                    $during[] = match ($action['op']) {
                        'call' => self::call(isset($action['breaker']) ? self::of($action, $s) : $breaker, $action, $s),
                        'state' => $breaker->getState()->value,
                        'observe' => self::observe(self::telemetry($action, $s)),
                        default => throw new Fault('unknown action'),
                    };
                }
                if (isset($spec['throw'])) {
                    throw new \RuntimeException($spec['throw']);
                }

                return $spec['result'] ?? null;
            };
        };
        $result = $breaker->call(
            open: static fn () => $a['fallback'] ?? 'fallback',
            close: $callback($a['close'] ?? ['result' => 'closed']),
            halfOpen: isset($a['half_open']) ? $callback($a['half_open']) : null,
        );

        return $during === [] ? $result : ['result' => $result, 'during' => $during];
    }

    /**
     * Every observable gauge's series: [[gauge, [[breaker name, value], ...]], ...].
     */
    private static function observe(Test $telemetry): array
    {
        $observed = [];
        foreach ($telemetry->observableGauges as $name => $gauge) {
            $series = [];
            foreach (self::callbacks($gauge) as $callback) {
                $callback(function (float|int $value, iterable $attributes = []) use (&$series): void {
                    $attributes = iterator_to_array($attributes);
                    $series[] = [$attributes['circuit_breaker.name'], $value];
                });
            }
            $observed[] = [$name, $series];
        }

        return $observed;
    }

    private static function breaker(array $a, Session $s): CircuitBreaker
    {
        $named = [];
        foreach (['timeout' => 'timeout', 'success_threshold' => 'successThreshold', 'key' => 'key', 'metric_prefix' => 'metricPrefix', 'window' => 'window', 'failure_ratio' => 'failureRatio', 'minimum_throughput' => 'minimumThroughput', 'half_open_permitted_calls' => 'halfOpenPermittedCalls'] as $arg => $param) {
            if (\array_key_exists($arg, $a)) {
                $named[$param] = $arg === 'failure_ratio' ? (float) $a[$arg] : $a[$arg];
            }
        }
        if (isset($a['store'])) {
            $named['cache'] = self::store($a, $s);
        }
        if (isset($a['telemetry'])) {
            $named['telemetry'] = self::telemetry($a, $s);
        }

        return new CircuitBreaker(...$named);
    }

    /**
     * @param list<array{0: string, 1: int|string}> $pairs
     * @return array<string, int|string>
     */
    private static function pairs(array $pairs): array
    {
        $out = [];
        foreach ($pairs as [$k, $v]) {
            $out[$k] = $v === '$opened_ago_10' ? time() - 10 : $v;
        }

        return $out;
    }

    private static function redis(Session $s): \Redis
    {
        $url = parse_url($s->service('redis'));
        $redis = new \Redis();
        $redis->connect($url['host'] ?? 'redis', $url['port'] ?? 6379);

        return $redis;
    }

    private static function store(array $a, Session $s): Store
    {
        $store = $s->get($a['store']);

        return $store instanceof Store ? $store : throw new Fault('not a store');
    }

    private static function telemetry(array $a, Session $s): RecordingTelemetry
    {
        $t = $s->get($a['telemetry']);

        return $t instanceof RecordingTelemetry ? $t : throw new Fault('not a telemetry');
    }

    private static function of(array $a, Session $s): CircuitBreaker
    {
        $b = $s->get($a['breaker']);

        return $b instanceof CircuitBreaker ? $b : throw new Fault('not a breaker');
    }
}

/**
 * The tests' array adapter: values in memory; every write logged as [op, key, value].
 */
final class ArrayStore implements Store
{
    /** @var list<array{0: string, 1: string, 2: int|string|null}> */
    public array $writes = [];

    /** @param array<string, int|string> $values */
    public function __construct(private array $values = [])
    {
    }

    public function get(string $key): int|string|null
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, int|string $value): void
    {
        $this->writes[] = ['set', $key, $value];
        $this->values[$key] = $value;
    }

    public function increment(string $key, int $by = 1): int
    {
        $value = ((int) ($this->values[$key] ?? 0)) + $by;
        $this->writes[] = ['increment', $key, $by];
        $this->values[$key] = $value;

        return $value;
    }

    public function delete(string $key): void
    {
        $this->writes[] = ['delete', $key, null];
        unset($this->values[$key]);
    }
}

/**
 * The Test backend, also logging every counter and gauge data point as
 * [kind, name, value, attributes] (gauge values, timestamps, as 0).
 */
final class RecordingTelemetry extends Test
{
    /** @var list<array{0: string, 1: string, 2: float|int, 3: array<mixed>}> */
    public array $log = [];

    public function keep(string $kind, string $name, float|int $value, iterable $attributes): void
    {
        $pairs = [];
        foreach ($attributes as $k => $v) {
            $pairs[] = [$k, $v];
        }
        $this->log[] = [$kind, $name, $value, $pairs];
    }

    public function createCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): Counter
    {
        return new class ($this, $name, parent::createCounter($name, $unit, $description, $advisory)) extends Counter {
            public function __construct(private RecordingTelemetry $owner, private string $name, private Counter $inner)
            {
            }

            public function add(float|int $amount, iterable $attributes = []): void
            {
                $this->inner->add($amount, $attributes);
                $this->owner->keep('counter', $this->name, $amount, $attributes);
            }
        };
    }

    public function createGauge(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): Gauge
    {
        return new class ($this, $name, parent::createGauge($name, $unit, $description, $advisory)) extends Gauge {
            public function __construct(private RecordingTelemetry $owner, private string $name, private Gauge $inner)
            {
            }

            public function record(float|int $amount, iterable $attributes = []): void
            {
                $this->inner->record($amount, $attributes);
                $this->owner->keep('gauge', $this->name, 0, $attributes);
            }
        };
    }
}
