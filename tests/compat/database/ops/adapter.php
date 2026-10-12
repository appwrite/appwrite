<?php

/**
 * adapter.*: the SQL adapters called directly
 * (Utopia\Database\Adapter\Postgres, and with `driver` `mariadb`/`mysql`
 * Adapter\MariaDB/Adapter\MySQL, over Utopia\Database\PDO).
 * Twin: crates/tools/compat/src/libs/database/adapter.rs.
 *
 * `adapter` arguments are handles from adapter.open; `collection` is a
 * collection id (a document with that `$id` where a Document is expected)
 * or a `$document` tag. Statements a `before` transform captures have the
 * random `ID::unique()` placeholders replaced by `<id>`.
 */

use Tests\Compat\Database\Values;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Adapter\MySQL;
use Utopia\Database\Adapter\Postgres;
use Utopia\Database\Adapter\SQL;
use Utopia\Database\Change;
use Utopia\Database\Document;
use Utopia\Database\PDO;
use Utopia\Database\Validator\Authorization;

$handle = static function (array $a, Session $s): object {
    $h = $s->get($a['adapter'] ?? null);
    if (!\is_object($h) || !\property_exists($h, 'db')) {
        throw new Fault('expected an adapter handle');
    }

    return $h;
};
$db = static fn (array $a, Session $s): SQL => $handle($a, $s)->db;
$collection = static function (mixed $value): Document {
    $value = Values::decode($value);
    if ($value instanceof Document) {
        return $value;
    }

    return new Document(['$id' => (string) $value]);
};
$normalize = static fn (string $sql): string => (string) \preg_replace('/[0-9a-f]{20}/', '<id>', $sql);
$strings = static fn (mixed $value): array => \array_map(\strval(...), Values::list($value));
// Queries as the Database hands them to the adapter: `attribute_types`
// (attribute => type) and `arrays` (array attributes) set on every query
// and nested query of those attributes.
$queries = static function (array $a): array {
    $types = Values::decodeArray($a['attribute_types'] ?? []);
    $arrays = \array_map(\strval(...), Values::list($a['arrays'] ?? []));
    $apply = static function (array $queries) use (&$apply, $types, $arrays): void {
        foreach ($queries as $query) {
            if (!$query instanceof \Utopia\Database\Query) {
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
    $list = Values::queries($a['queries'] ?? []);
    $apply($list);

    return $list;
};

// The adapter `driver` names: `postgres` (default), `mariadb` or `mysql`, on its service.
$connect = static function (Session $s, string $driver = 'postgres'): SQL {
    $attributes = SQL::getPDOAttributes();
    // One adapter, one connection (as in Rust): no persistent handle shared between adapters.
    $attributes[\PDO::ATTR_PERSISTENT] = false;
    if ($driver === 'postgres') {
        $url = \parse_url($s->service('database-postgres'));
        $pdo = new PDO(
            'pgsql:host=' . $url['host'] . ';port=' . ($url['port'] ?? 5432) . ';dbname=' . \ltrim($url['path'] ?? '', '/'),
            \urldecode($url['user'] ?? ''),
            \urldecode($url['pass'] ?? ''),
            $attributes
        );
        $adapter = new Postgres($pdo);
    } elseif ($driver === 'mariadb' || $driver === 'mysql') {
        $url = \parse_url($s->service($driver === 'mariadb' ? 'database-mariadb' : 'database-mysql'));
        $pdo = new PDO(
            'mysql:host=' . $url['host'] . ';port=' . ($url['port'] ?? 3306) . ';charset=utf8mb4',
            \urldecode($url['user'] ?? ''),
            \urldecode($url['pass'] ?? ''),
            $attributes
        );
        $adapter = $driver === 'mariadb' ? new MariaDB($pdo) : new MySQL($pdo);
    } else {
        throw new Fault("adapter: unknown driver `{$driver}`");
    }
    $adapter->setAuthorization(new Authorization());

    return $adapter;
};
// The fuzz profiles' adapter: one per namespace and driver, on schema ${ns}
// (namespace `fz`), with collection `fz` seeded with 24 documents and an
// empty `rt`. MariaDB and MySQL have no object attributes: no `meta` (`fz`)
// nor `o` (`rt`) there.
$fuzzAdapter = static function (Session $s, string $driver = 'postgres') use ($connect): SQL {
    static $adapters = [];
    if (isset($adapters[$s->ns][$driver])) {
        return $adapters[$s->ns][$driver];
    }
    $objects = $driver === 'postgres';
    $adapter = $connect($s, $driver);
    $adapter->setDatabase($s->ns);
    $adapter->setNamespace('fz');
    // MariaDB's delete() of a database that does not exist fails.
    if ($objects || $adapter->exists($s->ns)) {
        $adapter->delete($s->ns);
    }
    $adapter->create($s->ns);
    $attribute = static fn (string $id, string $type, int $size = 0, bool $array = false) => new Document([
        '$id' => $id, 'type' => $type, 'size' => $size, 'signed' => true, 'array' => $array, 'required' => false,
    ]);
    $adapter->createCollection('fz', [
        $attribute('title', 'string', 64), $attribute('year', 'integer', 4), $attribute('rating', 'double'),
        $attribute('active', 'boolean'), $attribute('tags', 'string', 32, true),
        ...($objects ? [$attribute('meta', 'object')] : []),
        $attribute('released', 'datetime'),
    ]);
    $adapter->createCollection('rt', [$attribute('t', 'text'), $attribute('a', 'string', 64, true), ...($objects ? [$attribute('o', 'object')] : [])]);
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

    return $adapters[$s->ns][$driver] = $adapter;
};

return [
    'adapter.open' => static function (array $a, Session $s) use ($connect) {
        $adapter = $connect($s, (string) ($a['driver'] ?? 'postgres'));
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

        return $s->handle(new class ($adapter) {
            /** @var list<array{string, string}> */
            public array $captured = [];

            public function __construct(public SQL $db)
            {
            }
        });
    },

    // ----- Settings -----

    'adapter.set_authorization' => static function (array $a, Session $s) use ($db) {
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
        $db($a, $s)->setAuthorization($authorization);

        return null;
    },
    'adapter.get_authorization' => static function (array $a, Session $s) use ($db) {
        $authorization = $db($a, $s)->getAuthorization();

        return ['roles' => $authorization->getRoles(), 'status' => $authorization->getStatus()];
    },
    'adapter.set_database' => static fn (array $a, Session $s) => $db($a, $s)->setDatabase((string) $a['name']),
    'adapter.get_database' => static fn (array $a, Session $s) => $db($a, $s)->getDatabase(),
    'adapter.set_namespace' => static function (array $a, Session $s) use ($db) {
        $db($a, $s)->setNamespace((string) $a['namespace']);

        return null;
    },
    'adapter.get_namespace' => static fn (array $a, Session $s) => $db($a, $s)->getNamespace(),
    'adapter.set_shared_tables' => static fn (array $a, Session $s) => $db($a, $s)->setSharedTables((bool) $a['shared']),
    'adapter.get_shared_tables' => static fn (array $a, Session $s) => $db($a, $s)->getSharedTables(),
    'adapter.set_tenant' => static fn (array $a, Session $s) => $db($a, $s)->setTenant($a['tenant'] ?? null),
    'adapter.get_tenant' => static fn (array $a, Session $s) => $db($a, $s)->getTenant(),
    'adapter.set_tenant_per_document' => static fn (array $a, Session $s) => $db($a, $s)->setTenantPerDocument((bool) $a['enabled']),
    'adapter.get_tenant_per_document' => static fn (array $a, Session $s) => $db($a, $s)->getTenantPerDocument(),
    'adapter.set_hostname' => static function (array $a, Session $s) use ($db) {
        $db($a, $s)->setHostname((string) $a['hostname']);

        return null;
    },
    'adapter.get_hostname' => static fn (array $a, Session $s) => $db($a, $s)->getHostname(),
    'adapter.set_debug' => static function (array $a, Session $s) use ($db) {
        $db($a, $s)->setDebug((string) $a['key'], Values::decode($a['value'] ?? null));

        return null;
    },
    'adapter.get_debug' => static fn (array $a, Session $s) => Values::encode($db($a, $s)->getDebug()),
    'adapter.reset_debug' => static function (array $a, Session $s) use ($db) {
        $db($a, $s)->resetDebug();

        return null;
    },
    'adapter.set_metadata' => static function (array $a, Session $s) use ($db) {
        $db($a, $s)->setMetadata((string) $a['key'], (string) $a['value']);

        return null;
    },
    'adapter.get_metadata' => static fn (array $a, Session $s) => $db($a, $s)->getMetadata(),
    'adapter.reset_metadata' => static function (array $a, Session $s) use ($db) {
        $db($a, $s)->resetMetadata();

        return null;
    },
    'adapter.set_timeout' => static function (array $a, Session $s) use ($db) {
        $db($a, $s)->setTimeout((int) $a['milliseconds'], (string) ($a['event'] ?? '*'));

        return null;
    },
    'adapter.get_timeout' => static fn (array $a, Session $s) => $db($a, $s)->getTimeout(),
    'adapter.clear_timeout' => static function (array $a, Session $s) use ($db) {
        $db($a, $s)->clearTimeout((string) $a['event']);

        return null;
    },
    'adapter.clear_timeouts' => static function (array $a, Session $s) use ($db) {
        $db($a, $s)->clearTimeouts();

        return null;
    },
    'adapter.before' => static function (array $a, Session $s) use ($handle, $normalize) {
        $h = $handle($a, $s);
        $event = (string) $a['event'];
        $name = (string) ($a['name'] ?? '');
        if ($a['remove'] ?? false) {
            $h->db->before($event, $name);

            return null;
        }
        $prepend = isset($a['prepend']) ? (string) $a['prepend'] : null;
        $h->db->before($event, $name, static function (mixed $query) use ($h, $event, $normalize, $prepend) {
            $h->captured[] = [$event, $normalize((string) $query)];

            return $prepend === null ? $query : $prepend . $query;
        });

        return null;
    },
    'adapter.captured' => static function (array $a, Session $s) use ($handle) {
        $h = $handle($a, $s);
        $out = $h->captured;
        $h->captured = [];

        return $out;
    },
    'adapter.filter' => static fn (array $a, Session $s) => $db($a, $s)->filter((string) $a['value']),
    'adapter.enable_alter_locks' => static function (array $a, Session $s) use ($db) {
        $db($a, $s)->enableAlterLocks((bool) $a['enable']);

        return null;
    },
    'adapter.lock_type' => static fn (array $a, Session $s) => $db($a, $s)->getLockType(),
    'adapter.column_type' => static fn (array $a, Session $s) => $db($a, $s)->getColumnType(
        (string) $a['type'],
        (int) ($a['size'] ?? 0),
        (bool) ($a['signed'] ?? true),
        (bool) ($a['array'] ?? false),
        (bool) ($a['required'] ?? false),
    ),
    'adapter.tenant_query' => static fn (array $a, Session $s) => $db($a, $s)->getTenantQuery(
        (string) $a['collection'],
        (string) ($a['alias'] ?? ''),
        (int) ($a['tenant_count'] ?? 0),
        (string) ($a['condition'] ?? 'AND'),
    ),
    'adapter.sql_conditions' => static function (array $a, Session $s) use ($db, $normalize, $queries) {
        $binds = [];
        $sql = $db($a, $s)->getSQLConditions($queries($a), $binds, (string) ($a['separator'] ?? 'AND'));

        return ['sql' => $normalize($sql), 'binds' => Values::encode(\array_values($binds))];
    },
    'adapter.set_float_precision' => static function (array $a, Session $s) use ($db) {
        $db($a, $s)->setFloatPrecision((int) $a['precision']);

        return null;
    },
    'adapter.driver_exec' => static fn (array $a, Session $s) => $db($a, $s)->getDriver()->exec((string) $a['sql']),
    'adapter.driver_query' => static function (array $a, Session $s) use ($db) {
        $statement = $db($a, $s)->getDriver()->prepare((string) $a['sql']);
        $statement->execute();

        return $statement->fetchAll();
    },
    'adapter.process_exception' => static function (array $a, Session $s) {
        $exception = new \PDOException((string) $a['message']);
        (new \ReflectionProperty(\Exception::class, 'code'))->setValue($exception, (string) $a['sqlstate']);
        $exception->errorInfo = [(string) $a['sqlstate'], $a['native'] ?? null, (string) $a['message']];
        $class = new \ReflectionClass(match ($a['driver'] ?? 'postgres') {
            'mariadb' => MariaDB::class,
            'mysql' => MySQL::class,
            default => Postgres::class,
        });

        throw $class->getMethod('processException')->invoke($class->newInstanceWithoutConstructor(), $exception);
    },

    // ----- Transactions and connection -----

    'adapter.in_transaction' => static fn (array $a, Session $s) => $db($a, $s)->inTransaction(),
    'adapter.start_transaction' => static fn (array $a, Session $s) => $db($a, $s)->startTransaction(),
    'adapter.commit_transaction' => static fn (array $a, Session $s) => $db($a, $s)->commitTransaction(),
    'adapter.rollback_transaction' => static fn (array $a, Session $s) => $db($a, $s)->rollbackTransaction(),
    'adapter.with_transaction' => static function (array $a, Session $s) use ($db, $collection) {
        $adapter = $db($a, $s);
        $calls = 0;

        try {
            $result = $adapter->withTransaction(static function () use ($adapter, $a, $collection, &$calls) {
                $calls++;
                $document = $adapter->createDocument($collection($a['collection']), Values::document($a['document']));
                $fail = $a['fail'] ?? null;
                if ($fail !== null && ($a['fail_times'] ?? \PHP_INT_MAX) >= $calls) {
                    $class = match ($fail) {
                        'duplicate' => \Utopia\Database\Exception\Duplicate::class,
                        'timeout' => \Utopia\Database\Exception\Timeout::class,
                        default => \Utopia\Database\Exception::class,
                    };

                    throw new $class('callback failed');
                }

                return $document;
            });
        } catch (\Throwable $e) {
            return ['calls' => $calls, 'error' => ['class' => $e::class, 'message' => $e->getMessage()]];
        }

        return ['calls' => $calls, 'result' => Values::encode($result)];
    },
    'adapter.ping' => static fn (array $a, Session $s) => $db($a, $s)->ping(),
    'adapter.reconnect' => static function (array $a, Session $s) use ($db) {
        $db($a, $s)->reconnect();

        return null;
    },
    'adapter.connection_id' => static fn (array $a, Session $s) => $db($a, $s)->getConnectionId(),

    // ----- Databases and collections -----

    'adapter.create' => static fn (array $a, Session $s) => $db($a, $s)->create((string) ($a['name'] ?? $s->ns)),
    'adapter.exists' => static fn (array $a, Session $s) => $db($a, $s)->exists(
        (string) ($a['database'] ?? $s->ns),
        isset($a['collection']) ? (string) $a['collection'] : null
    ),
    'adapter.list' => static fn (array $a, Session $s) => Values::encode($db($a, $s)->list()),
    'adapter.delete' => static fn (array $a, Session $s) => $db($a, $s)->delete((string) ($a['name'] ?? $s->ns)),
    'adapter.create_collection' => static fn (array $a, Session $s) => $db($a, $s)->createCollection(
        (string) $a['name'],
        Values::documents($a['attributes'] ?? []),
        Values::documents($a['indexes'] ?? []),
    ),
    'adapter.delete_collection' => static fn (array $a, Session $s) => $db($a, $s)->deleteCollection((string) $a['id']),
    'adapter.analyze_collection' => static fn (array $a, Session $s) => $db($a, $s)->analyzeCollection((string) $a['collection']),
    'adapter.size_of_collection' => static fn (array $a, Session $s) => $db($a, $s)->getSizeOfCollection((string) $a['collection']),
    'adapter.size_of_collection_on_disk' => static fn (array $a, Session $s) => $db($a, $s)->getSizeOfCollectionOnDisk((string) $a['collection']),
    'adapter.schema_attributes' => static fn (array $a, Session $s) => Values::encode($db($a, $s)->getSchemaAttributes((string) $a['collection'])),
    'adapter.schema_indexes' => static fn (array $a, Session $s) => Values::encode($db($a, $s)->getSchemaIndexes((string) $a['collection'])),

    // ----- Attributes, relationships, indexes -----

    'adapter.create_attribute' => static fn (array $a, Session $s) => $db($a, $s)->createAttribute(
        (string) $a['collection'],
        (string) $a['id'],
        (string) $a['type'],
        (int) ($a['size'] ?? 0),
        (bool) ($a['signed'] ?? true),
        (bool) ($a['array'] ?? false),
        (bool) ($a['required'] ?? false),
    ),
    'adapter.create_attributes' => static fn (array $a, Session $s) => $db($a, $s)->createAttributes(
        (string) $a['collection'],
        Values::decodeArray(Values::list($a['attributes'] ?? [])),
    ),
    'adapter.update_attribute' => static fn (array $a, Session $s) => $db($a, $s)->updateAttribute(
        (string) $a['collection'],
        (string) $a['id'],
        (string) $a['type'],
        (int) ($a['size'] ?? 0),
        (bool) ($a['signed'] ?? true),
        (bool) ($a['array'] ?? false),
        isset($a['new_key']) ? (string) $a['new_key'] : null,
        (bool) ($a['required'] ?? false),
    ),
    'adapter.delete_attribute' => static fn (array $a, Session $s) => $db($a, $s)->deleteAttribute((string) $a['collection'], (string) $a['id']),
    'adapter.rename_attribute' => static fn (array $a, Session $s) => $db($a, $s)->renameAttribute((string) $a['collection'], (string) $a['old'], (string) $a['new']),
    'adapter.create_relationship' => static fn (array $a, Session $s) => $db($a, $s)->createRelationship(
        (string) $a['collection'],
        (string) $a['related_collection'],
        (string) $a['type'],
        (bool) ($a['two_way'] ?? false),
        (string) ($a['id'] ?? ''),
        (string) ($a['two_way_key'] ?? ''),
    ),
    'adapter.update_relationship' => static fn (array $a, Session $s) => $db($a, $s)->updateRelationship(
        (string) $a['collection'],
        (string) $a['related_collection'],
        (string) $a['type'],
        (bool) ($a['two_way'] ?? false),
        (string) ($a['key'] ?? ''),
        (string) ($a['two_way_key'] ?? ''),
        (string) ($a['side'] ?? 'parent'),
        isset($a['new_key']) ? (string) $a['new_key'] : null,
        isset($a['new_two_way_key']) ? (string) $a['new_two_way_key'] : null,
    ),
    'adapter.delete_relationship' => static fn (array $a, Session $s) => $db($a, $s)->deleteRelationship(
        (string) $a['collection'],
        (string) $a['related_collection'],
        (string) $a['type'],
        (bool) ($a['two_way'] ?? false),
        (string) ($a['key'] ?? ''),
        (string) ($a['two_way_key'] ?? ''),
        (string) ($a['side'] ?? 'parent'),
    ),
    'adapter.create_index' => static function (array $a, Session $s) use ($db, $strings) {
        $types = [];
        foreach (Values::decodeArray($a['attribute_types'] ?? []) as $attribute => $type) {
            $types[$attribute] = (string) $type;
        }

        return $db($a, $s)->createIndex(
            (string) $a['collection'],
            (string) $a['id'],
            (string) $a['type'],
            $strings($a['attributes'] ?? []),
            Values::list(Values::decode($a['lengths'] ?? [])),
            Values::list(Values::decode($a['orders'] ?? [])),
            $types,
            $strings($a['collation'] ?? []),
            (int) ($a['ttl'] ?? 1),
        );
    },
    'adapter.delete_index' => static fn (array $a, Session $s) => $db($a, $s)->deleteIndex((string) $a['collection'], (string) $a['id']),
    'adapter.rename_index' => static fn (array $a, Session $s) => $db($a, $s)->renameIndex((string) $a['collection'], (string) $a['old'], (string) $a['new']),

    // ----- Documents -----

    'adapter.get_document' => static fn (array $a, Session $s) => Values::encode($db($a, $s)->getDocument(
        $collection($a['collection']),
        (string) $a['id'],
        Values::queries($a['queries'] ?? []),
        (bool) ($a['for_update'] ?? false),
    )),
    'adapter.create_document' => static fn (array $a, Session $s) => Values::encode($db($a, $s)->createDocument(
        $collection($a['collection']),
        Values::document($a['document']),
    )),
    'adapter.create_documents' => static function (array $a, Session $s) use ($db, $collection) {
        $adapter = $db($a, $s);
        $create = static fn () => $adapter->createDocuments($collection($a['collection']), Values::documents($a['documents'] ?? []));

        return Values::encode(($a['skip_duplicates'] ?? false) ? $adapter->skipDuplicates($create) : $create());
    },
    'adapter.update_document' => static fn (array $a, Session $s) => Values::encode($db($a, $s)->updateDocument(
        $collection($a['collection']),
        (string) $a['id'],
        Values::document($a['document']),
        (bool) ($a['skip_permissions'] ?? false),
    )),
    'adapter.update_documents' => static fn (array $a, Session $s) => $db($a, $s)->updateDocuments(
        $collection($a['collection']),
        Values::document($a['updates']),
        Values::documents($a['documents'] ?? []),
    ),
    'adapter.upsert_documents' => static function (array $a, Session $s) use ($db, $collection) {
        $changes = [];
        foreach (Values::list($a['changes'] ?? []) as $change) {
            $changes[] = new Change(Values::document($change['old'] ?? []), Values::document($change['new'] ?? []));
        }

        return Values::encode($db($a, $s)->upsertDocuments($collection($a['collection']), (string) ($a['attribute'] ?? ''), $changes));
    },
    'adapter.get_sequences' => static fn (array $a, Session $s) => Values::encode($db($a, $s)->getSequences(
        (string) $a['collection'],
        Values::documents($a['documents'] ?? []),
    )),
    'adapter.delete_document' => static fn (array $a, Session $s) => $db($a, $s)->deleteDocument((string) $a['collection'], (string) $a['id']),
    'adapter.delete_documents' => static fn (array $a, Session $s) => $db($a, $s)->deleteDocuments(
        (string) $a['collection'],
        Values::list(Values::decode($a['sequences'] ?? [])),
        \array_map(\strval(...), Values::list($a['permission_ids'] ?? [])),
    ),
    'adapter.increase_document_attribute' => static fn (array $a, Session $s) => $db($a, $s)->increaseDocumentAttribute(
        (string) $a['collection'],
        (string) $a['id'],
        (string) $a['attribute'],
        $a['value'],
        (string) $a['updated_at'],
        $a['min'] ?? null,
        $a['max'] ?? null,
    ),
    'adapter.find' => static fn (array $a, Session $s) => Values::encode($db($a, $s)->find(
        $collection($a['collection']),
        $queries($a),
        \array_key_exists('limit', $a) ? ($a['limit'] === null ? null : (int) $a['limit']) : 25,
        isset($a['offset']) ? (int) $a['offset'] : null,
        $strings($a['order_attributes'] ?? []),
        $strings($a['order_types'] ?? []),
        Values::decodeArray($a['cursor'] ?? []),
        (string) ($a['cursor_direction'] ?? 'after'),
        (string) ($a['for_permission'] ?? 'read'),
    )),
    'adapter.count' => static fn (array $a, Session $s) => $db($a, $s)->count(
        $collection($a['collection']),
        $queries($a),
        isset($a['max']) ? (int) $a['max'] : null,
    ),
    'adapter.sum' => static fn (array $a, Session $s) => $db($a, $s)->sum(
        $collection($a['collection']),
        (string) $a['attribute'],
        $queries($a),
        isset($a['max']) ? (int) $a['max'] : null,
    ),

    // ----- Values -----

    'adapter.casting_before' => static fn (array $a, Session $s) => Values::encode($db($a, $s)->castingBefore($collection($a['collection']), Values::document($a['document']))),
    'adapter.casting_after' => static fn (array $a, Session $s) => Values::encode($db($a, $s)->castingAfter($collection($a['collection']), Values::document($a['document']))),
    'adapter.set_utc_datetime' => static fn (array $a, Session $s) => $db($a, $s)->setUTCDatetime((string) $a['value']),
    'adapter.decode_point' => static fn (array $a, Session $s) => $db($a, $s)->decodePoint((string) $a['wkb']),
    'adapter.decode_linestring' => static fn (array $a, Session $s) => $db($a, $s)->decodeLinestring((string) $a['wkb']),
    'adapter.decode_polygon' => static fn (array $a, Session $s) => $db($a, $s)->decodePolygon((string) $a['wkb']),
    'adapter.spatial_type' => static fn (array $a, Session $s) => $db($a, $s)->getSpatialTypeFromWKT((string) $a['wkt']),

    // ----- Limits and capabilities -----

    'adapter.limits' => static function (array $a, Session $s) use ($db) {
        $p = $db($a, $s);

        return [
            'limitForString' => $p->getLimitForString(),
            'limitForInt' => $p->getLimitForInt(),
            'limitForBigInt' => $p->getLimitForBigInt(),
            'limitForAttributes' => $p->getLimitForAttributes(),
            'limitForIndexes' => $p->getLimitForIndexes(),
            'maxIndexLength' => $p->getMaxIndexLength(),
            'maxVarcharLength' => $p->getMaxVarcharLength(),
            'maxUIDLength' => $p->getMaxUIDLength(),
            'minDateTime' => $p->getMinDateTime()->format('Y-m-d H:i:s.v P'),
            'maxDateTime' => $p->getMaxDateTime()->format('Y-m-d H:i:s.v P'),
            'idAttributeType' => $p->getIdAttributeType(),
            'countOfDefaultAttributes' => $p->getCountOfDefaultAttributes(),
            'countOfDefaultIndexes' => $p->getCountOfDefaultIndexes(),
            'documentSizeLimit' => $p->getDocumentSizeLimit(),
            'keywords' => $p->getKeywords(),
            'internalIndexesKeys' => $p->getInternalIndexesKeys(),
        ];
    },
    'adapter.counts' => static function (array $a, Session $s) use ($db, $collection) {
        $p = $db($a, $s);
        $c = $collection($a['collection']);

        return [
            'countOfAttributes' => $p->getCountOfAttributes($c),
            'countOfIndexes' => $p->getCountOfIndexes($c),
            'attributeWidth' => $p->getAttributeWidth($c),
        ];
    },
    'adapter.capabilities' => static function (array $a, Session $s) use ($db) {
        $p = $db($a, $s);

        return [
            'schemas' => $p->getSupportForSchemas(),
            'attributes' => $p->getSupportForAttributes(),
            'setAttributes' => $p->setSupportForAttributes(false),
            'schemaAttributes' => $p->getSupportForSchemaAttributes(),
            'schemaIndexes' => $p->getSupportForSchemaIndexes(),
            'index' => $p->getSupportForIndex(),
            'indexArray' => $p->getSupportForIndexArray(),
            'castIndexArray' => $p->getSupportForCastIndexArray(),
            'uniqueIndex' => $p->getSupportForUniqueIndex(),
            'fulltextIndex' => $p->getSupportForFulltextIndex(),
            'fulltextWildcardIndex' => $p->getSupportForFulltextWildcardIndex(),
            'casting' => $p->getSupportForCasting(),
            'numericCasting' => $p->getSupportForNumericCasting(),
            'queryContains' => $p->getSupportForQueryContains(),
            'jsonOverlaps' => $p->getSupportForJSONOverlaps(),
            'timeouts' => $p->getSupportForTimeouts(),
            'relationships' => $p->getSupportForRelationships(),
            'updateLock' => $p->getSupportForUpdateLock(),
            'batchOperations' => $p->getSupportForBatchOperations(),
            'attributeResizing' => $p->getSupportForAttributeResizing(),
            'getConnectionId' => $p->getSupportForGetConnectionId(),
            'upserts' => $p->getSupportForUpserts(),
            'upsertOnUniqueIndex' => $p->getSupportForUpsertOnUniqueIndex(),
            'vectors' => $p->getSupportForVectors(),
            'cacheSkipOnFailure' => $p->getSupportForCacheSkipOnFailure(),
            'caching' => $p->getSupportForCaching(),
            'reconnection' => $p->getSupportForReconnection(),
            'hostname' => $p->getSupportForHostname(),
            'batchCreateAttributes' => $p->getSupportForBatchCreateAttributes(),
            'spatialAttributes' => $p->getSupportForSpatialAttributes(),
            'object' => $p->getSupportForObject(),
            'objectIndexes' => $p->getSupportForObjectIndexes(),
            'spatialIndexNull' => $p->getSupportForSpatialIndexNull(),
            'operators' => $p->getSupportForOperators(),
            'optionalSpatialAttributeWithExistingRows' => $p->getSupportForOptionalSpatialAttributeWithExistingRows(),
            'spatialIndexOrder' => $p->getSupportForSpatialIndexOrder(),
            'spatialAxisOrder' => $p->getSupportForSpatialAxisOrder(),
            'boundaryInclusiveContains' => $p->getSupportForBoundaryInclusiveContains(),
            'distanceBetweenMultiDimensionGeometryInMeters' => $p->getSupportForDistanceBetweenMultiDimensionGeometryInMeters(),
            'multipleFulltextIndexes' => $p->getSupportForMultipleFulltextIndexes(),
            'identicalIndexes' => $p->getSupportForIdenticalIndexes(),
            'orderRandom' => $p->getSupportForOrderRandom(),
            'unsignedBigInt' => $p->getSupportForUnsignedBigInt(),
            'internalCasting' => $p->getSupportForInternalCasting(),
            'utcCasting' => $p->getSupportForUTCCasting(),
            'integerBooleans' => $p->getSupportForIntegerBooleans(),
            'alterLocks' => $p->getSupportForAlterLocks(),
            'nonUtfCharacters' => $p->getSupportNonUtfCharacters(),
            'trigramIndex' => $p->getSupportForTrigramIndex(),
            'pcreRegex' => $p->getSupportForPCRERegex(),
            'posixRegex' => $p->getSupportForPOSIXRegex(),
            'regex' => $p->getSupportForRegex(),
            'ttlIndexes' => $p->getSupportForTTLIndexes(),
            'transactionRetries' => $p->getSupportForTransactionRetries(),
            'nestedTransactions' => $p->getSupportForNestedTransactions(),
            'likeOperator' => $p->getLikeOperator(),
            'regexOperator' => $p->getRegexOperator(),
        ];
    },

    // ----- Fuzzing: one adapter per namespace, over a seeded collection -----

    'adapter.fuzz_find' => static function (array $a, Session $s) use ($fuzzAdapter, $queries, $strings) {
        $adapter = $fuzzAdapter($s, (string) ($a['driver'] ?? 'postgres'));
        $authorization = new Authorization();
        if (\array_key_exists('roles', $a)) {
            $authorization->cleanRoles();
            foreach (Values::list($a['roles']) as $role) {
                $authorization->addRole((string) $role);
            }
        } else {
            $authorization->setStatus(false);
        }
        $adapter->setAuthorization($authorization);

        return Values::encode($adapter->find(
            new Document(['$id' => 'fz']),
            $queries($a),
            \array_key_exists('limit', $a) ? ($a['limit'] === null ? null : (int) $a['limit']) : 25,
            isset($a['offset']) ? (int) $a['offset'] : null,
            $strings($a['order_attributes'] ?? []),
            $strings($a['order_types'] ?? []),
            Values::decodeArray($a['cursor'] ?? []),
            (string) ($a['cursor_direction'] ?? 'after'),
        ));
    },
    'adapter.fuzz_roundtrip' => static function (array $a, Session $s) use ($fuzzAdapter) {
        $driver = (string) ($a['driver'] ?? 'postgres');
        $adapter = $fuzzAdapter($s, $driver);
        $value = Values::decode($a['value'] ?? null);
        $authorization = new Authorization();
        $authorization->setStatus(false);
        $adapter->setAuthorization($authorization);
        $out = [];
        foreach ([
            static fn () => $adapter->createDocument(new Document(['$id' => 'rt']), new Document([
                '$id' => 'rt', '$permissions' => [], 't' => $value, 'a' => [$value],
                ...($driver === 'postgres' ? ['o' => ['k' => $value]] : []),
            ])),
            static fn () => $adapter->getDocument(new Document(['$id' => 'rt']), 'rt'),
            static fn () => $adapter->find(new Document(['$id' => 'rt']), [new \Utopia\Database\Query('equal', 't', [$value])]),
            static fn () => $adapter->deleteDocument('rt', 'rt'),
        ] as $call) {
            try {
                $out[] = Values::encode($call());
            } catch (\Throwable $e) {
                $out[] = ['error' => ['class' => $e::class, 'message' => $e->getMessage()]];
            }
        }

        return $out;
    },
];
