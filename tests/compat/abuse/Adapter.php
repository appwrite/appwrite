<?php

namespace Tests\Compat\Abuse;

use Appwrite\AppwriteException;
use Appwrite\Client;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\Abuse\Adapter as Limit;
use Utopia\Abuse\Adapter\SlidingWindow;
use Utopia\Abuse\Adapter\TimeLimit;
use Utopia\Abuse\Adapter\TokenBucket;
use Utopia\Abuse\ReCaptcha;
use Utopia\Abuse\Result;
use Utopia\Abuse\Tests\TimeLimit\Memory;
use Utopia\Abuse\Tests\TimeLimit\Store;
use Utopia\Abuse\Tests\TokenBucket\Fixed;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory as MemoryDatabase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Pools\Adapter\Stack;
use Utopia\Pools\Pool;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

require_once __DIR__ . '/../../../packages/abuse/tests/TimeLimit/Store.php';
require_once __DIR__ . '/../../../packages/abuse/tests/TimeLimit/Memory.php';
require_once __DIR__ . '/../../../packages/abuse/tests/TokenBucket/Fixed.php';

/**
 * The time a clocked adapter reads; `null` reads the real clock.
 */
final class Clock
{
    public int|float|null $now = null;
}

/** A TimeLimit\RedisPool whose now() is a Clock. */
final readonly class ClockedTimeLimitPool extends TimeLimit\RedisPool
{
    public function __construct(string $key, int $limit, int $seconds, Pool $pool, private Clock $clock)
    {
        parent::__construct($key, $limit, $seconds, $pool);
    }

    #[\Override]
    protected function now(): int
    {
        return $this->clock->now === null ? parent::now() : (int) $this->clock->now;
    }
}

/** A SlidingWindow\RedisPool whose now() is a Clock. */
final readonly class ClockedSlidingWindowPool extends SlidingWindow\RedisPool
{
    public function __construct(string $key, int $limit, int $windowSize, int $ttl, Pool $pool, private Clock $clock)
    {
        parent::__construct($key, $limit, $windowSize, $ttl, $pool);
    }

    #[\Override]
    protected function now(): int
    {
        return $this->clock->now === null ? parent::now() : (int) $this->clock->now;
    }
}

/** A TokenBucket\RedisPool whose now() is a Clock. */
final readonly class ClockedTokenBucketPool extends TokenBucket\RedisPool
{
    public function __construct(string $key, int $tokens, float $refillRate, Pool $pool, private Clock $clock)
    {
        parent::__construct($key, $tokens, $refillRate, $pool);
    }

    #[\Override]
    protected function now(): float
    {
        return $this->clock->now === null ? parent::now() : (float) $this->clock->now;
    }
}

/**
 * A fake Appwrite TablesDB API in memory, for TimeLimit\Appwrite\TablesDB:
 * the same fake as `FakeTables` in crates/tools/compat/src/libs/abuse.rs.
 * Every column and index is available at once; row ids are sequential.
 */
final class FakeTables extends Client
{
    /** @var list<string> */
    public array $calls = [];

    /** @var array<string, true> */
    private array $databases = [];

    /** @var array<string, array{columns: list<string>, indexes: list<string>}> */
    private array $tables = [];

    /** @var list<array{id: string, key: string, time: string, count: int}> */
    private array $rows = [];

    private int $next = 0;

    public function __construct(string $state)
    {
        parent::__construct();
        if ($state === 'bare' || $state === 'complete') {
            $this->databases['db'] = true;
            $this->tables['db/Abuse'] = ['columns' => [], 'indexes' => []];
        }
        if ($state === 'complete') {
            $this->tables['db/Abuse'] = ['columns' => ['key', 'time', 'count'], 'indexes' => ['unique1', 'index2']];
            $this->tables['db/lock'] = ['columns' => [], 'indexes' => []];
        }
    }

    /** @return list<string> the column and index keys of a table */
    public function schema(string $table): array
    {
        $t = $this->tables["db/{$table}"] ?? ['columns' => [], 'indexes' => []];

        return [...$t['columns'], ...$t['indexes']];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function call(string $method, string $path = '', array $headers = [], array $params = [], ?string $responseType = null)
    {
        $parts = explode('/', trim($path, '/'));
        $db = $parts[1] ?? '';
        $table = $parts[3] ?? '';
        $now = '2026-01-01T00:00:00.000+00:00';
        $meta = ['$createdAt' => $now, '$updatedAt' => $now];
        $shape = $parts;
        foreach ([1, 3] as $i) {
            if (isset($shape[$i])) {
                $shape[$i] = '*';
            }
        }
        if (($shape[4] ?? '') === 'rows' && \count($shape) > 5) {
            $shape = [...\array_slice($shape, 0, 5), '{row}'];
        }
        $route = $method . ' ' . implode('/', $shape);

        switch ($route) {
            case 'POST tablesdb':
                $this->calls[] = 'create';
                $id = $params['databaseId'];
                if (isset($this->databases[$id])) {
                    throw new AppwriteException('Database already exists', 409, 'database_already_exists');
                }
                $this->databases[$id] = true;

                return ['$id' => $id, 'name' => $params['name'], 'enabled' => true, 'type' => 'legacy'] + $meta;
            case 'GET tablesdb/*/tables/*':
                $this->calls[] = 'getTable';
                if (!isset($this->tables["{$db}/{$table}"])) {
                    throw new AppwriteException('Table not found', 404, 'table_not_found');
                }

                return $this->table($db, $table, $meta);
            case 'POST tablesdb/*/tables':
                $this->calls[] = 'createTable';
                $id = $params['tableId'];
                if (isset($this->tables["{$db}/{$id}"])) {
                    throw new AppwriteException('Table already exists', 409, 'table_already_exists');
                }
                $this->tables["{$db}/{$id}"] = [
                    'columns' => array_map(fn (array $c) => $c['key'], $params['columns'] ?? []),
                    'indexes' => array_map(fn (array $i) => $i['key'], $params['indexes'] ?? []),
                ];

                return $this->table($db, $id, $meta);
            case 'POST tablesdb/*/tables/*/columns/string':
            case 'POST tablesdb/*/tables/*/columns/datetime':
            case 'POST tablesdb/*/tables/*/columns/integer':
                $type = $parts[5];
                $this->calls[] = 'create' . ucfirst($type) . 'Column';
                if (\in_array($params['key'], $this->tables["{$db}/{$table}"]['columns'], true)) {
                    throw new AppwriteException('Column already exists', 409, 'column_already_exists');
                }
                $this->tables["{$db}/{$table}"]['columns'][] = $params['key'];

                return ['key' => $params['key'], 'type' => $type, 'status' => 'available', 'error' => '', 'required' => $params['required'], 'size' => $params['size'] ?? 0, 'format' => ''] + $meta;
            case 'POST tablesdb/*/tables/*/indexes':
                $this->calls[] = 'createIndex';
                if (\in_array($params['key'], $this->tables["{$db}/{$table}"]['indexes'], true)) {
                    throw new AppwriteException('Index already exists', 409, 'index_already_exists');
                }
                $this->tables["{$db}/{$table}"]['indexes'][] = $params['key'];

                return ['$id' => $params['key'], 'key' => $params['key'], 'type' => (string) $params['type'], 'status' => 'available', 'error' => '', 'columns' => $params['columns'], 'lengths' => []] + $meta;
            case 'GET tablesdb/*/tables/*/columns':
                $this->calls[] = 'listColumns';

                return ['total' => 0, 'columns' => []];
            case 'GET tablesdb/*/tables/*/indexes':
                $this->calls[] = 'listIndexes';

                return ['total' => 0, 'indexes' => []];
            case 'GET tablesdb/*/tables/*/rows':
                $this->calls[] = 'listRows';
                $rows = $this->query($params['queries'] ?? []);

                return ['total' => \count($rows), 'rows' => array_map(fn (array $r) => $this->row($db, $table, $r, $meta), $rows)];
            case 'POST tablesdb/*/tables/*/rows':
                $this->calls[] = 'createRow';
                $data = $params['data'];
                foreach ($this->rows as $row) {
                    if ($row['key'] === $data['key'] && $row['time'] === $data['time']) {
                        throw new AppwriteException('Row already exists', 409, 'row_already_exists');
                    }
                }
                $row = ['id' => (string) ++$this->next, 'key' => $data['key'], 'time' => $data['time'], 'count' => $data['count']];
                $this->rows[] = $row;

                return $this->row($db, $table, $row, $meta);
            case 'PATCH tablesdb/*/tables/*/rows/{row}':
                $increment = str_ends_with($path, '/increment');
                $this->calls[] = $increment ? 'incrementRowColumn' : 'updateRow';
                $id = $parts[5];
                foreach ($this->rows as $i => $row) {
                    if ($row['id'] === $id) {
                        $this->rows[$i]['count'] = $increment ? $row['count'] + (int) ($params['value'] ?? 1) : $params['data']['count'];

                        return $this->row($db, $table, $this->rows[$i], $meta);
                    }
                }
                throw new AppwriteException('Row not found', 404, 'row_not_found');
            case 'DELETE tablesdb/*/tables/*/rows':
                $this->calls[] = 'deleteRows';
                $doomed = array_column($this->query($params['queries'] ?? []), 'id');
                $this->rows = array_values(array_filter($this->rows, fn (array $r) => !\in_array($r['id'], $doomed, true)));

                return ['total' => \count($doomed), 'rows' => []];
        }

        throw new Fault("fake TablesDB: no route for {$method} {$path}");
    }

    /**
     * The rows a list of queries selects: equal, lessThan, orderDesc(''), offset, limit.
     *
     * @return list<array{id: string, key: string, time: string, count: int}>
     */
    private function query(array $queries): array
    {
        $rows = $this->rows;
        $offset = 0;
        $limit = 25;
        foreach ($queries as $query) {
            $q = json_decode($query, true);
            switch ($q['method']) {
                case 'equal':
                    $rows = array_values(array_filter($rows, fn (array $r) => \in_array($r[$q['attribute']], $q['values'], true)));
                    break;
                case 'lessThan':
                    $rows = array_values(array_filter($rows, fn (array $r) => strcmp($r[$q['attribute']], $q['values'][0]) < 0));
                    break;
                case 'orderDesc':
                    $rows = array_reverse($rows);
                    break;
                case 'offset':
                    $offset = $q['values'][0];
                    break;
                case 'limit':
                    $limit = $q['values'][0];
                    break;
            }
        }

        return \array_slice($rows, $offset, $limit);
    }

    private function table(string $db, string $id, array $meta): array
    {
        return ['$id' => $id, '$permissions' => [], 'databaseId' => $db, 'name' => $id, 'enabled' => true, 'rowSecurity' => false, 'columns' => [], 'indexes' => [], 'bytesMax' => 0, 'bytesUsed' => 0] + $meta;
    }

    private function row(string $db, string $table, array $row, array $meta): array
    {
        return ['$id' => $row['id'], '$sequence' => $row['id'], '$tableId' => $table, '$databaseId' => $db, '$permissions' => [], 'key' => $row['key'], 'time' => $row['time'], 'count' => $row['count']] + $meta;
    }
}

/**
 * Maps tests/compat/abuse/spec.json operations onto utopia-php/abuse. Glue only.
 */
final class Adapter implements Base, Benchmarks
{
    /** @var array<int, Clock> clocks of clocked adapters, by object id */
    private static array $clocks = [];

    public function benchmarks(): array
    {
        return [
            'key' => ['Derive a limit for a request: two parameters into the key pattern, then the key', function (Session $s) {
                $limit = new TimeLimit\None('url:{url},ip:{ip}', 10, 60);

                return fn () => $limit->withParams(['{url}' => '/v1/account/sessions/email', '{ip}' => '203.0.113.7'])->key();
            }],
            'time_limit_redis' => ['Check a fixed-window limit in Redis: the hit script', function (Session $s) {
                $limit = new TimeLimit\Redis("{$s->ns}:bench:{ip}", PHP_INT_MAX, 3600, self::redis($s, 'abuse-redis'))->withParam('{ip}', '203.0.113.7');

                return fn () => $limit->check();
            }],
            'sliding_window_redis' => ['Check a sliding-window limit in Redis: the estimate script on two buckets', function (Session $s) {
                $limit = new SlidingWindow\Redis("{$s->ns}:bench:{ip}", PHP_INT_MAX, 60, 120, self::redis($s, 'abuse-redis'))->withParam('{ip}', '203.0.113.7');

                return fn () => $limit->check();
            }],
            'token_bucket_redis' => ['Check a token bucket in Redis: the refill-and-take script', function (Session $s) {
                $limit = new TokenBucket\Redis("{$s->ns}:bench:{ip}", 1000000, 1000.0, self::redis($s, 'abuse-redis'))->withParam('{ip}', '203.0.113.7');

                return fn () => $limit->check();
            }],
            'time_limit_pool' => ['Check a fixed-window limit through a pool of Redis connections', function (Session $s) {
                $limit = new TimeLimit\RedisPool("{$s->ns}:bench:{ip}", PHP_INT_MAX, 3600, self::pool($s, 'abuse-redis', false))->withParam('{ip}', '203.0.113.7');

                return fn () => $limit->check();
            }],
        ];
    }

    public function operations(): array
    {
        return [
            'limit.new' => fn (array $a, Session $s) => $s->handle(self::create($a, $s)),
            'limit.check' => fn (array $a, Session $s) => self::result(self::clocked($a, $s)->check()),
            'limit.peek' => fn (array $a, Session $s) => self::result(self::clocked($a, $s)->peek()),
            'limit.reset' => function (array $a, Session $s) {
                self::clocked($a, $s)->reset();

                return;
            },
            'limit.key' => fn (array $a, Session $s) => self::limit($a, $s)->key(),
            'limit.with_params' => fn (array $a, Session $s) => $s->handle(self::share(self::limit($a, $s), self::limit($a, $s)->withParams(self::params($a['params'])))),
            'limit.with_param' => fn (array $a, Session $s) => $s->handle(self::share(self::limit($a, $s), self::limit($a, $s)->withParam($a['name'], $a['value']))),
            'limit.logs' => fn (array $a, Session $s) => self::logs(self::limit($a, $s), $a),
            'limit.cleanup' => fn (array $a, Session $s) => self::limit($a, $s)->cleanup($a['timestamp']),
            'limit.run' => fn (array $a, Session $s) => self::run($a, $s),

            'database.new' => function (array $a, Session $s) {
                $db = new Database(new MemoryDatabase(), new Cache(new NoCache()));
                $db->setDatabase('abuse');
                if ($a['exists'] ?? true) {
                    $db->create();
                }

                return $s->handle($db);
            },
            'database.setup' => function (array $a, Session $s) {
                $limit = self::limit($a, $s);
                if (!$limit instanceof TimeLimit\Database) {
                    throw new Fault('not a database limit');
                }
                $limit->setup();

                return;
            },

            'tables.new' => fn (array $a, Session $s) => $s->handle(new FakeTables($a['state'] ?? 'none')),
            'tables.setup' => function (array $a, Session $s) {
                $limit = self::limit($a, $s);
                if (!$limit instanceof TimeLimit\Appwrite\TablesDB) {
                    throw new Fault('not a tables limit');
                }
                $limit->setup();

                return;
            },
            'tables.calls' => function (array $a, Session $s) {
                $tables = self::tables($a, $s);
                $calls = $tables->calls;
                $tables->calls = [];

                return ['calls' => $calls, 'abuse' => $tables->schema('Abuse'), 'lock' => $tables->schema('lock')];
            },

            'memory.new' => fn (array $a, Session $s) => $s->handle(new Store($a['now'])),
            'memory.advance' => function (array $a, Session $s) {
                self::store($a, $s)->now += $a['seconds'];

                return;
            },
            'memory.state' => fn (array $a, Session $s) => ['counts' => (object) self::store($a, $s)->counts, 'calls' => self::store($a, $s)->calls],

            'recaptcha.verify' => function (array $a, Session $s) {
                /** @var \ArrayObject<int, RequestInterface> $requests */
                $requests = new \ArrayObject();
                $client = new readonly class ($a['body'], $requests) implements ClientInterface {
                    /** @param \ArrayObject<int, RequestInterface> $requests */
                    public function __construct(private string $body, private \ArrayObject $requests)
                    {
                    }

                    public function sendRequest(RequestInterface $request): ResponseInterface
                    {
                        $this->requests[] = $request;

                        return new Response(200, body: new Stream($this->body));
                    }
                };
                $recaptcha = new ReCaptcha($a['secret'], $client);
                $verdict = isset($a['score'])
                    ? $recaptcha->verify($a['response'], $a['ip'], (float) $a['score'])
                    : $recaptcha->verify($a['response'], $a['ip']);
                /** @var RequestInterface $request */
                $request = $requests[0];

                return [
                    'verdict' => $verdict,
                    'method' => $request->getMethod(),
                    'url' => (string) $request->getUri(),
                    'content_type' => $request->getHeaderLine('Content-Type'),
                    'body' => (string) $request->getBody(),
                ];
            },
            'recaptcha.url' => fn (array $a, Session $s) => ReCaptcha::URL,

            'clock.wait_window' => function (array $a, Session $s) {
                $now = time();
                $until = $now - $now % $a['seconds'] + $a['seconds'];
                while (time() < $until) {
                    usleep(1000);
                }

                return;
            },
            'clock.fresh_second' => function (array $a, Session $s) {
                $start = time();
                while (time() === $start) {
                    usleep(1000);
                }

                return;
            },
            'clock.window_end' => fn (array $a, Session $s) => time() - time() % $a['seconds'] + $a['seconds'],
            'clock.between' => fn (array $a, Session $s) => $a['value'] >= time() + $a['low'] && $a['value'] <= time() + $a['high'],
            'compare.greater' => fn (array $a, Session $s) => $a['a'] > $a['b'],

            'redis.set' => fn (array $a, Session $s) => self::redis($s, $a['service'] ?? 'abuse-redis')->set($a['key'], $a['value']),
            'redis.get' => fn (array $a, Session $s) => self::redis($s, $a['service'] ?? 'abuse-redis')->get($a['key']),
            'redis.ttl' => function (array $a, Session $s) {
                $ttl = self::redis($s, $a['service'] ?? 'abuse-redis')->ttl($a['key']);

                return $ttl > $a['max'] - 3 && $ttl <= $a['max'];
            },
            'redis.clear' => function (array $a, Session $s) {
                $redis = self::redis($s, $a['service'] ?? 'abuse-redis');
                $cursor = null;
                do {
                    $keys = $redis->scan($cursor, 'abuse__*', 100);
                    foreach ($keys ?: [] as $key) {
                        $redis->del($key);
                    }
                } while ($cursor > 0);

                return;
            },
            'cluster.set' => fn (array $a, Session $s) => self::cluster($s)->set($a['key'], $a['value']),
            'cluster.clear' => function (array $a, Session $s) {
                $redis = self::cluster($s);
                foreach ($redis->_masters() as $master) {
                    $cursor = null;
                    do {
                        $keys = $redis->scan($cursor, $master, 'abuse__*', 100);
                        foreach (\is_array($keys) ? $keys : [] as $key) {
                            $redis->del($key);
                        }
                    } while ($cursor > 0);
                }

                return;
            },
        ];
    }

    /**
     * new <algo>\<store>(...) for limit.new.
     */
    private static function create(array $a, Session $s): Limit
    {
        $key = $a['key'];
        $service = $a['service'] ?? 'abuse-redis';
        $clock = new Clock();
        $limit = match ($a['algo']) {
            'time_limit' => match ($a['store']) {
                'none' => new TimeLimit\None($key, $a['limit'], $a['seconds']),
                'redis' => new TimeLimit\Redis($key, $a['limit'], $a['seconds'], self::redis($s, $service)),
                'cluster' => new TimeLimit\RedisCluster($key, $a['limit'], $a['seconds'], self::cluster($s)),
                'pool' => new ClockedTimeLimitPool($key, $a['limit'], $a['seconds'], self::pool($s, $service, false), $clock),
                'pool-cluster' => new ClockedTimeLimitPool($key, $a['limit'], $a['seconds'], self::pool($s, $service, true), $clock),
                'database' => new TimeLimit\Database($key, $a['limit'], $a['seconds'], self::database($a, $s)),
                'tables' => new TimeLimit\Appwrite\TablesDB($key, $a['limit'], $a['seconds'], self::tables($a, $s), 'db'),
                'memory' => new Memory($key, $a['limit'], $a['seconds'], $s->get($a['memory'])),
                default => throw new Fault('unknown store ' . $a['store']),
            },
            'sliding_window' => match ($a['store']) {
                'none' => new SlidingWindow\None($key, $a['limit'], $a['window']),
                'redis' => new SlidingWindow\Redis($key, $a['limit'], $a['window'], $a['ttl'], self::redis($s, $service)),
                'cluster' => new SlidingWindow\RedisCluster($key, $a['limit'], $a['window'], $a['ttl'], self::cluster($s)),
                'pool' => new ClockedSlidingWindowPool($key, $a['limit'], $a['window'], $a['ttl'], self::pool($s, $service, false), $clock),
                'pool-cluster' => new ClockedSlidingWindowPool($key, $a['limit'], $a['window'], $a['ttl'], self::pool($s, $service, true), $clock),
                default => throw new Fault('unknown store ' . $a['store']),
            },
            'token_bucket' => match ($a['store']) {
                'none' => new TokenBucket\None($key, $a['tokens'], (float) $a['refill_rate']),
                'redis' => new TokenBucket\Redis($key, $a['tokens'], (float) $a['refill_rate'], self::redis($s, $service)),
                'cluster' => new TokenBucket\RedisCluster($key, $a['tokens'], (float) $a['refill_rate'], self::cluster($s)),
                'pool' => new ClockedTokenBucketPool($key, $a['tokens'], (float) $a['refill_rate'], self::pool($s, $service, false), $clock),
                'pool-cluster' => new ClockedTokenBucketPool($key, $a['tokens'], (float) $a['refill_rate'], self::pool($s, $service, true), $clock),
                'fixed' => new Fixed($a['tokens'], (float) $a['refill_rate'], (float) $a['available'], (float) $a['time']),
                default => throw new Fault('unknown store ' . $a['store']),
            },
            default => throw new Fault('unknown algorithm ' . $a['algo']),
        };
        self::$clocks[spl_object_id($limit)] = $clock;

        return $limit;
    }

    /**
     * A copy made by withParams() reads the same clock.
     */
    private static function share(Limit $from, Limit $to): Limit
    {
        if (isset(self::$clocks[spl_object_id($from)])) {
            self::$clocks[spl_object_id($to)] = self::$clocks[spl_object_id($from)];
        }

        return $to;
    }

    /**
     * The limit of `handle`, its clock set to `now` (or the real clock).
     */
    private static function clocked(array $a, Session $s): Limit
    {
        $limit = self::limit($a, $s);
        $clock = self::$clocks[spl_object_id($limit)] ?? null;
        if (\array_key_exists('now', $a)) {
            $clocked = $limit instanceof ClockedTimeLimitPool || $limit instanceof ClockedSlidingWindowPool || $limit instanceof ClockedTokenBucketPool;
            if (!$clocked || $clock === null) {
                throw new Fault('this limit reads the real clock');
            }
            $clock->now = $a['now'];
        } elseif ($clock !== null) {
            $clock->now = null;
        }

        return $limit;
    }

    /**
     * Port of the checks a fuzz input describes: a limit on a fixed clock, its
     * key, and the verdict of each step.
     */
    private static function run(array $a, Session $s): array
    {
        $limit = self::create(['key' => "{$s->ns}:{$a['key']}", 'service' => 'abuse-redis-fuzz'] + $a, $s);
        self::$clocks[spl_object_id($limit)]->now = $a['now'];
        $limit = self::share($limit, $limit->withParams(self::params($a['params'] ?? [])));
        $limit->reset();
        $verdicts = [];
        foreach ($a['steps'] as $step) {
            $verdicts[] = match ($step) {
                'check' => self::result($limit->check()),
                'peek' => self::result($limit->peek()),
                default => throw new Fault('unknown step ' . $step),
            };
        }

        return ['key' => $limit->key(), 'verdicts' => $verdicts];
    }

    /**
     * @return array<string, string>|array<int, string>
     */
    private static function params(mixed $params): array
    {
        return \is_array($params) ? $params : [];
    }

    private static function result(Result $r): array
    {
        return ['limited' => $r->limited, 'limit' => $r->limit, 'remaining' => $r->remaining, 'reset' => $r->reset];
    }

    /**
     * getLogs() with the offset and limit given, or its defaults. Documents
     * are reduced to their key and count.
     */
    private static function logs(Limit $limit, array $a): mixed
    {
        $args = [];
        if (\array_key_exists('offset', $a)) {
            $args['offset'] = $a['offset'];
        }
        if (\array_key_exists('count', $a)) {
            $args['limit'] = $a['count'];
        }
        $logs = $limit->getLogs(...$args);

        return array_map(fn (mixed $log) => $log instanceof Document ? ['key' => $log->getAttribute('key'), 'count' => $log->getAttribute('count')] : $log, $logs);
    }

    private static function limit(array $a, Session $s): Limit
    {
        $limit = $s->get($a['handle']);

        return $limit instanceof Limit ? $limit : throw new Fault('not a limit');
    }

    private static function store(array $a, Session $s): Store
    {
        $store = $s->get($a['memory']);

        return $store instanceof Store ? $store : throw new Fault('not a memory store');
    }

    private static function database(array $a, Session $s): Database
    {
        $db = $s->get($a['database']);

        return $db instanceof Database ? $db : throw new Fault('not a database');
    }

    private static function tables(array $a, Session $s): FakeTables
    {
        $tables = $s->get($a['tables']);

        return $tables instanceof FakeTables ? $tables : throw new Fault('not a fake TablesDB');
    }

    private static function redis(Session $s, string $service): \Redis
    {
        $url = parse_url($s->service($service));
        $redis = new \Redis();
        $redis->connect($url['host'] ?? 'redis', $url['port'] ?? 6379);
        $redis->select((int) ltrim($url['path'] ?? '/0', '/'));

        return $redis;
    }

    /** @return list<string> */
    private static function seeds(Session $s): array
    {
        return explode(',', substr($s->service('abuse-cluster'), \strlen('redis-cluster://')));
    }

    private static function cluster(Session $s): \RedisCluster
    {
        return new \RedisCluster(null, self::seeds($s), 1.5, 1.5);
    }

    private static function pool(Session $s, string $service, bool $cluster): Pool
    {
        $init = $cluster ? fn (): \RedisCluster => self::cluster($s) : fn (): \Redis => self::redis($s, $service);

        return new Pool(new Stack(), 'abuse', 2, $init, timeout: 0.0);
    }
}
