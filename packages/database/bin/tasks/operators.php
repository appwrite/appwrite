<?php

/**
 * Comprehensive Operator Performance Benchmark
 *
 * This script benchmarks the performance of ALL operator types against traditional
 * read-modify-write approaches across different database adapters.
 *
 * @example
 * docker compose exec tests bin/operators --adapter=mariadb --iterations=1000
 * docker compose exec tests bin/operators --adapter=postgres --iterations=1000 --seed=10000
 * docker compose exec tests bin/operators --adapter=sqlite --iterations=1000 --seed=5000
 *
 * The --seed parameter allows you to pre-populate the collection with a specified
 * number of documents to test how operators perform with varying amounts of existing data.
 */

global $cli;

use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Console;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Adapter\MySQL;
use Utopia\Database\Adapter\Postgres;
use Utopia\Database\Adapter\SQLite;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Operator;
use Utopia\Database\PDO;
use Utopia\Database\Query;
use Utopia\Validator\Integer;
use Utopia\Validator\Text;

$cli
    ->task('operators')
    ->desc('Benchmark operator performance vs traditional read-modify-write')
    ->param('adapter', '', new Text(0), 'Database adapter (mariadb, postgres, sqlite)')
    ->param('iterations', 1000, new Integer(true), 'Number of iterations per test', true)
    ->param('seed', 0, new Integer(true), 'Number of documents to pre-seed the collection with', true)
    ->param('name', 'operator_benchmark_' . uniqid(), new Text(0), 'Name of test database', true)
    ->action(function (string $adapter, int $iterations, int $seed, string $name) {
        $namespace = '_ns';
        $cache = new Cache(new NoCache());

        Console::info("=============================================================");
        Console::info("  OPERATOR PERFORMANCE BENCHMARK");
        Console::info("=============================================================");
        Console::info("Adapter: {$adapter}");
        Console::info("Iterations: {$iterations}");
        Console::info("Seed Documents: {$seed}");
        Console::info("Database: {$name}");
        Console::info("=============================================================\n");

        // ------------------------------------------------------------------
        // Adapter configuration
        // ------------------------------------------------------------------
        $dbAdapters = [
            'mariadb' => [
                'host' => 'mariadb',
                'port' => 3306,
                'user' => 'root',
                'pass' => 'password',
                'dsn' => static fn (string $host, int $port) => "mysql:host={$host};port={$port};charset=utf8mb4",
                'adapter' => MariaDB::class,
                'attrs' => MariaDB::getPDOAttributes(),
            ],
            'mysql' => [
                'host' => 'mysql',
                'port' => 3307,
                'user' => 'root',
                'pass' => 'password',
                'dsn' => static fn (string $host, int $port) => "mysql:host={$host};port={$port};charset=utf8mb4",
                'adapter' => MySQL::class,
                'attrs' => MySQL::getPDOAttributes(),
            ],
            'postgres' => [
                'host' => 'postgres',
                'port' => 5432,
                'user' => 'root',
                'pass' => 'password',
                'dsn' => static fn (string $host, int $port) => "pgsql:host={$host};port={$port}",
                'adapter' => Postgres::class,
                'attrs' => Postgres::getPDOAttributes(),
            ],
            'sqlite' => [
                'host' => ':memory:',
                'port' => 0,
                'user' => '',
                'pass' => '',
                'dsn' => static fn (string $host, int $port) => "sqlite::memory:",
                'adapter' => SQLite::class,
                'attrs' => [],
            ],
        ];

        if (!isset($dbAdapters[$adapter])) {
            Console::error("Adapter '{$adapter}' not supported. Available: mariadb, postgres, sqlite");
            return;
        }

        $cfg = $dbAdapters[$adapter];
        $dsn = ($cfg['dsn'])($cfg['host'], $cfg['port']);

        try {
            // Initialize database connection
            $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], $cfg['attrs']);

            $database = (new Database(new ($cfg['adapter'])($pdo), $cache))
                ->setDatabase($name)
                ->setNamespace($namespace);

            // Setup test environment
            setupTestEnvironment($database, $name, $seed);

            // Run all benchmarks
            $results = runAllBenchmarks($database, $iterations);

            // Display results
            displayResults($results, $adapter, $iterations, $seed);

            // Cleanup
            cleanup($database, $name);

            Console::success("\nBenchmark completed successfully!");

        } catch (\Throwable $e) {
            Console::error("Error: " . $e->getMessage());
            Console::error("Trace: " . $e->getTraceAsString());
            return;
        }
    });

/**
 * Setup test environment with collections and sample data
 */
function setupTestEnvironment(Database $database, string $name, int $seed): void
{
    Console::info("Setting up test environment...");

    // Delete database if it exists
    if ($database->exists($name)) {
        $database->delete($name);
    }
    $database->create();

    $authorization->addRole(Role::any()->toString());

    // Create test collection
    $database->createCollection('operators_test', permissions: [
        Permission::create(Role::any()),
        Permission::read(Role::any()),
        Permission::update(Role::any()),
        Permission::delete(Role::any()),
    ]);

    // Create attributes for all operator types
    // Numeric attributes
    $database->createAttribute('operators_test', 'counter', Database::VAR_INTEGER, 0, false, 0);
    $database->createAttribute('operators_test', 'score', Database::VAR_FLOAT, 0, false, 0.0);
    $database->createAttribute('operators_test', 'multiplier', Database::VAR_FLOAT, 0, false, 1.0);
    $database->createAttribute('operators_test', 'divider', Database::VAR_FLOAT, 0, false, 100.0);
    $database->createAttribute('operators_test', 'modulo_val', Database::VAR_INTEGER, 0, false, 100);
    $database->createAttribute('operators_test', 'power_val', Database::VAR_FLOAT, 0, false, 2.0);

    // String attributes
    $database->createAttribute('operators_test', 'name', Database::VAR_STRING, 200, false, 'test');
    $database->createAttribute('operators_test', 'text', Database::VAR_STRING, 500, false, 'initial');
    $database->createAttribute('operators_test', 'description', Database::VAR_STRING, 500, false, 'foo bar baz');

    // Boolean attributes
    $database->createAttribute('operators_test', 'active', Database::VAR_BOOLEAN, 0, false, true);

    // Array attributes
    $database->createAttribute('operators_test', 'tags', Database::VAR_STRING, 50, false, null, true, true);
    $database->createAttribute('operators_test', 'numbers', Database::VAR_INTEGER, 0, false, null, true, true);
    $database->createAttribute('operators_test', 'items', Database::VAR_STRING, 50, false, null, true, true);

    // Date attributes
    $database->createAttribute('operators_test', 'created_at', Database::VAR_DATETIME, 0, false, null, false, false, null, [], ['datetime']);
    $database->createAttribute('operators_test', 'updated_at', Database::VAR_DATETIME, 0, false, null, false, false, null, [], ['datetime']);

    // Seed documents if requested
    if ($seed > 0) {
        seedDocuments($database, $seed);
    }

    Console::success("Test environment setup complete.\n");
}

/**
 * Seed the collection with a specified number of documents
 */
function seedDocuments(Database $database, int $count): void
{
    Console::info("Seeding {$count} documents...");

    $batchSize = 100; // Insert in batches for better performance
    $batches = (int) ceil($count / $batchSize);

    $seedStart = microtime(true);

    for ($batch = 0; $batch < $batches; $batch++) {
        $docs = [];
        $remaining = min($batchSize, $count - ($batch * $batchSize));

        for ($i = 0; $i < $remaining; $i++) {
            $docNum = ($batch * $batchSize) + $i;
            $docs[] = new Document([
                '$id' => 'seed_' . $docNum,
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                ],
                'counter' => rand(0, 1000),
                'score' => round(rand(0, 10000) / 100, 2),
                'multiplier' => round(rand(50, 200) / 100, 2),
                'divider' => round(rand(5000, 15000) / 100, 2),
                'modulo_val' => rand(50, 200),
                'power_val' => round(rand(100, 300) / 100, 2),
                'name' => 'seed_doc_' . $docNum,
                'text' => 'Seed text for document ' . $docNum,
                'description' => 'This is seed document ' . $docNum . ' with some foo bar baz content',
                'active' => (bool) rand(0, 1),
                'tags' => ['seed', 'tag' . ($docNum % 10), 'category' . ($docNum % 5)],
                'numbers' => [rand(1, 10), rand(11, 20), rand(21, 30)],
                'items' => ['item' . ($docNum % 3), 'item' . ($docNum % 7)],
                'created_at' => DateTime::now(),
                'updated_at' => DateTime::now(),
            ]);
        }

        // Bulk insert documents
        $database->createDocuments('operators_test', $docs);

        // Show progress
        $progress = (($batch + 1) * $batchSize);
        $current = min($progress, $count);
        Console::log("  Seeded {$current}/{$count} documents...");
    }

    $seedTime = microtime(true) - $seedStart;
    Console::success("Seeding completed in " . number_format($seedTime, 2) . "s\n");
}

/**
 * Run all operator benchmarks
 */
function runAllBenchmarks(Database $database, int $iterations): array
{
    $results = [];
    $failed = [];

    Console::info("Starting benchmarks...\n");

    // Helper function to safely run benchmarks
    $safeBenchmark = function (string $name, callable $benchmark) use (&$results, &$failed) {
        try {
            $results[$name] = $benchmark();
        } catch (\Throwable $e) {
            $failed[$name] = $e->getMessage();
            Console::warning("  ⚠️  {$name} failed: " . $e->getMessage());
        }
    };

    Console::info("=== Operation Type Benchmarks ===\n");

    $safeBenchmark('UPDATE_SINGLE_NO_OPS', fn () => benchmarkOperation(
        $database,
        $iterations,
        'updateDocument',
        false,
        false
    ));

    $safeBenchmark('UPDATE_SINGLE_WITH_OPS', fn () => benchmarkOperation(
        $database,
        $iterations,
        'updateDocument',
        false,
        true
    ));

    $safeBenchmark('UPDATE_BULK_NO_OPS', fn () => benchmarkOperation(
        $database,
        $iterations,
        'updateDocuments',
        false,
        false
    ));

    $safeBenchmark('UPDATE_BULK_WITH_OPS', fn () => benchmarkOperation(
        $database,
        $iterations,
        'updateDocuments',
        false,
        true
    ));

    $safeBenchmark('UPSERT_SINGLE_NO_OPS', fn () => benchmarkOperation(
        $database,
        $iterations,
        'upsertDocument',
        false,
        false
    ));

    $safeBenchmark('UPSERT_SINGLE_WITH_OPS', fn () => benchmarkOperation(
        $database,
        $iterations,
        'upsertDocument',
        false,
        true
    ));

    $safeBenchmark('UPSERT_BULK_NO_OPS', fn () => benchmarkOperation(
        $database,
        $iterations,
        'upsertDocuments',
        false,
        false
    ));

    $safeBenchmark('UPSERT_BULK_WITH_OPS', fn () => benchmarkOperation(
        $database,
        $iterations,
        'upsertDocuments',
        false,
        true
    ));

    Console::info("\n=== Operator Type Benchmarks ===\n");

    // Numeric operators
    $safeBenchmark('INCREMENT', fn () => benchmarkOperatorAcrossOperations(
        $database,
        $iterations,
        'INCREMENT',
        'counter',
        Operator::increment(1),
        function ($doc) {
            $doc->setAttribute('counter', $doc->getAttribute('counter', 0) + 1);
            return $doc;
        },
        ['counter' => 0]
    ));

    $safeBenchmark('DECREMENT', fn () => benchmarkOperatorAcrossOperations(
        $database,
        $iterations,
        'DECREMENT',
        'counter',
        Operator::decrement(1),
        function ($doc) {
            $doc->setAttribute('counter', $doc->getAttribute('counter', 100) - 1);
            return $doc;
        },
        ['counter' => 100]
    ));

    $safeBenchmark('MULTIPLY', fn () => benchmarkOperatorAcrossOperations(
        $database,
        $iterations,
        'MULTIPLY',
        'multiplier',
        Operator::multiply(1.1),
        function ($doc) {
            $doc->setAttribute('multiplier', $doc->getAttribute('multiplier', 1.0) * 1.1);
            return $doc;
        },
        ['multiplier' => 1.0]
    ));

    $safeBenchmark('DIVIDE', fn () => benchmarkOperatorAcrossOperations(
        $database,
        $iterations,
        'DIVIDE',
        'divider',
        Operator::divide(1.1),
        function ($doc) {
            $doc->setAttribute('divider', $doc->getAttribute('divider', 100.0) / 1.1);
            return $doc;
        },
        ['divider' => 100.0]
    ));

    $safeBenchmark('MODULO', fn () => benchmarkOperatorAcrossOperations(
        $database,
        $iterations,
        'MODULO',
        'modulo_val',
        Operator::modulo(7),
        function ($doc) {
            $val = $doc->getAttribute('modulo_val', 100);
            $doc->setAttribute('modulo_val', $val % 7);
            return $doc;
        },
        ['modulo_val' => 100]
    ));

    $safeBenchmark('POWER', fn () => benchmarkOperatorAcrossOperations(
        $database,
        $iterations,
        'POWER',
        'power_val',
        Operator::power(1.001),
        function ($doc) {
            $doc->setAttribute('power_val', pow($doc->getAttribute('power_val', 2.0), 1.001));
            return $doc;
        },
        ['power_val' => 2.0]
    ));

    // String operators
    $safeBenchmark('CONCAT', fn () => benchmarkOperatorAcrossOperations(
        $database,
        $iterations,
        'CONCAT',
        'text',
        Operator::stringConcat('x'),
        function ($doc) {
            $doc->setAttribute('text', $doc->getAttribute('text', 'initial') . 'x');
            return $doc;
        },
        ['text' => 'initial']
    ));

    $safeBenchmark('REPLACE', fn () => benchmarkOperatorAcrossOperations(
        $database,
        $iterations,
        'REPLACE',
        'description',
        Operator::stringReplace('foo', 'bar'),
        function ($doc) {
            $doc->setAttribute('description', str_replace('foo', 'bar', $doc->getAttribute('description', 'foo bar baz')));
            return $doc;
        },
        ['description' => 'foo bar baz']
    ));

    // Boolean operators
    $safeBenchmark('TOGGLE', fn () => benchmarkOperatorAcrossOperations(
        $database,
        $iterations,
        'TOGGLE',
        'active',
        Operator::toggle(),
        function ($doc) {
            $doc->setAttribute('active', !$doc->getAttribute('active', true));
            return $doc;
        },
        ['active' => true]
    ));

    // Array operators
    $safeBenchmark('ARRAY_APPEND', fn () => benchmarkOperatorAcrossOperations(
        $database,
        $iterations,
        'ARRAY_APPEND',
        'tags',
        Operator::arrayAppend(['new']),
        function ($doc) {
            $tags = $doc->getAttribute('tags', ['initial']);
            $tags[] = 'new';
            $doc->setAttribute('tags', $tags);
            return $doc;
        },
        ['tags' => ['initial']]
    ));

    $safeBenchmark('ARRAY_PREPEND', fn () => benchmarkOperatorAcrossOperations(
        $database,
        $iterations,
        'ARRAY_PREPEND',
        'tags',
        Operator::arrayPrepend(['first']),
        function ($doc) {
            $tags = $doc->getAttribute('tags', ['initial']);
            array_unshift($tags, 'first');
            $doc->setAttribute('tags', $tags);
            return $doc;
        },
        ['tags' => ['initial']]
    ));

    $safeBenchmark('ARRAY_INSERT', fn () => benchmarkOperatorAcrossOperations(
        $database,
        $iterations,
        'ARRAY_INSERT',
        'numbers',
        Operator::arrayInsert(1, 99),
        function ($doc) {
            $numbers = $doc->getAttribute('numbers', [1, 2, 3]);
            array_splice($numbers, 1, 0, [99]);
            $doc->setAttribute('numbers', $numbers);
            return $doc;
        },
        ['numbers' => [1, 2, 3]]
    ));

    $safeBenchmark('ARRAY_REMOVE', fn () => benchmarkOperatorAcrossOperations(
        $database,
        $iterations,
        'ARRAY_REMOVE',
        'tags',
        Operator::arrayRemove('unwanted'),
        function ($doc) {
            $tags = $doc->getAttribute('tags', ['keep', 'unwanted', 'also']);
            $tags = array_values(array_filter($tags, fn ($t) => $t !== 'unwanted'));
            $doc->setAttribute('tags', $tags);
            return $doc;
        },
        ['tags' => ['keep', 'unwanted', 'also']]
    ));

    $safeBenchmark('ARRAY_UNIQUE', fn () => benchmarkOperatorAcrossOperations(
        $database,
        $iterations,
        'ARRAY_UNIQUE',
        'tags',
        Operator::arrayUnique(),
        function ($doc) {
            $tags = $doc->getAttribute('tags', ['a', 'b', 'a', 'c', 'b']);
            $doc->setAttribute('tags', array_values(array_unique($tags)));
            return $doc;
        },
        ['tags' => ['a', 'b', 'a', 'c', 'b']]
    ));

    $safeBenchmark('ARRAY_INTERSECT', fn () => benchmarkOperatorAcrossOperations(
        $database,
        $iterations,
        'ARRAY_INTERSECT',
        'tags',
        Operator::arrayIntersect(['keep', 'this']),
        function ($doc) {
            $tags = $doc->getAttribute('tags', ['keep', 'remove', 'this']);
            $doc->setAttribute('tags', array_values(array_intersect($tags, ['keep', 'this'])));
            return $doc;
        },
        ['tags' => ['keep', 'remove', 'this']]
    ));

    $safeBenchmark('ARRAY_DIFF', fn () => benchmarkOperatorAcrossOperations(
        $database,
        $iterations,
        'ARRAY_DIFF',
        'tags',
        Operator::arrayDiff(['remove']),
        function ($doc) {
            $tags = $doc->getAttribute('tags', ['keep', 'remove', 'this']);
            $doc->setAttribute('tags', array_values(array_diff($tags, ['remove'])));
            return $doc;
        },
        ['tags' => ['keep', 'remove', 'this']]
    ));

    $safeBenchmark('ARRAY_FILTER', fn () => benchmarkOperatorAcrossOperations(
        $database,
        $iterations,
        'ARRAY_FILTER',
        'numbers',
        Operator::arrayFilter('greaterThan', 5),
        function ($doc) {
            $numbers = $doc->getAttribute('numbers', [1, 3, 5, 7, 9]);
            $doc->setAttribute('numbers', array_values(array_filter($numbers, fn ($n) => $n > 5)));
            return $doc;
        },
        ['numbers' => [1, 3, 5, 7, 9]]
    ));

    // Date operators
    $safeBenchmark('DATE_ADD_DAYS', fn () => benchmarkOperatorAcrossOperations(
        $database,
        $iterations,
        'DATE_ADD_DAYS',
        'created_at',
        Operator::dateAddDays(1),
        function ($doc) {
            $date = new \DateTime($doc->getAttribute('created_at', DateTime::now()));
            $date->modify('+1 day');
            $doc->setAttribute('created_at', DateTime::format($date));
            return $doc;
        },
        ['created_at' => DateTime::now()]
    ));

    $safeBenchmark('DATE_SUB_DAYS', fn () => benchmarkOperatorAcrossOperations(
        $database,
        $iterations,
        'DATE_SUB_DAYS',
        'updated_at',
        Operator::dateSubDays(1),
        function ($doc) {
            $date = new \DateTime($doc->getAttribute('updated_at', DateTime::now()));
            $date->modify('-1 day');
            $doc->setAttribute('updated_at', DateTime::format($date));
            return $doc;
        },
        ['updated_at' => DateTime::now()]
    ));

    $safeBenchmark('DATE_SET_NOW', fn () => benchmarkOperatorAcrossOperations(
        $database,
        $iterations,
        'DATE_SET_NOW',
        'updated_at',
        Operator::dateSetNow(),
        function ($doc) {
            $doc->setAttribute('updated_at', DateTime::now());
            return $doc;
        },
        ['updated_at' => DateTime::now()]
    ));

    // Report any failures
    if (!empty($failed)) {
        Console::warning("\n⚠️  Some benchmarks failed:");
        foreach ($failed as $name => $error) {
            Console::warning("  - {$name}: " . substr($error, 0, 100));
        }
    }

    return $results;
}

/**
 * Benchmark operation type (update vs upsert, single vs bulk) with and without operators
 */
function benchmarkOperation(
    Database $database,
    int $iterations,
    string $operation,
    bool $isBulk,
    bool $useOperators
): array {
    $displayName = strtoupper($operation) . ($useOperators ? ' (with ops)' : ' (no ops)');
    Console::info("Benchmarking {$displayName}...");

    $docId = 'bench_op_' . strtolower($operation) . '_' . ($useOperators ? 'ops' : 'noops');

    // Create initial document
    $baseData = [
        '$permissions' => [
            Permission::read(Role::any()),
            Permission::update(Role::any()),
        ],
        'counter' => 0,
        'name' => 'test',
        'score' => 100.0
    ];

    $database->createDocument('operators_test', new Document(array_merge(['$id' => $docId], $baseData)));

    $memBefore = memory_get_usage(true);
    $start = microtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        if ($operation === 'updateDocument') {
            if ($useOperators) {
                $database->updateDocument('operators_test', $docId, new Document([
                    'counter' => Operator::increment(1)
                ]));
            } else {
                $database->updateDocument('operators_test', $docId, new Document([
                    'counter' => $i + 1
                ]));
            }
        } elseif ($operation === 'updateDocuments') {
            if ($useOperators) {
                $updates = new Document(['counter' => Operator::increment(1)]);
                // For a single doc benchmark, just use that one ID
                $queries = [Query::equal('$id', [$docId])];
                $database->updateDocuments('operators_test', $updates, $queries);
            } else {
                // Without operators, we need to read and update individually
                // because updateDocuments with queries would apply the same value to all matching docs
                $doc = $database->getDocument('operators_test', $docId);
                $database->updateDocument('operators_test', $docId, new Document([
                    'counter' => $i + 1
                ]));
            }
        } elseif ($operation === 'upsertDocument') {
            if ($useOperators) {
                $database->upsertDocument('operators_test', new Document([
                    '$id' => $docId,
                    'counter' => Operator::increment(1),
                    'name' => 'test',
                    'score' => 100.0
                ]));
            } else {
                $database->upsertDocument('operators_test', new Document([
                    '$id' => $docId,
                    'counter' => $i + 1,
                    'name' => 'test',
                    'score' => 100.0
                ]));
            }
        } elseif ($operation === 'upsertDocuments') {
            if ($useOperators) {
                $database->upsertDocuments('operators_test', [
                    new Document(['$id' => $docId, 'counter' => Operator::increment(1), 'name' => 'test', 'score' => 100.0])
                ]);
            } else {
                $database->upsertDocuments('operators_test', [
                    new Document(['$id' => $docId, 'counter' => $i + 1, 'name' => 'test', 'score' => 100.0])
                ]);
            }
        }
    }

    $timeOp = microtime(true) - $start;
    $memOp = memory_get_usage(true) - $memBefore;

    // Cleanup
    $database->deleteDocument('operators_test', $docId);

    Console::success("  Time: {$timeOp}s | Memory: " . formatBytes($memOp));

    return [
        'operation' => $operation,
        'use_operators' => $useOperators,
        'time' => $timeOp,
        'memory' => $memOp,
        'iterations' => $iterations,
    ];
}

/**
 * Benchmark an operator across all operation types (update, bulk update, upsert, bulk upsert)
 */
function benchmarkOperatorAcrossOperations(
    Database $database,
    int $iterations,
    string $operatorName,
    string $attribute,
    Operator $operator,
    callable $traditionalModifier,
    array $initialData
): array {
    $results = [];
    $operationTypes = [
        'UPDATE' => 'updateDocument',
        'BULK_UPDATE' => 'updateDocuments',
        'UPSERT' => 'upsertDocument',
        'BULK_UPSERT' => 'upsertDocuments',
    ];

    Console::info("Benchmarking {$operatorName} across all operation types...");

    foreach ($operationTypes as $opType => $method) {
        // Skip upsert operations if not supported
        if (str_contains($method, 'upsert') && !$database->getAdapter()->getSupportForUpserts()) {
            Console::warning("  Skipping {$opType} (not supported by adapter)");
            continue;
        }

        $isBulk = str_contains($method, 'Documents');
        $docCount = $isBulk ? 10 : 1;

        // Prepare test documents
        $baseData = array_merge([
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ],
        ], $initialData);

        // Create documents for with-operator test
        $docIdsWith = [];
        for ($i = 0; $i < $docCount; $i++) {
            $docId = 'bench_with_' . strtolower($operatorName) . '_' . strtolower($opType) . '_' . $i;
            $docIdsWith[] = $docId;
            $database->createDocument('operators_test', new Document(array_merge(['$id' => $docId], $baseData)));
        }

        // Create documents for without-operator test
        $docIdsWithout = [];
        for ($i = 0; $i < $docCount; $i++) {
            $docId = 'bench_without_' . strtolower($operatorName) . '_' . strtolower($opType) . '_' . $i;
            $docIdsWithout[] = $docId;
            $database->createDocument('operators_test', new Document(array_merge(['$id' => $docId], $baseData)));
        }

        // Benchmark WITH operator
        $memBefore = memory_get_usage(true);
        $start = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            if ($method === 'updateDocument') {
                $database->updateDocument('operators_test', $docIdsWith[0], new Document([
                    $attribute => $operator
                ]));
            } elseif ($method === 'updateDocuments') {
                $updates = new Document([$attribute => $operator]);
                // Pass all doc IDs in a single query
                $queries = [Query::equal('$id', $docIdsWith)];
                $database->updateDocuments('operators_test', $updates, $queries);
            } elseif ($method === 'upsertDocument') {
                $database->upsertDocument('operators_test', new Document(array_merge(
                    ['$id' => $docIdsWith[0]],
                    $baseData,
                    [$attribute => $operator]
                )));
            } elseif ($method === 'upsertDocuments') {
                $docs = [];
                foreach ($docIdsWith as $docId) {
                    $docs[] = new Document(array_merge(
                        ['$id' => $docId],
                        $baseData,
                        [$attribute => $operator]
                    ));
                }
                $database->upsertDocuments('operators_test', $docs);
            }
        }

        $timeWith = microtime(true) - $start;
        $memWith = memory_get_usage(true) - $memBefore;

        // Benchmark WITHOUT operator (traditional read-modify-write)
        $memBefore = memory_get_usage(true);
        $start = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            if ($method === 'updateDocument') {
                $doc = $database->getDocument('operators_test', $docIdsWithout[0]);
                $doc = $traditionalModifier($doc);
                $database->updateDocument('operators_test', $docIdsWithout[0], $doc);
            } elseif ($method === 'updateDocuments') {
                // Bulk read all documents at once
                $docs = $database->find('operators_test', [Query::equal('$id', $docIdsWithout)]);

                // Apply modifications
                $modifiedDocs = [];
                foreach ($docs as $doc) {
                    $modifiedDocs[] = $traditionalModifier($doc);
                }

                // Update each document individually (updateDocuments can't handle different values per doc)
                foreach ($modifiedDocs as $doc) {
                    $database->updateDocument('operators_test', $doc->getId(), $doc);
                }
            } elseif ($method === 'upsertDocument') {
                $doc = $database->getDocument('operators_test', $docIdsWithout[0]);
                $doc = $traditionalModifier($doc);
                $database->upsertDocument('operators_test', $doc);
            } elseif ($method === 'upsertDocuments') {
                // Bulk read all documents at once
                $docs = $database->find('operators_test', [Query::equal('$id', $docIdsWithout)]);

                // Apply modifications
                $modifiedDocs = [];
                foreach ($docs as $doc) {
                    $modifiedDocs[] = $traditionalModifier($doc);
                }

                // Bulk upsert
                $database->upsertDocuments('operators_test', $modifiedDocs);
            }
        }

        $timeWithout = microtime(true) - $start;
        $memWithout = memory_get_usage(true) - $memBefore;

        // Cleanup test documents
        foreach ($docIdsWith as $docId) {
            $database->deleteDocument('operators_test', $docId);
        }
        foreach ($docIdsWithout as $docId) {
            $database->deleteDocument('operators_test', $docId);
        }

        // Calculate metrics
        $speedup = $timeWithout > 0 ? $timeWithout / $timeWith : 0;
        $improvement = $timeWithout > 0 ? (($timeWithout - $timeWith) / $timeWithout) * 100 : 0;

        // Color code the speedup output
        $speedupStr = number_format($speedup, 2);
        if ($speedup > 1.0) {
            Console::success("  {$opType}: {$timeWith}s vs {$timeWithout}s | Speedup: {$speedupStr}x");
        } elseif ($speedup >= 0.85) {
            Console::warning("  {$opType}: {$timeWith}s vs {$timeWithout}s | Speedup: {$speedupStr}x");
        } else {
            Console::error("  {$opType}: {$timeWith}s vs {$timeWithout}s | Speedup: {$speedupStr}x");
        }

        $results[$opType] = [
            'operation_type' => $opType,
            'method' => $method,
            'time_with' => $timeWith,
            'time_without' => $timeWithout,
            'memory_with' => $memWith,
            'memory_without' => $memWithout,
            'speedup' => $speedup,
            'improvement_percent' => $improvement,
            'iterations' => $iterations,
            'document_count' => $docCount,
        ];
    }

    return [
        'operator' => $operatorName,
        'attribute' => $attribute,
        'operations' => $results,
    ];
}

/**
 * Display formatted results table
 */
function displayResults(array $results, string $adapter, int $iterations, int $seed): void
{
    Console::info("\n=============================================================");
    Console::info("  BENCHMARK RESULTS");
    Console::info("=============================================================");
    Console::info("Adapter: {$adapter}");
    Console::info("Iterations per test: {$iterations}");
    Console::info("Seeded documents: {$seed}");
    Console::info("=============================================================\n");

    // ==================================================================
    // OPERATION TYPE RESULTS
    // ==================================================================
    Console::info("=== OPERATION PERFORMANCE (Overhead Check) ===\n");
    Console::info("This section verifies NO OVERHEAD for non-operator operations:\n");

    $opTypes = ['UPDATE_SINGLE', 'UPDATE_BULK', 'UPSERT_SINGLE', 'UPSERT_BULK'];

    foreach ($opTypes as $opType) {
        $noOpsKey = $opType . '_NO_OPS';
        $withOpsKey = $opType . '_WITH_OPS';

        if (isset($results[$noOpsKey]) && isset($results[$withOpsKey])) {
            $noOps = $results[$noOpsKey];
            $withOps = $results[$withOpsKey];

            $timeNoOps = number_format($noOps['time'], 4);
            $timeWithOps = number_format($withOps['time'], 4);

            Console::info(str_pad($opType, 20) . ":");
            Console::info("  NO operators:   {$timeNoOps}s");
            Console::info("  WITH operators: {$timeWithOps}s");
            Console::info("");
        }
    }

    Console::info("=============================================================\n");

    // Calculate column widths
    $colWidths = [
        'operator' => 20,
        'with' => 12,
        'without' => 12,
        'speedup' => 10,
        'improvement' => 14,
        'mem_diff' => 12,
    ];

    // Header
    $header = sprintf(
        "%-{$colWidths['operator']}s %-{$colWidths['with']}s %-{$colWidths['without']}s %-{$colWidths['speedup']}s %-{$colWidths['improvement']}s %-{$colWidths['mem_diff']}s",
        'OPERATOR',
        'WITH (s)',
        'WITHOUT (s)',
        'SPEEDUP',
        'IMPROVEMENT',
        'MEM DIFF'
    );

    Console::info($header);
    Console::info(str_repeat('-', array_sum($colWidths) + 5));

    // Group results by category
    $categories = [
        'Numeric' => ['INCREMENT', 'DECREMENT', 'MULTIPLY', 'DIVIDE', 'MODULO', 'POWER'],
        'String' => ['CONCAT', 'REPLACE'],
        'Boolean' => ['TOGGLE'],
        'Array' => ['ARRAY_APPEND', 'ARRAY_PREPEND', 'ARRAY_INSERT', 'ARRAY_REMOVE', 'ARRAY_UNIQUE', 'ARRAY_INTERSECT', 'ARRAY_DIFF', 'ARRAY_FILTER'],
        'Date' => ['DATE_ADD_DAYS', 'DATE_SUB_DAYS', 'DATE_SET_NOW'],
    ];

    $totalSpeedup = 0;
    $totalCount = 0;

    foreach ($categories as $categoryName => $operators) {
        Console::info("\n{$categoryName} Operators:");

        foreach ($operators as $operatorName) {
            if (!isset($results[$operatorName])) {
                continue;
            }

            $result = $results[$operatorName];

            Console::info("\n  {$operatorName}:");

            if (!isset($result['operations'])) {
                Console::warning("    No results (benchmark failed)");
                continue;
            }

            foreach ($result['operations'] as $opType => $opResult) {
                $timeWith = number_format($opResult['time_with'], 4);
                $timeWithout = number_format($opResult['time_without'], 4);
                $speedup = number_format($opResult['speedup'], 2);
                $improvement = number_format($opResult['improvement_percent'], 1);
                $memDiff = formatBytes($opResult['memory_without'] - $opResult['memory_with']);

                // Color code based on performance
                $speedupDisplay = $opResult['speedup'] > 1.0 ? "\033[32m{$speedup}x\033[0m" :
                                 ($opResult['speedup'] >= 0.85 ? "\033[33m{$speedup}x\033[0m" :
                                 "\033[31m{$speedup}x\033[0m");

                $improvementDisplay = $opResult['improvement_percent'] > 0 ? "\033[32m+{$improvement}%\033[0m" :
                                     ($opResult['improvement_percent'] >= -15 ? "\033[33m{$improvement}%\033[0m" :
                                     "\033[31m{$improvement}%\033[0m");

                $row = sprintf(
                    "    %-16s %-{$colWidths['with']}s %-{$colWidths['without']}s %-{$colWidths['speedup']}s %-{$colWidths['improvement']}s %-{$colWidths['mem_diff']}s",
                    $opType,
                    $timeWith,
                    $timeWithout,
                    $speedupDisplay,
                    $improvementDisplay,
                    $memDiff
                );

                Console::log($row);

                $totalSpeedup += $opResult['speedup'];
                $totalCount++;
            }
        }
    }

    // Summary statistics
    $avgSpeedup = $totalCount > 0 ? $totalSpeedup / $totalCount : 0;

    Console::info("\n" . str_repeat('=', array_sum($colWidths) + 5));
    Console::info("SUMMARY:");
    Console::info("  Total operators tested: {$totalCount}");
    Console::info("  Average speedup: " . number_format($avgSpeedup, 2) . "x");

    // Performance insights
    Console::info("\n" . str_repeat('=', array_sum($colWidths) + 5));
    Console::info("PERFORMANCE INSIGHTS:");

    // Flatten results for fastest/slowest calculation
    $flattenedResults = [];
    foreach ($results as $operatorName => $result) {
        if (isset($result['operations'])) {
            foreach ($result['operations'] as $opType => $opResult) {
                $flattenedResults[] = [
                    'operator' => $operatorName,
                    'operation' => $opType,
                    'speedup' => $opResult['speedup'],
                ];
            }
        }
    }

    if (!empty($flattenedResults)) {
        $fastest = array_reduce(
            $flattenedResults,
            fn ($carry, $item) =>
            $carry === null || $item['speedup'] > $carry['speedup'] ? $item : $carry
        );

        $slowest = array_reduce(
            $flattenedResults,
            fn ($carry, $item) =>
            $carry === null || $item['speedup'] < $carry['speedup'] ? $item : $carry
        );

        if ($fastest) {
            Console::success("  Fastest: {$fastest['operator']} ({$fastest['operation']}) - " . number_format($fastest['speedup'], 2) . "x speedup");
        }

        if ($slowest) {
            Console::warning("  Slowest: {$slowest['operator']} ({$slowest['operation']}) - " . number_format($slowest['speedup'], 2) . "x speedup");
        }
    }

    Console::info("\n=============================================================\n");
}

/**
 * Format bytes to human-readable format
 */
function formatBytes(int $bytes): string
{
    if ($bytes === 0) {
        return '0 B';
    }

    $sign = $bytes < 0 ? '-' : '+';
    $bytes = abs($bytes);

    $units = ['B', 'KB', 'MB', 'GB'];
    $power = floor(log($bytes, 1024));
    $power = min($power, count($units) - 1);

    return $sign . round($bytes / pow(1024, $power), 2) . ' ' . $units[$power];
}

/**
 * Cleanup test environment
 */
function cleanup(Database $database, string $name): void
{
    Console::info("Cleaning up test environment...");

    try {
        if ($database->exists($name)) {
            $database->delete($name);
        }
        Console::success("Cleanup complete.");
    } catch (\Throwable $e) {
        Console::warning("Cleanup failed: " . $e->getMessage());
    }
}
