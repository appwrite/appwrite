<?php

namespace Tests\Compat\Database;

use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Session;
use Utopia\Cache\Adapter\None as NoneCache;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\Memory;
use Utopia\Database\Adapter\Postgres;
use Utopia\Database\Adapter\SQLite;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\PDO;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Queries\Documents as DocumentsQueries;
use Utopia\Database\Validator\Structure;

require_once __DIR__ . '/Values.php';

/**
 * Maps tests/compat/database/spec.json operations onto utopia-php/database.
 * Glue only. Operations live in ops/<area>.php, one file per area, each
 * returning `name => callable(array $args, Session $session): mixed`.
 * Arguments and results cross through Values (tagged documents, queries,
 * operators and objects).
 */
final class Adapter implements Base, Benchmarks
{
    /** The benchmarks' collection: a user profile, as Appwrite stores one. */
    public const ATTRIBUTES = '[
        {"$id": "name", "key": "name", "type": "string", "size": 128, "required": true, "signed": true, "array": false, "filters": []},
        {"$id": "email", "key": "email", "type": "string", "size": 256, "required": true, "signed": true, "array": false, "filters": []},
        {"$id": "age", "key": "age", "type": "integer", "size": 0, "required": false, "signed": true, "array": false, "filters": []},
        {"$id": "score", "key": "score", "type": "double", "size": 0, "required": false, "signed": true, "array": false, "filters": []},
        {"$id": "active", "key": "active", "type": "boolean", "size": 0, "required": false, "signed": true, "array": false, "filters": []},
        {"$id": "tags", "key": "tags", "type": "string", "size": 64, "required": false, "signed": true, "array": true, "filters": []},
        {"$id": "born", "key": "born", "type": "datetime", "size": 0, "required": false, "signed": false, "array": false, "filters": ["datetime"]}
    ]';

    public const INDEXES = '[{"$id": "by_name", "key": "by_name", "type": "key", "attributes": ["name"], "lengths": [], "orders": []}]';

    /** A user document of the collection. */
    public const DOCUMENT = '{"$id": "alice", "$collection": "users", "$permissions": ["read(\"any\")", "update(\"any\")"],
        "$createdAt": "2024-01-01T00:00:00.000+00:00", "$updatedAt": "2024-01-01T00:00:00.000+00:00",
        "name": "Alice", "email": "alice@example.com", "age": 31, "score": 4.5, "active": true,
        "tags": ["admin", "beta", "eu"], "born": "1990-05-17T10:00:00.000+00:00"}';

    /** A list request's queries, as the HTTP layer receives them. */
    public const QUERIES = [
        '{"method":"equal","attribute":"name","values":["Alice","Bob"]}',
        '{"method":"greaterThan","attribute":"age","values":[18]}',
        '{"method":"orderDesc","attribute":"score","values":[]}',
        '{"method":"limit","values":[25]}',
        '{"method":"select","values":["name","email","age"]}',
    ];

    public function operations(): array
    {
        $operations = [];
        foreach (glob(__DIR__ . '/ops/*.php') ?: [] as $file) {
            $operations += require $file;
        }

        return $operations;
    }

    public function benchmarks(): array
    {
        $collection = fn (): Document => new Document([
            '$id' => 'users',
            '$collection' => Database::METADATA,
            'attributes' => array_map(fn (array $a) => new Document($a), json_decode(self::ATTRIBUTES, true)),
            'indexes' => array_map(fn (array $i) => new Document($i), json_decode(self::INDEXES, true)),
        ]);
        $document = fn (): Document => new Document(json_decode(self::DOCUMENT, true));

        return [
            'query_parse' => ['Parse a list request\'s five queries from their JSON strings', function (Session $s) {
                return fn () => Query::parseQueries(self::QUERIES);
            }],
            'queries_validate' => ['Validate five parsed queries against a collection\'s attributes and indexes (Queries\\Documents)', function (Session $s) use ($collection) {
                $c = $collection();
                $validator = new DocumentsQueries($c->getAttribute('attributes'), $c->getAttribute('indexes'), Database::VAR_INTEGER);
                $queries = Query::parseQueries(self::QUERIES);

                return fn () => $validator->isValid($queries);
            }],
            'structure_validate' => ['Validate a user document against its collection\'s structure', function (Session $s) use ($collection, $document) {
                $validator = new Structure($collection(), Database::VAR_INTEGER);
                $doc = $document();

                return fn () => $validator->isValid($doc);
            }],
            'document_encode' => ['Encode a user document for the adapter (defaults, the datetime filter)', function (Session $s) use ($document) {
                [$db, $c] = self::database($s, 'document_encode');
                $doc = $document();

                return fn () => $db->encode($c, clone $doc);
            }],
            'document_decode' => ['Decode a stored user document (filters reversed, internal keys mapped back)', function (Session $s) use ($document) {
                [$db, $c] = self::database($s, 'document_decode');
                $stored = $db->encode($c, $document());

                return fn () => $db->decode($c, clone $stored);
            }],
            'create_document' => ['Create a user document: validation, encoding and one INSERT round trip', function (Session $s) use ($document) {
                [$db] = self::database($s, 'create_document');
                $doc = $document();

                return fn () => $db->createDocument('users', (clone $doc)->setAttribute('$id', ID::unique()));
            }],
            'create_documents' => ['Create ten user documents in one batch (one INSERT round trip)', function (Session $s) use ($document) {
                [$db] = self::database($s, 'create_documents');
                $doc = $document();

                return fn () => $db->createDocuments('users', array_map(fn () => (clone $doc)->setAttribute('$id', ID::unique()), range(1, 10)));
            }],
            'get_document' => ['Read a user document by id, uncached (one SELECT round trip)', function (Session $s) use ($document) {
                [$db] = self::database($s, 'get_document');
                $db->createDocument('users', $document());

                return fn () => $db->getDocument('users', 'alice');
            }],
            'find' => ['Find users by an indexed name, ordered and limited (one SELECT round trip over 100 rows)', function (Session $s) use ($document) {
                [$db] = self::database($s, 'find');
                $doc = $document();
                $db->createDocuments('users', array_map(fn (int $i) => (clone $doc)->setAttribute('$id', "u{$i}")->setAttribute('name', $i % 2 ? 'Alice' : 'Bob')->setAttribute('age', $i), range(1, 100)));
                $queries = Query::parseQueries(self::QUERIES);

                return fn () => $db->find('users', $queries);
            }],
            'update_document' => ['Update one attribute of a user document (a locking read and one UPDATE)', function (Session $s) use ($document) {
                [$db] = self::database($s, 'update_document');
                $db->createDocument('users', $document());
                $age = 0;

                return function () use ($db, &$age) {
                    return $db->updateDocument('users', 'alice', new Document(['age' => ++$age]));
                };
            }],
        ] + self::engineBenchmarks('sqlite', $document) + self::engineBenchmarks('memory', $document);
    }

    /**
     * The document round trips of `create_document`, `get_document`, `find`
     * and `update_document` on another engine, as `<engine>_<name>`.
     *
     * @param  callable(): Document  $document
     * @return array<string, array{string, callable(Session): callable}>
     */
    private static function engineBenchmarks(string $engine, callable $document): array
    {
        $descriptions = $engine === 'sqlite' ? [
            'create_document' => 'Create a user document on SQLite (a file in the runtime\'s container): one INSERT',
            'get_document' => 'Read a user document by id on SQLite, uncached (one SELECT)',
            'find' => 'Find users by an indexed name on SQLite, ordered and limited (one SELECT over 100 rows)',
            'update_document' => 'Update one attribute of a user document on SQLite (a read and one UPDATE)',
        ] : [
            'create_document' => 'Create a user document on the in-memory adapter',
            'get_document' => 'Read a user document by id on the in-memory adapter, uncached',
            'find' => 'Find users by name on the in-memory adapter, ordered and limited (a scan of 100 documents)',
            'update_document' => 'Update one attribute of a user document on the in-memory adapter',
        ];

        return [
            "{$engine}_create_document" => [$descriptions['create_document'], function (Session $s) use ($engine, $document) {
                [$db] = self::database($s, "{$engine}_create_document");
                $doc = $document();

                return fn () => $db->createDocument('users', (clone $doc)->setAttribute('$id', ID::unique()));
            }],
            "{$engine}_get_document" => [$descriptions['get_document'], function (Session $s) use ($engine, $document) {
                [$db] = self::database($s, "{$engine}_get_document");
                $db->createDocument('users', $document());

                return fn () => $db->getDocument('users', 'alice');
            }],
            "{$engine}_find" => [$descriptions['find'], function (Session $s) use ($engine, $document) {
                [$db] = self::database($s, "{$engine}_find");
                $doc = $document();
                $db->createDocuments('users', array_map(fn (int $i) => (clone $doc)->setAttribute('$id', "u{$i}")->setAttribute('name', $i % 2 ? 'Alice' : 'Bob')->setAttribute('age', $i), range(1, 100)));
                $queries = Query::parseQueries(self::QUERIES);

                return fn () => $db->find('users', $queries);
            }],
            "{$engine}_update_document" => [$descriptions['update_document'], function (Session $s) use ($engine, $document) {
                [$db] = self::database($s, "{$engine}_update_document");
                $db->createDocument('users', $document());
                $age = 0;

                return function () use ($db, &$age) {
                    return $db->updateDocument('users', 'alice', new Document(['age' => ++$age]));
                };
            }],
        ];
    }

    /**
     * A database of the benchmarks' collection on the compat PostgreSQL,
     * uncached, in a schema of its own (dropped first when a run left it).
     *
     * @return array{Database, Document}
     */
    private static function database(Session $s, string $name): array
    {
        $engine = explode('_', $name, 2)[0];
        if ($engine === 'sqlite') {
            // A file of the runtime's own container, removed first when a run left it.
            $path = "/tmp/compat_bench_php_{$name}.sqlite";
            if (file_exists($path)) {
                unlink($path);
            }
            $adapter = new SQLite(new PDO('sqlite:' . $path, null, null, SQLite::getPDOAttributes()));
        } elseif ($engine === 'memory') {
            $adapter = new Memory();
        } else {
            $url = parse_url($s->service('database-postgres'));
            $dsn = 'pgsql:host=' . ($url['host'] ?? '') . ';port=' . ($url['port'] ?? 5432) . ';dbname=' . ltrim($url['path'] ?? '', '/');
            $adapter = new Postgres(new PDO($dsn, urldecode($url['user'] ?? ''), urldecode($url['pass'] ?? ''), Postgres::getPDOAttributes()));
        }
        $db = new Database($adapter, new Cache(new NoneCache()));
        $authorization = new Authorization();
        $authorization->addRole('any');
        $db->setAuthorization($authorization);
        $db->setDatabase("compat_bench_php_{$name}")->setNamespace('bench');
        if ($db->exists()) {
            $db->delete();
        }
        $db->create();
        $attributes = array_map(fn (array $a) => new Document($a), json_decode(self::ATTRIBUTES, true));
        $indexes = array_map(fn (array $i) => new Document($i), json_decode(self::INDEXES, true));
        $collection = $db->createCollection('users', $attributes, $indexes, ['read("any")', 'create("any")', 'update("any")'], false);

        return [$db, $collection];
    }
}
