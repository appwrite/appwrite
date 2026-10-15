<?php

/**
 * adapter.*: the adapters called directly, without a Database
 * (Utopia\Database\Adapter\Postgres, MariaDB, MySQL over Utopia\Database\PDO,
 * SQLite on a file, Memory). Twin: crates/tools/compat/src/libs/database/adapter.rs.
 *
 *   adapter.open    {driver?|engine?, database?, namespace?, shared_tables?, tenant?,
 *                    tenant_per_document?, roles?, status?, hooks?}  a handle
 *   adapter.call    {adapter, method, args?}       $adapter->method(...args), args
 *                                                  decoded as database.* decodes them
 *   adapter.fuzz_find, adapter.fuzz_roundtrip, mongo.fuzz_find, mongo.fuzz_roundtrip
 *                                                  the fuzz profiles' programs
 *
 * `hooks` are write hooks registered on the adapter (default `["Permissions"]`,
 * the permissions table a Database's Hook\Permissions keeps).
 */

use Tests\Compat\Database\Core;
use Tests\Compat\Database\Hooks;
use Tests\Compat\Database\Values;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\Database\Adapter;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Adapter\Mongo;
use Utopia\Database\Adapter\MySQL;
use Utopia\Database\Adapter\Postgres;
use Utopia\Database\Adapter\SQLite;
use Utopia\Database\Attribute;
use Utopia\Database\Document;
use Utopia\Database\Hook\Permissions;
use Utopia\Database\PDO;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Query\CursorDirection;
use Utopia\Query\OrderDirection;

/**
 * A bare adapter of `driver` (`postgres`, `mariadb`, `mysql`) or `engine`
 * (`sqlite`: file `/tmp/compat-fs/${ns}/<file>`, `memory`), one connection
 * of its own (not persistent, as in Rust).
 */
$connect = static function (Session $s, array $a): Adapter {
    $attributes = Core::PDO_ATTRIBUTES;
    $attributes[\PDO::ATTR_PERSISTENT] = false;
    $engine = (string) ($a['engine'] ?? 'postgres');
    if ($engine === 'sqlite') {
        $dir = '/tmp/compat-fs/' . $s->ns;
        if (!\is_dir($dir)) {
            \mkdir($dir, 0777, true);
        }
        $file = (string) ($a['file'] ?? 'adapter.sqlite');
        if ($file === '' || \str_contains($file, '/')) {
            throw new Fault('adapter.open: `file` is a file name');
        }

        return new SQLite(new PDO('sqlite:' . $dir . '/' . $file, null, null, $attributes));
    }
    if ($engine === 'memory') {
        return new \Utopia\Database\Adapter\Memory();
    }
    $driver = (string) ($a['driver'] ?? 'postgres');
    if ($driver === 'postgres') {
        $url = \parse_url($s->service('database-postgres'));

        return new Postgres(new PDO(
            'pgsql:host=' . $url['host'] . ';port=' . ($url['port'] ?? 5432) . ';dbname=' . \ltrim($url['path'] ?? '', '/'),
            \urldecode($url['user'] ?? ''),
            \urldecode($url['pass'] ?? ''),
            $attributes
        ));
    }
    if ($driver !== 'mariadb' && $driver !== 'mysql') {
        throw new Fault("adapter: unknown driver `{$driver}`");
    }
    $url = \parse_url($s->service($driver === 'mariadb' ? 'database-mariadb' : 'database-mysql'));
    $pdo = new PDO(
        'mysql:host=' . $url['host'] . ';port=' . ($url['port'] ?? 3306) . ';charset=utf8mb4',
        \urldecode($url['user'] ?? ''),
        \urldecode($url['pass'] ?? ''),
        $attributes
    );

    return $driver === 'mariadb' ? new MariaDB($pdo) : new MySQL($pdo);
};

$authorization = static function (array $a): Authorization {
    $authorization = new Authorization();
    if (\array_key_exists('roles', $a)) {
        $authorization->cleanRoles();
        foreach (Values::list($a['roles']) as $role) {
            $authorization->addRole((string) $role);
        }
    }
    if (\array_key_exists('status', $a)) {
        $authorization->setStatus((bool) $a['status']);
    }

    return $authorization;
};

$adapterOf = static function (array $a, Session $s): Adapter {
    $adapter = $s->get($a['adapter'] ?? null);
    if (!$adapter instanceof Adapter) {
        throw new Fault('expected an adapter handle');
    }

    return $adapter;
};

// The fuzz profiles' collections on `$adapter`: schema ${ns} (namespace
// `fz`), collection `fz` seeded with 24 documents and an empty `rt`; without
// `$objects` (MariaDB, MySQL, SQLite) no `meta` (`fz`) nor `o` (`rt`). The
// MongoDB twins (mongo.fuzz_*) seed a database `${ns}` on the compat MongoDB
// the same way.
$fuzzSeed = static function (Adapter $adapter, Session $s, bool $objects): void {
    $adapter->setAuthorization(new Authorization());
    $adapter->addWriteHook(new Permissions());
    $adapter->setDatabase($s->ns);
    $adapter->setNamespace('fz');
    if ($adapter->exists($s->ns)) {
        $adapter->delete($s->ns);
    }
    $adapter->create($s->ns);
    $adapter->createCollection('fz', [
        Attribute::string('title', 64), Attribute::integer('year'), Attribute::double('rating'),
        Attribute::boolean('active'), Attribute::string('tags', 32, array: true),
        ...($objects ? [Attribute::object('meta')] : []),
        Attribute::datetime('released'),
    ]);
    $adapter->createCollection('rt', [Attribute::text('t'), Attribute::string('a', 64, array: true), ...($objects ? [Attribute::object('o')] : [])]);
    $names = ['Alpha', 'beta', 'Gamma', 'delta', 'Ünïcödé', "o'brien", '100%', 'a_b'];
    $tags = [['x', 'y'], [], ['y'], ['z', 'x', "o'b"]];
    $documents = [];
    for ($i = 0; $i < 24; $i++) {
        $document = [
            '$id' => 'd' . $i,
            '$permissions' => [$i % 2 === 0 ? 'read("any")' : 'read("user:1")'],
            '$createdAt' => \sprintf('2001-01-%02d 00:00:00.000', $i + 1),
            '$updatedAt' => \sprintf('2002-01-%02d 00:00:00.000', 24 - $i),
            'title' => $names[$i % 8] . ' ' . $i,
            'year' => 1970 + ($i * 7) % 50,
            'rating' => $i * 0.5 - 3,
            'active' => $i % 3 === 0,
            'tags' => $tags[$i % 4],
        ];
        if ($objects) {
            $document['meta'] = ['k' => $i % 5, 's' => $i % 2 === 0 ? 'a' : 'b'];
        }
        $document['released'] = \sprintf('2000-01-%02d 00:00:00.000', $i % 28 + 1);
        $documents[] = new Document($document);
    }
    $adapter->createDocuments(new Document(['$id' => 'fz']), $documents);
};

// The fuzz profiles' adapter: one per namespace and driver. Driver `sqlite`
// (`engine: "sqlite"` of the operations) puts it in file
// /tmp/compat-fs/${ns}/fuzz.sqlite.
$fuzzAdapter = static function (Session $s, string $driver = 'postgres') use ($connect, $fuzzSeed): Adapter {
    static $adapters = [];
    if (isset($adapters[$s->ns][$driver])) {
        return $adapters[$s->ns][$driver];
    }
    $adapter = $driver === 'sqlite'
        ? $connect($s, ['engine' => 'sqlite', 'file' => 'fuzz.sqlite'])
        : $connect($s, ['driver' => $driver]);
    $fuzzSeed($adapter, $s, $driver === 'postgres');

    return $adapters[$s->ns][$driver] = $adapter;
};
$fuzzMongo = static function (Session $s) use ($fuzzSeed): Mongo {
    static $adapters = [];
    if (isset($adapters[$s->ns])) {
        return $adapters[$s->ns];
    }
    $adapter = new Mongo(Core::mongo($s, $s->ns));
    $fuzzSeed($adapter, $s, true);

    return $adapters[$s->ns] = $adapter;
};

$fuzzFind = static function (array $a, Session $s, ?Adapter $adapter = null) use ($fuzzAdapter, $authorization): mixed {
    $adapter ??= $fuzzAdapter($s, (($a['engine'] ?? 'postgres') === 'sqlite' ? 'sqlite' : (string) ($a['driver'] ?? 'postgres')));
    $adapter->setAuthorization(\array_key_exists('roles', $a) ? $authorization($a) : $authorization(['status' => false]));
    // Database::find() ends every order with $sequence; without it rows tied
    // on the ordered attributes come back in whatever order the server picks.
    $orderAttributes = \array_map(\strval(...), Values::list($a['order_attributes'] ?? []));
    $orderTypes = \array_map(static fn ($t) => OrderDirection::from(\strtoupper((string) $t)), Values::list($a['order_types'] ?? []));
    if (!\in_array('$sequence', $orderAttributes, true)) {
        $orderTypes = \array_pad(\array_slice($orderTypes, 0, \count($orderAttributes)), \count($orderAttributes), OrderDirection::Asc);
        $orderAttributes[] = '$sequence';
        $orderTypes[] = OrderDirection::Asc;
    }
    // Queries as the Database hands them to the adapter: `attribute_types`
    // (attribute => type) and `arrays` (array attributes) set on every query
    // and nested query of those attributes.
    $types = Values::decodeArray($a['attribute_types'] ?? []);
    $arrays = \array_map(\strval(...), Values::list($a['arrays'] ?? []));
    $apply = static function (array $queries) use (&$apply, $types, $arrays): void {
        foreach ($queries as $query) {
            if (!$query instanceof Query) {
                continue;
            }
            if (isset($types[$query->getAttribute()])) {
                $query->setAttributeType((string) $types[$query->getAttribute()]);
            }
            if (\in_array($query->getAttribute(), $arrays, true)) {
                $query->setOnArray(true);
            }
            if ($query->isNested()) {
                $apply($query->getValues());
            }
        }
    };
    $queries = Values::queries($a['queries'] ?? []);
    $apply($queries);

    return Values::encode($adapter->find(
        new Document(['$id' => 'fz']),
        $queries,
        \array_key_exists('limit', $a) ? ($a['limit'] === null ? null : (int) $a['limit']) : 25,
        isset($a['offset']) ? (int) $a['offset'] : null,
        $orderAttributes,
        $orderTypes,
        Values::decodeArray($a['cursor'] ?? []),
        CursorDirection::from((string) ($a['cursor_direction'] ?? 'after')),
    ));
};
$fuzzRoundtrip = static function (array $a, Session $s, ?Adapter $adapter = null) use ($fuzzAdapter, $authorization): mixed {
    // An adapter passed in is MongoDB's (mongo.fuzz_roundtrip).
    $driver = $adapter === null ? (($a['engine'] ?? 'postgres') === 'sqlite' ? 'sqlite' : (string) ($a['driver'] ?? 'postgres')) : 'mongo';
    $adapter ??= $fuzzAdapter($s, $driver);
    $value = Values::decode($a['value'] ?? null);
    $adapter->setAuthorization($authorization(['status' => false]));
    $collection = new Document(['$id' => 'rt']);
    $out = [];
    foreach ([
        static fn () => $adapter->createDocument($collection, new Document([
            '$id' => 'rt', '$permissions' => [], 't' => $value, 'a' => [$value],
            ...(\in_array($driver, ['postgres', 'mongo'], true) ? ['o' => ['k' => $value]] : []),
        ])),
        static fn () => $adapter->getDocument($collection, 'rt'),
        static fn () => $adapter->find($collection, [new Query('equal', 't', [$value])]),
        static fn () => $adapter->deleteDocument($collection, 'rt'),
    ] as $call) {
        try {
            $out[] = Values::encode($call());
        } catch (\Throwable $e) {
            $out[] = ['error' => ['class' => $e::class, 'message' => $e->getMessage()]];
        }
    }

    return $out;
};

return Core::operations([
    'adapter.open' => static function (array $a, Session $s) use ($connect, $authorization) {
        $adapter = $connect($s, $a);
        $adapter->setAuthorization($authorization($a));
        foreach (Values::list($a['hooks'] ?? ['Permissions']) as $hook) {
            $made = Hooks::make($hook);
            if (!$made instanceof \Utopia\Database\Hook\Write) {
                throw new Fault('adapter.open: `hooks` are write hooks');
            }
            $adapter->addWriteHook($made);
        }
        $adapter->setDatabase((string) ($a['database'] ?? $s->ns));
        $adapter->setNamespace((string) ($a['namespace'] ?? 'ns'));
        if (\array_key_exists('shared_tables', $a)) {
            $adapter->setSharedTables((bool) $a['shared_tables']);
        }
        if (\array_key_exists('tenant', $a)) {
            $adapter->setTenant($a['tenant']);
        }
        if (\array_key_exists('tenant_per_document', $a)) {
            $adapter->setTenantPerDocument((bool) $a['tenant_per_document']);
        }

        return $s->handle($adapter);
    },
    'adapter.call' => static fn (array $a, Session $s) => Core::invoke($adapterOf($a, $s), (string) ($a['method'] ?? ''), (array) ($a['args'] ?? []), $s),
    'adapter.fuzz_find' => $fuzzFind,
    'adapter.fuzz_roundtrip' => $fuzzRoundtrip,
    // The same on the MongoDB adapter (database ${ns} of the compat MongoDB,
    // seeded the same way).
    'mongo.fuzz_find' => static fn (array $a, Session $s) => $fuzzFind($a, $s, $fuzzMongo($s)),
    'mongo.fuzz_roundtrip' => static fn (array $a, Session $s) => $fuzzRoundtrip($a, $s, $fuzzMongo($s)),
]);
