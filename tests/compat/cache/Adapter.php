<?php

namespace Tests\Compat\Cache;

use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Fault;
use Tests\Compat\PhpStd\Typed;
use Tests\Compat\Session;
use Utopia\Cache\Adapter as CacheAdapter;
use Utopia\Cache\Adapter\CircuitBreaker;
use Utopia\Cache\Adapter\Filesystem;
use Utopia\Cache\Adapter\Hazelcast;
use Utopia\Cache\Adapter\Memcached;
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Adapter\Pool;
use Utopia\Cache\Adapter\Redis;
use Utopia\Cache\Adapter\Redis\Client;
use Utopia\Cache\Adapter\Redis\ConnectionError;
use Utopia\Cache\Adapter\Redis\ConnectionException;
use Utopia\Cache\Adapter\Redis\Multiplexing;
use Utopia\Cache\Adapter\Redis\NoScript;
use Utopia\Cache\Adapter\Redis\RedisError;
use Utopia\Cache\Adapter\RedisCluster;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\Cache\Codec;
use Utopia\Cache\Codec\Igbinary;
use Utopia\Cache\Codec\Json;
use Utopia\Cache\Envelope;
use Utopia\Cache\Feature;
use Utopia\CircuitBreaker\CircuitBreaker as Breaker;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Pool as UtopiaPool;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\Test as TestTelemetry;
use Utopia\Telemetry\Counter;
use Utopia\Telemetry\Histogram;
use Utopia\Telemetry\UpDownCounter;

use function Swoole\Coroutine\run;

require_once __DIR__ . '/../php-std/Typed.php';

/**
 * Maps tests/compat/cache/spec.json operations onto utopia-php/cache. Glue only.
 */
final class Adapter implements Base, Benchmarks
{
    /** A user document as Appwrite caches it: system attributes, permissions, nested preferences, targets and memberships (about 2 KB of JSON). */
    private const DOCUMENT = <<<'JSON'
        {"$id":"6650f2b1c3a4e5d6f7a8","$sequence":48213,"$createdAt":"2026-05-24T10:15:30.123+00:00","$updatedAt":"2026-09-30T08:02:11.456+00:00","$permissions":["read(\"user:6650f2b1c3a4e5d6f7a8\")","update(\"user:6650f2b1c3a4e5d6f7a8\")","delete(\"user:6650f2b1c3a4e5d6f7a8\")"],"name":"Ada Lovelace","email":"ada.lovelace@example.com","phone":"+14155550123","status":true,"labels":["admin","beta"],"passwordUpdate":"2026-05-24T10:15:30.123+00:00","registration":"2026-05-24T10:15:30.123+00:00","emailVerification":true,"phoneVerification":false,"mfa":false,"accessedAt":"2026-10-10T19:44:02.000+00:00","prefs":{"theme":"dark","language":"en-GB","timezone":"Europe/London","notifications":{"email":true,"push":false,"digest":"weekly"},"dashboard":{"pinned":["functions","databases","storage"],"density":0.75}},"targets":[{"$id":"6650f2b1d4e5f6a7b8c9","$createdAt":"2026-05-24T10:15:30.123+00:00","$updatedAt":"2026-05-24T10:15:30.123+00:00","name":"","userId":"6650f2b1c3a4e5d6f7a8","providerId":null,"providerType":"email","identifier":"ada.lovelace@example.com","expired":false},{"$id":"6650f2b1e5f6a7b8c9d0","$createdAt":"2026-06-02T14:20:00.000+00:00","$updatedAt":"2026-06-02T14:20:00.000+00:00","name":"Pixel 9","userId":"6650f2b1c3a4e5d6f7a8","providerId":"fcm","providerType":"push","identifier":"dGhpcyBpcyBhIGZha2UgZmNtIHRva2VuIGZvciBiZW5jaG1hcmtz","expired":false}],"memberships":[{"teamId":"team_core","roles":["owner"],"confirm":true},{"teamId":"team_docs","roles":["developer","reviewer"],"confirm":true}],"quota":{"storage":1073741824,"used":52428800,"ratio":0.048828125}}
        JSON;

    /** The key the in-process benchmarks cache the document under. */
    private const KEY = 'user:6650f2b1c3a4e5d6f7a8';

    /** The instant the envelope benchmark writes at, so both runtimes encode the same bytes. */
    private const TIME = 1_790_000_000;

    public function benchmarks(): array
    {
        $doc = json_decode(self::DOCUMENT, true, flags: JSON_THROW_ON_ERROR);
        // The `cache-redis` service as the cases use it, a key in this run's
        // namespace. The setup saves it with a TTL, which the timed HSETs
        // keep, so Redis drops it after the run.
        $redis = function (string $name, Session $s, bool $seed) use ($doc): array {
            $cache = new Cache(new Redis(self::raw([], $s)));
            $key = "{$s->ns}:bench:{$name}";
            if ($seed) {
                $cache->save($key, $doc, '', 600);
            }

            return [$cache, $key];
        };

        return [
            'memory_load' => ['Load a cached user document from the in-memory adapter through the cache facade', function (Session $s) use ($doc) {
                $cache = new Cache(new Memory());
                $cache->save(self::KEY, $doc);

                return fn () => $cache->load(self::KEY, 3600);
            }],
            'memory_save' => ['Save a user document to the in-memory adapter through the cache facade', function (Session $s) use ($doc) {
                $cache = new Cache(new Memory());

                return fn () => $cache->save(self::KEY, $doc);
            }],
            'redis_load' => ['Load a cached user document from Redis: one HGET and the JSON envelope decoded', function (Session $s) use ($redis) {
                [$cache, $key] = $redis('redis_load', $s, true);

                return fn () => $cache->load($key, 3600);
            }],
            'redis_miss' => ['Load a key Redis does not hold: one HGET, nothing to decode', function (Session $s) use ($redis) {
                [$cache, $key] = $redis('redis_miss', $s, false);

                return fn () => $cache->load($key, 3600);
            }],
            'redis_save' => ['Save a user document to Redis: the JSON envelope encoded and one HSET', function (Session $s) use ($redis, $doc) {
                [$cache, $key] = $redis('redis_save', $s, true);

                return fn () => $cache->save($key, $doc);
            }],
            'envelope' => ['Encode a user document into a JSON cache envelope and decode it back', function (Session $s) use ($doc) {
                $envelope = new Envelope(new Json());

                return fn () => $envelope->decode($envelope->encode($doc, self::TIME), 3600, self::TIME);
            }],
        ];
    }

    public function operations(): array
    {
        $ops = [
            'memory.new' => fn (array $a, Session $s) => $s->handle(new Memory()),
            'none.new' => fn (array $a, Session $s) => $s->handle(new None()),
            'filesystem.new' => fn (array $a, Session $s) => $s->handle(new Filesystem($a['path'])),
            'filesystem.path' => fn (array $a, Session $s) => (new Filesystem($a['path']))->getPath($a['key']),
            'filesystem.stream' => function (array $a, Session $s) {
                $stream = (new Filesystem($a['path'], true))->load($a['key'], $a['ttl']);
                if (!\is_resource($stream)) {
                    return $stream;
                }
                $contents = stream_get_contents($stream);
                fclose($stream);

                return $contents;
            },
            'redis.new' => fn (array $a, Session $s) => $s->handle(self::redisAdapter($a, $s)),
            'memcached.new' => function (array $a, Session $s) {
                $adapter = new Memcached(self::memcached($s->service($a['service'] ?? 'cache-memcached')));
                self::retries($adapter, $a);

                return $s->handle($adapter);
            },
            'hazelcast.new' => function (array $a, Session $s) {
                $adapter = new Hazelcast(self::memcached($s->service($a['service'] ?? 'cache-hazelcast')), self::codec($a));
                self::retries($adapter, $a);

                return $s->handle($adapter);
            },
            'cluster.new' => function (array $a, Session $s) {
                $seeds = explode(',', substr($s->service('cache-cluster'), \strlen('redis-cluster://')));
                $adapter = new RedisCluster(new \RedisCluster(null, $seeds, 1.5, 1.5), $seeds, $a['name'] ?? null, codec: self::codec($a));
                self::retries($adapter, $a);

                return $s->handle($adapter);
            },
            'cluster.info' => function (array $a, Session $s) {
                $adapter = self::adapter($a['adapter'], $s);

                return $adapter instanceof RedisCluster
                    ? ['name' => $adapter->getClusterName(), 'seeds' => $adapter->getSeeds()]
                    : throw new Fault('not a cluster adapter');
            },
            'sharding.new' => fn (array $a, Session $s) => $s->handle(new Sharding(array_map(fn ($h) => self::adapter($h, $s), $a['adapters']))),
            'pool.new' => function (array $a, Session $s) {
                $path = $a['path'];
                $pool = new UtopiaPool(new Stack(), 'compat', $a['size'] ?? 10, fn (): Filesystem => new Filesystem($path), timeout: 0.0);

                return $s->handle(new Pool($pool));
            },
            'circuit_breaker.new' => fn (array $a, Session $s) => $s->handle(new CircuitBreaker(
                self::adapter($a['adapter'], $s),
                new Breaker(...(isset($a['minimum_throughput']) ? ['minimumThroughput' => $a['minimum_throughput']] : [])),
            )),
            'failing.new' => fn (array $a, Session $s) => $s->handle(new FailingAdapter()),
            'counting_failing.new' => fn (array $a, Session $s) => $s->handle(new CountingFailingAdapter()),
            'counting_failing.loads' => function (array $a, Session $s) {
                $adapter = $s->get($a['adapter']);

                return $adapter instanceof CountingFailingAdapter ? $adapter->loads : throw new Fault('not a counting adapter');
            },
            'probe.new' => fn (array $a, Session $s) => $s->handle(new ProbeAdapter()),
            'probe.calls' => function (array $a, Session $s) {
                $adapter = $s->get($a['adapter']);
                if (!$adapter instanceof ProbeAdapter) {
                    throw new Fault('not a probe adapter');
                }

                return ['calls' => $adapter->calls, 'same' => $adapter->telemetry === $s->get($a['telemetry'])];
            },
            'null_load.new' => fn (array $a, Session $s) => $s->handle(new NullLoadAdapter()),
            'telemetry.new' => fn (array $a, Session $s) => $s->handle(new RecordingTelemetry()),
            'telemetry.dump' => function (array $a, Session $s) {
                $telemetry = $s->get($a['telemetry']);

                return $telemetry instanceof RecordingTelemetry ? $telemetry->dump() : throw new Fault('not a telemetry');
            },
            'cache.new' => fn (array $a, Session $s) => $s->handle(new Cache(self::adapter($a['adapter'], $s))),
            'cache.set_case_sensitivity' => fn (array $a, Session $s) => self::cache($a, $s)->setCaseSensitivity($a['value']),
            'cache.case_sensitive' => fn (array $a, Session $s) => self::cache($a, $s)->caseSensitive,
            'cache.set_telemetry' => function (array $a, Session $s) {
                $telemetry = $s->get($a['telemetry']);
                self::cache($a, $s)->setTelemetry($telemetry instanceof Telemetry ? $telemetry : throw new Fault('not a telemetry'));

                return;
            },
            'adapter.set_telemetry' => function (array $a, Session $s) {
                $telemetry = $s->get($a['telemetry']);
                $adapter = self::adapter($a['adapter'], $s);
                if (!$adapter instanceof Feature\Telemetry) {
                    throw new Fault('adapter has no telemetry');
                }
                $adapter->setTelemetry($telemetry instanceof Telemetry ? $telemetry : throw new Fault('not a telemetry'));

                return;
            },
            'adapter.name' => fn (array $a, Session $s) => self::adapter($a['adapter'], $s)->getName($a['key'] ?? null),
            'adapter.retry' => function (array $a, Session $s) {
                $adapter = self::adapter($a['adapter'], $s);

                return $adapter instanceof Feature\Retryable
                    ? ['max' => $adapter->getMaxRetries(), 'delay' => $adapter->getRetryDelay()]
                    : throw new Fault('not retryable');
            },
            'adapter.grace' => function (array $a, Session $s) {
                $adapter = self::adapter($a['adapter'], $s);

                return $adapter instanceof Redis || $adapter instanceof Multiplexing
                    ? $adapter->getLeaseGraceWindow()
                    : throw new Fault('not leasable');
            },
            'multiplexing.run' => fn (array $a, Session $s) => self::multiplexing($a, $s),

            'codec.encode' => fn (array $a, Session $s) => self::codecOf($a['codec'])->encode(Typed::decode($a['value'])),
            'codec.decode' => fn (array $a, Session $s) => Typed::dump(self::codecOf($a['codec'])->decode($a['bytes'])),
            'envelope.encode' => fn (array $a, Session $s) => self::envelope($a)->encode(Typed::decode($a['data']), $a['time']),
            'envelope.decode' => fn (array $a, Session $s) => Typed::dump(self::envelope($a)->decode($a['value'], $a['ttl'], $a['now'])),
            'envelope.touch' => fn (array $a, Session $s) => self::envelope($a)->touch($a['value'], $a['time']),

            'resp.encode' => fn (array $a, Session $s) => Client::encode($a['args']),
            'resp.parse' => function (array $a, Session $s) {
                $offset = $a['offset'] ?? 0;
                $value = Client::parse($a['buffer'], $offset);

                return ['value' => self::reply($value), 'offset' => $offset];
            },
            'resp.unwrap' => fn (array $a, Session $s) => self::reply(Client::unwrap(self::frame($a['value']))),
            'noscript.matches' => fn (array $a, Session $s) => NoScript::matches($a['error']),
            'noscript.from' => function (array $a, Session $s) {
                $signal = NoScript::from(($a['cause'] ?? false) ? new \RedisException($a['message']) : $a['message']);

                return ['class' => $signal::class, 'message' => $signal->getMessage()];
            },

            // Fixtures: direct Redis access, as the PHP tests use.
            'raw.hget' => fn (array $a, Session $s) => self::raw($a, $s)->hGet($a['key'], $a['field']),
            'raw.hset' => fn (array $a, Session $s) => self::raw($a, $s)->hSet($a['key'], $a['field'], $a['value']),
            'raw.hexists' => fn (array $a, Session $s) => (bool) self::raw($a, $s)->hExists($a['key'], $a['field']),
            'raw.ttl' => function (array $a, Session $s) {
                $ttl = self::raw($a, $s)->ttl($a['key']);

                return $ttl > 0 && $ttl <= ($a['at_most'] ?? PHP_INT_MAX) ? 'positive' : $ttl;
            },
            'raw.script_flush' => fn (array $a, Session $s) => (bool) self::raw($a, $s)->script('flush'),
            'raw.tomb' => function (array $a, Session $s) {
                $redis = self::raw($a, $s);
                $time = $redis->time();
                $micros = ((int) $time[0] + $a['seconds']) * 1000000;

                return $redis->hSet($a['key'], '__utopia_tomb__', (string) $micros);
            },
            'raw.json_decodes' => fn (array $a, Session $s) => json_decode($a['bytes']) !== null,
            'fuzz.roundtrip' => function (array $a, Session $s) {
                $adapter = match ($a['adapter']) {
                    'memory' => new Memory(),
                    'redis' => self::redisAdapter([], $s),
                    'redis-igbinary' => self::redisAdapter(['codec' => 'igbinary'], $s),
                    'filesystem' => new Filesystem('/tmp/compat-fs/' . $s->ns . '/fuzz'),
                    'memcached' => new Memcached(self::memcached($s->service('cache-memcached'))),
                    'hazelcast' => new Hazelcast(self::memcached($s->service('cache-hazelcast'))),
                    default => throw new Fault('unknown adapter'),
                };
                $cache = new Cache($adapter);
                $key = $s->ns . ':' . $a['key'];
                $hash = $a['hash'];
                $data = Typed::decode($a['data']);
                $steps = [
                    fn () => $cache->save($key, $data, $hash) !== false,
                    fn () => $cache->load($key, 60, $hash),
                    fn () => $cache->touch($key, $hash),
                    fn () => $cache->load($key, 60, $hash),
                    fn () => $cache->list($key),
                    fn () => $cache->purge($key, $hash),
                    fn () => $cache->load($key, 60, $hash),
                ];
                $results = [];
                foreach ($steps as $run) {
                    try {
                        $results[] = Typed::dump($run());
                    } catch (\Throwable $e) {
                        $results[] = ['$error' => ['class' => $e::class, 'message' => $e->getMessage()]];
                    }
                }

                return $results;
            },
            'sleep' => function (array $a, Session $s) {
                usleep((int) ($a['seconds'] * 1000000));

                return;
            },
        ];

        foreach (self::METHODS as $op) {
            $ops['cache.' . $op] = fn (array $a, Session $s) => self::call(self::cache($a, $s), $op, $a);
            $ops['adapter.' . $op] = fn (array $a, Session $s) => self::call(self::adapter($a['adapter'], $s), $op, $a);
        }

        return $ops;
    }

    private const array METHODS = ['load', 'save', 'touch', 'list', 'purge', 'flush', 'ping', 'size', 'load_many', 'save_many', 'generation', 'save_with_lease'];

    /**
     * One cache or adapter method with the case's arguments.
     *
     * @param array<string, mixed> $a
     */
    private static function call(Cache|CacheAdapter $target, string $op, array $a): mixed
    {
        $result = match ($op) {
            'load' => $target->load($a['key'], $a['ttl'], $a['hash'] ?? ''),
            'save' => $target->save($a['key'], Typed::decode($a['data']), $a['hash'] ?? '', $a['ttl'] ?? 0),
            'touch' => $target->touch($a['key'], $a['hash'] ?? ''),
            'list' => $target->list($a['key']),
            'purge' => $target->purge($a['key'], $a['hash'] ?? ''),
            'flush' => $target->flush(),
            'ping' => $target->ping(),
            'size' => $target->getSize(),
            'load_many' => $target instanceof Cache
                ? $target->loadMany($a['key'], $a['ttl'], $a['fields'] ?? [])
                : ($target instanceof Feature\Batchable ? $target->loadMany($a['key'], $a['fields'] ?? [], $a['ttl']) : []),
            'save_many' => $target instanceof Cache
                ? $target->saveMany($a['key'], self::fields($a['data']), $a['ttl'] ?? 0)
                : ($target instanceof Feature\Batchable ? $target->saveMany($a['key'], self::fields($a['data']), $a['ttl'] ?? 0) : false),
            'generation' => $target instanceof Cache
                ? $target->getGeneration($a['key'])
                : ($target instanceof Feature\Leasable ? $target->getGeneration($a['key']) : '0'),
            'save_with_lease' => $target instanceof Cache
                ? $target->saveWithLease($a['key'], Typed::decode($a['data']), $a['hash'], $a['generation'])
                : ($target instanceof Feature\Leasable
                    ? $target->saveWithLease($a['key'], Typed::decode($a['data']), $a['hash'], $a['generation'])
                    : $target->save($a['key'], Typed::decode($a['data']), $a['hash'])),
            default => throw new Fault("unknown method {$op}"),
        };

        // Saves return the data on success: report that they stored.
        if (\in_array($op, ['save', 'save_many', 'save_with_lease'], true)) {
            return $result !== false;
        }
        if ($op === 'load_many') {
            return (object) array_map(Typed::dump(...), $result);
        }

        return Typed::dump($result);
    }

    /**
     * @return array<string, mixed>
     */
    private static function fields(mixed $data): array
    {
        $fields = [];
        foreach ($data as $pair) {
            $fields[$pair[0]] = Typed::decode($pair[1]);
        }

        return $fields;
    }

    /**
     * Runs `steps` against a Cache over a fresh Multiplexing adapter inside
     * one coroutine scheduler, returning each step's result or error.
     *
     * @param array<string, mixed> $a
     * @return list<mixed>
     */
    private static function multiplexing(array $a, Session $s): array
    {
        $results = [];
        $url = parse_url($s->service($a['service'] ?? 'cache-redis'));
        run(function () use ($a, $url, &$results): void {
            $o = $a['options'] ?? [];
            $o = $o instanceof \stdClass ? [] : $o;
            try {
                $adapter = new Multiplexing(
                    $url['host'] ?? 'redis',
                    $url['port'] ?? 6379,
                    timeout: (float) ($o['timeout'] ?? 1.0),
                    readTimeout: (float) ($o['read_timeout'] ?? 0.25),
                    dbIndex: (int) ($o['db'] ?? ltrim($url['path'] ?? '', '/')),
                    livenessTimeout: (float) ($o['liveness_timeout'] ?? 5.0),
                    codec: self::codec($o),
                    idleGrace: (float) ($o['idle_grace'] ?? 1.0),
                );
            } catch (\Throwable $e) {
                $results[] = ['$error' => ['class' => $e::class, 'message' => $e->getMessage()]];

                return;
            }
            if (isset($o['grace'])) {
                $adapter->setLeaseGraceWindow($o['grace']);
            }
            $cache = new Cache($adapter);
            if ($o['case_sensitive'] ?? false) {
                $cache->setCaseSensitivity(true);
            }
            try {
                foreach ($a['steps'] as $step) {
                    $args = $step['args'] ?? [];
                    $step['args'] = $args instanceof \stdClass ? [] : $args;
                    try {
                        if ($step['op'] === 'sleep') {
                            \Swoole\Coroutine::sleep((float) $step['args']['seconds']);
                            $results[] = null;
                        } elseif ($step['op'] === 'load_equals') {
                            $a = $step['args'];
                            $results[] = $cache->load($a['key'], $a['ttl'], $a['hash']) == Typed::decode($a['data']);
                        } elseif ($step['op'] === 'concurrent') {
                            $results[] = self::concurrent($cache, $step['args']);
                        } else {
                            $results[] = self::call($cache, $step['op'], $step['args'] ?? []);
                        }
                    } catch (\Throwable $e) {
                        $results[] = ['$error' => ['class' => $e::class, 'message' => $e->getMessage()]];
                    }
                }
            } finally {
                $adapter->disconnect();
            }
        });

        return $results;
    }

    /**
     * `count` coroutines each saving and loading their own key; the number
     * that read back what they wrote.
     *
     * @param array<string, mixed> $a
     */
    private static function concurrent(Cache $cache, array $a): int
    {
        $ok = 0;
        $group = new \Swoole\Coroutine\WaitGroup();
        for ($i = 0; $i < $a['count']; $i++) {
            $group->add();
            \Swoole\Coroutine::create(function () use ($cache, $a, $i, &$ok, $group): void {
                try {
                    $key = $a['prefix'] . $i;
                    $value = ['i' => $i, 'text' => 'café 🚀 row ' . $i];
                    if ($cache->save($key, $value, $key) === $value && $cache->load($key, 60, $key) == $value) {
                        $ok++;
                    }
                } finally {
                    $group->done();
                }
            });
        }
        $group->wait();

        return $ok;
    }

    private static function reply(mixed $value): mixed
    {
        if ($value === Client::INCOMPLETE) {
            return ['$incomplete' => true];
        }
        if ($value instanceof RedisError) {
            return ['$redis_error' => $value->exception->getMessage()];
        }
        if (\is_array($value)) {
            return array_map(self::reply(...), $value);
        }

        return $value;
    }

    private static function frame(mixed $value): mixed
    {
        if (\is_array($value) && isset($value['$redis_error'])) {
            return new RedisError(new \RedisException($value['$redis_error']));
        }
        if (\is_array($value) && isset($value['$connection_error'])) {
            return new ConnectionError(new ConnectionException($value['$connection_error']));
        }
        if (\is_array($value)) {
            return array_map(self::frame(...), $value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $a
     */
    private static function redisAdapter(array $a, Session $s): Redis
    {
        $adapter = new Redis(self::raw($a, $s), self::codec($a));
        self::retries($adapter, $a);
        if (isset($a['grace'])) {
            $adapter->setLeaseGraceWindow($a['grace']);
        }

        return $adapter;
    }

    /**
     * @param array<string, mixed> $a
     */
    private static function retries(Feature\Retryable $adapter, array $a): void
    {
        if (isset($a['retries'])) {
            $adapter->setMaxRetries($a['retries']);
        }
        if (isset($a['delay'])) {
            $adapter->setRetryDelay($a['delay']);
        }
    }

    /**
     * @param array<string, mixed> $a
     */
    private static function raw(array $a, Session $s): \Redis
    {
        $url = parse_url($s->service($a['service'] ?? 'cache-redis'));
        $redis = new \Redis();
        $redis->connect($url['host'] ?? 'redis', $url['port'] ?? 6379);
        $redis->select((int) ($a['db'] ?? ltrim($url['path'] ?? '', '/')));

        return $redis;
    }

    private static function memcached(string $service): \Memcached
    {
        $url = parse_url($service);
        $client = new \Memcached();
        $client->addServer($url['host'] ?? 'memcached', $url['port'] ?? 11211);

        return $client;
    }

    /**
     * @param array<string, mixed> $a
     */
    private static function codec(array $a): Codec
    {
        return self::codecOf($a['codec'] ?? 'json');
    }

    private static function codecOf(string $name): Codec
    {
        return match ($name) {
            'json' => new Json(),
            'igbinary' => new Igbinary(),
            default => throw new Fault("unknown codec {$name}"),
        };
    }

    /**
     * @param array<string, mixed> $a
     */
    private static function envelope(array $a): Envelope
    {
        return new Envelope(self::codecOf($a['codec'] ?? 'json'));
    }

    private static function adapter(mixed $handle, Session $s): CacheAdapter
    {
        $adapter = $s->get($handle);

        return $adapter instanceof CacheAdapter ? $adapter : throw new Fault('not an adapter');
    }

    /**
     * @param array<string, mixed> $a
     */
    private static function cache(array $a, Session $s): Cache
    {
        $cache = $s->get($a['cache']);

        return $cache instanceof Cache ? $cache : throw new Fault('not a cache');
    }
}

/**
 * CircuitBreakerTest's FailingAdapter: every operation throws.
 */
class FailingAdapter implements CacheAdapter
{
    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        throw new \RuntimeException('Cache failed.');
    }

    public function save(string $key, array|string $data, string $hash = '', int $ttl = 0): bool|string|array
    {
        throw new \RuntimeException('Cache failed.');
    }

    public function touch(string $key, string $hash = ''): bool
    {
        throw new \RuntimeException('Cache failed.');
    }

    public function list(string $key): array
    {
        throw new \RuntimeException('Cache failed.');
    }

    public function purge(string $key, string $hash = ''): bool
    {
        throw new \RuntimeException('Cache failed.');
    }

    public function flush(): bool
    {
        throw new \RuntimeException('Cache failed.');
    }

    public function ping(): bool
    {
        throw new \RuntimeException('Cache failed.');
    }

    public function getSize(): int
    {
        throw new \RuntimeException('Cache failed.');
    }

    public function getName(?string $key = null): string
    {
        return 'failing';
    }
}

/**
 * CircuitBreakerTest's CountingFailingAdapter: counts the loads that reached it.
 */
final class CountingFailingAdapter extends FailingAdapter
{
    public int $loads = 0;

    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        $this->loads++;

        return parent::load($key, $ttl, $hash);
    }
}

/**
 * TelemetryTest's adapter: a Memory adapter recording the telemetry it is handed.
 */
final class ProbeAdapter extends Memory implements Feature\Telemetry
{
    public ?Telemetry $telemetry = null;

    public int $calls = 0;

    public function setTelemetry(Telemetry $telemetry): void
    {
        $this->telemetry = $telemetry;
        $this->calls++;
    }
}

/**
 * LoadResultsTelemetryTest's adapter: every load returns null.
 */
final class NullLoadAdapter extends Memory
{
    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        return null;
    }
}

/**
 * The Test telemetry adapter, keeping each data point's attributes as the
 * load-results tests capture them.
 */
final class RecordingTelemetry extends TestTelemetry
{
    /** @var array<string, list<array{value: float|int, attributes: array<mixed>}>> */
    private array $points = [];

    /**
     * Keeps one data point of the instrument `$key` ("kind:name").
     *
     * @param iterable<mixed> $attributes
     */
    public function keep(string $key, float|int $value, iterable $attributes): void
    {
        $this->points[$key][] = ['value' => $value, 'attributes' => \is_array($attributes) ? $attributes : iterator_to_array($attributes)];
    }

    public function createCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): Counter
    {
        return new class ($this, 'counter:' . $name) extends Counter {
            public function __construct(private readonly RecordingTelemetry $owner, private readonly string $key)
            {
            }

            public function add(float|int $amount, iterable $attributes = []): void
            {
                $this->owner->keep($this->key, $amount, $attributes);
            }
        };
    }

    public function createUpDownCounter(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): UpDownCounter
    {
        return new class ($this, 'upDownCounter:' . $name) extends UpDownCounter {
            public function __construct(private readonly RecordingTelemetry $owner, private readonly string $key)
            {
            }

            public function add(float|int $amount, iterable $attributes = []): void
            {
                $this->owner->keep($this->key, $amount, $attributes);
            }
        };
    }

    public function createHistogram(string $name, ?string $unit = null, ?string $description = null, array $advisory = []): Histogram
    {
        return new class ($this, 'histogram:' . $name) extends Histogram {
            public function __construct(private readonly RecordingTelemetry $owner, private readonly string $key)
            {
            }

            public function record(float|int $amount, iterable $attributes = []): void
            {
                // Durations vary between runs: only that a point was recorded is compared.
                $this->owner->keep($this->key, 0, $attributes);
            }
        };
    }

    /**
     * Instruments that recorded, by kind and name, sorted.
     *
     * @return array<string, list<array{value: float|int, attributes: array<mixed>}>>
     */
    public function dump(): array
    {
        $points = $this->points;
        ksort($points);

        return $points;
    }
}
