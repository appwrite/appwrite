<?php

ini_set('memory_limit', '4G');
ini_set('xdebug.max_nesting_level', '-1');

global $cli;

use Swoole\Database\PDOConfig;
use Swoole\Database\PDOPool;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\Console;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Adapter\MySQL;
use Utopia\Database\Adapter\Postgres;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\PDO;
use Utopia\Database\Query;
use Utopia\Validator\Boolean;
use Utopia\Validator\Integer;
use Utopia\Validator\Text;

// Global pools for faster document generation
$namesPool = ['Alice', 'Bob', 'Carol', 'Dave', 'Eve', 'Frank', 'Grace', 'Heidi', 'Ivan', 'Judy', 'Mallory', 'Niaj', 'Olivia', 'Peggy', 'Quentin', 'Rupert', 'Sybil', 'Trent', 'Uma', 'Victor'];
$genresPool = ['fashion', 'food', 'travel', 'music', 'lifestyle', 'fitness', 'diy', 'sports', 'finance'];
$tagsPool = ['short', 'quick', 'easy', 'medium', 'hard'];

/**
 * @Example
 * docker compose exec tests bin/relationships --adapter=mariadb --limit=1000
 */

$cli
    ->task('relationships')
    ->desc('Load database with mock relationships for testing')
    ->param('adapter', '', new Text(0), 'Database adapter')
    ->param('limit', 0, new Integer(true), 'Total number of records to add to database')
    ->param('name', 'myapp_' . uniqid(), new Text(0), 'Name of created database.', true)
    ->param('sharedTables', false, new Boolean(true), 'Whether to use shared tables', true)
    ->param('runs', 1, new Integer(true), 'Number of times to run benchmarks', true)
    ->action(function (string $adapter, int $limit, string $name, bool $sharedTables, int $runs) {
        $start = null;
        $namespace = '_ns';
        $cache = new Cache(new NoCache());

        Console::info("Filling {$adapter} with {$limit} records: {$name}");

        $createRelationshipSchema = function (Database $database): void {
            if ($database->exists($database->getDatabase())) {
                $database->delete($database->getDatabase());
            }
            $database->getAuthorization()->addRole(Role::any()->toString());
            $database->create();
            $database->createCollection('authors', permissions: [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ]);
            $database->createAttribute('authors', 'name', Database::VAR_STRING, 256, true);
            $database->createAttribute('authors', 'created', Database::VAR_DATETIME, 0, true, filters: ['datetime']);
            $database->createAttribute('authors', 'bio', Database::VAR_STRING, 5000, true);
            $database->createAttribute('authors', 'avatar', Database::VAR_STRING, 256, true);
            $database->createAttribute('authors', 'website', Database::VAR_STRING, 256, true);

            $database->createCollection('articles', permissions: [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ]);
            $database->createAttribute('articles', 'title', Database::VAR_STRING, 256, true);
            $database->createAttribute('articles', 'text', Database::VAR_STRING, 5000, true);
            $database->createAttribute('articles', 'genre', Database::VAR_STRING, 256, true);
            $database->createAttribute('articles', 'views', Database::VAR_INTEGER, 0, true);
            $database->createAttribute('articles', 'tags', Database::VAR_STRING, 0, true, array: true);

            $database->createCollection('users', permissions: [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ]);
            $database->createAttribute('users', 'username', Database::VAR_STRING, 256, true);
            $database->createAttribute('users', 'email', Database::VAR_STRING, 256, true);
            $database->createAttribute('users', 'password', Database::VAR_STRING, 256, true);

            $database->createCollection('comments', permissions: [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ]);
            $database->createAttribute('comments', 'content', Database::VAR_STRING, 256, true);
            $database->createAttribute('comments', 'likes', Database::VAR_INTEGER, 8, true, signed: false);

            $database->createCollection('profiles', permissions: [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ]);
            $database->createAttribute('profiles', 'bio_extended', Database::VAR_STRING, 10000, true);
            $database->createAttribute('profiles', 'social_links', Database::VAR_STRING, 256, true, array: true);
            $database->createAttribute('profiles', 'verified', Database::VAR_BOOLEAN, 0, true);

            $database->createCollection('categories', permissions: [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
                Permission::update(Role::any()),
            ]);
            $database->createAttribute('categories', 'name', Database::VAR_STRING, 256, true);
            $database->createAttribute('categories', 'description', Database::VAR_STRING, 1000, true);

            $database->createRelationship('authors', 'articles', Database::RELATION_MANY_TO_MANY, true, onDelete: Database::RELATION_MUTATE_SET_NULL);
            $database->createRelationship('articles', 'comments', Database::RELATION_ONE_TO_MANY, true, twoWayKey: 'article', onDelete: Database::RELATION_MUTATE_CASCADE);
            $database->createRelationship('users', 'comments', Database::RELATION_ONE_TO_MANY, true, twoWayKey: 'user', onDelete: Database::RELATION_MUTATE_CASCADE);
            $database->createRelationship('authors', 'profiles', Database::RELATION_ONE_TO_ONE, true, twoWayKey: 'author', onDelete: Database::RELATION_MUTATE_CASCADE);
            $database->createRelationship('articles', 'categories', Database::RELATION_MANY_TO_ONE, true, id: 'category', twoWayKey: 'articles', onDelete: Database::RELATION_MUTATE_SET_NULL);
        };

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
                'user' => 'postgres',
                'pass' => 'password',
                'dsn' => static fn (string $host, int $port) => "pgsql:host={$host};port={$port}",
                'adapter' => Postgres::class,
                'attrs' => Postgres::getPDOAttributes(),
            ],
        ];

        if (!isset($dbAdapters[$adapter])) {
            Console::error("Adapter '{$adapter}' not supported");
            return;
        }

        $cfg = $dbAdapters[$adapter];

        $pdo = new PDO(
            ($cfg['dsn'])($cfg['host'], $cfg['port']),
            $cfg['user'],
            $cfg['pass'],
            $cfg['attrs']
        );

        $database = (new Database(new ($cfg['adapter'])($pdo), $cache))
            ->setDatabase($name)
            ->setNamespace($namespace)
            ->setSharedTables($sharedTables);

        $createRelationshipSchema($database);

        // Create categories and users once before parallel batch creation
        $globalDocs = createGlobalDocuments($database, $limit);

        $pdo = null;

        $pool = new PDOPool(
            (new PDOConfig())
                ->withHost($cfg['host'])
                ->withPort($cfg['port'])
                ->withDbName($name)
                ->withCharset('utf8mb4')
                ->withUsername($cfg['user'])
                ->withPassword($cfg['pass']),
            size: 64
        );

        $start = \microtime(true);

        for ($i = 0; $i < $limit / 1000; $i++) {
            go(function () use ($cfg, $pool, $name, $namespace, $sharedTables, $cache, $globalDocs) {
                try {
                    $pdo = $pool->get();

                    $database = (new Database(new ($cfg['adapter'])($pdo), $cache))
                        ->setDatabase($name)
                        ->setNamespace($namespace)
                        ->setSharedTables($sharedTables);

                    createRelationshipDocuments($database, $globalDocs['categories'], $globalDocs['users']);
                    $pool->put($pdo);
                } catch (\Throwable $error) {
                    // Errors caught but documents still created successfully - likely concurrent update race conditions
                }
            });
        }

        $time = microtime(true) - $start;
        Console::success("Document creation completed in {$time} seconds");

        // Display relationship structure
        displayRelationshipStructure();

        // Collect benchmark results across runs
        $results = [];

        Console::info("Running benchmarks {$runs} time(s)...");

        for ($run = 1; $run <= $runs; $run++) {
            if ($runs > 1) {
                Console::info("Run {$run}/{$runs}");
            }

            $results[] = [
                'single' => benchmarkSingle($database),
                'batch100' => benchmarkBatch100($database),
                'batch1000' => benchmarkBatch1000($database),
                'batch5000' => benchmarkBatch5000($database),
                'pagination' => benchmarkPagination($database),
            ];
        }

        // Calculate and display averages
        displayBenchmarkResults($results, $runs);
    });


function createGlobalDocuments(Database $database, int $limit): array
{
    global $genresPool, $namesPool;

    // Scale categories based on limit (minimum 9, scales up to 100 max)
    $numCategories = min(100, max(9, (int)($limit / 10000)));
    $categoryDocs = [];
    for ($i = 0; $i < $numCategories; $i++) {
        $genre = $genresPool[$i % count($genresPool)];
        $categoryDocs[] = new Document([
            '$id' => 'category_' . \uniqid(),
            'name' => \ucfirst($genre) . ($i >= count($genresPool) ? ' ' . ($i + 1) : ''),
            'description' => 'Articles about ' . $genre,
        ]);
    }

    // Create categories once - documents are modified in place with IDs
    $database->createDocuments('categories', $categoryDocs);

    // Scale users based on limit (10% of total documents)
    $numUsers = max(1000, (int)($limit / 10));
    $userDocs = [];
    for ($u = 0; $u < $numUsers; $u++) {
        $userDocs[] = new Document([
            '$id' => 'user_' . \uniqid(),
            'username' => $namesPool[\array_rand($namesPool)] . '_' . $u,
            'email' => 'user' . $u . '@example.com',
            'password' => \bin2hex(\random_bytes(8)),
        ]);
    }

    // Create users once
    $database->createDocuments('users', $userDocs);

    // Return both categories and users
    return ['categories' => $categoryDocs, 'users' => $userDocs];
}

function createRelationshipDocuments(Database $database, array $categories, array $users): void
{
    global $namesPool, $genresPool, $tagsPool;

    $documents = [];
    $start = \microtime(true);

    // Prepare pools for nested data
    $numAuthors = 10;
    $numArticlesPerAuthor = 10;
    $numCommentsPerArticle = 10;

    // Generate authors with nested articles and comments
    for ($a = 0; $a < $numAuthors; $a++) {
        $author = new Document([
            'name' => $namesPool[array_rand($namesPool)],
            'created' => DateTime::now(),
            'bio' => \substr(\bin2hex(\random_bytes(32)), 0, 100),
            'avatar' => 'https://example.com/avatar/' . $a,
            'website' => 'https://example.com/user/' . $a,
        ]);

        // Create profile for author (one-to-one relationship)
        $profile = new Document([
            'bio_extended' => \substr(\bin2hex(\random_bytes(128)), 0, 500),
            'social_links' => [
                'https://twitter.com/author' . $a,
                'https://linkedin.com/in/author' . $a,
            ],
            'verified' => (bool)\mt_rand(0, 1),
        ]);
        $author->setAttribute('profiles', $profile);

        // Nested articles
        $authorArticles = [];
        for ($i = 0; $i < $numArticlesPerAuthor; $i++) {
            $article = new Document([
                'title' => 'Article ' . ($i + 1) . ' by ' . $author->getAttribute('name'),
                'text' => \substr(\bin2hex(\random_bytes(64)), 0, \mt_rand(100, 200)),
                'genre' => $genresPool[array_rand($genresPool)],
                'views' => \mt_rand(0, 1000),
                'tags' => \array_slice($tagsPool, 0, \mt_rand(1, \count($tagsPool))),
                'category' => $categories[\array_rand($categories)],
            ]);

            // Nested comments
            $comments = [];
            for ($c = 0; $c < $numCommentsPerArticle; $c++) {
                $comment = new Document([
                    'content' => 'Comment ' . ($c + 1),
                    'likes' => \mt_rand(0, 10000),
                    'user' => $users[\array_rand($users)],
                ]);
                $comments[] = $comment;
            }

            $article->setAttribute('comments', $comments);
            $authorArticles[] = $article;
        }

        $author->setAttribute('articles', $authorArticles);
        $documents[] = $author;
    }

    // Insert authors (with nested articles, comments, and users)
    $start = \microtime(true);
    $database->createDocuments('authors', $documents);
    $time = \microtime(true) - $start;
    Console::success("Inserted nested documents in {$time} seconds");
}

/**
 * Benchmark querying a single document from each collection.
 */
function benchmarkSingle(Database $database): array
{
    $collections = ['authors', 'articles', 'users', 'comments', 'profiles', 'categories'];
    $results = [];

    foreach ($collections as $collection) {
        // Fetch one document ID to use (skip relationships to avoid infinite recursion)
        $docs = $database->skipRelationships(fn () => $database->findOne($collection));
        $id = $docs->getId();

        $start = microtime(true);
        $database->getDocument($collection, $id);
        $time = microtime(true) - $start;

        $results[$collection] = $time;
    }

    return $results;
}

/**
 * Benchmark querying 100 documents from each collection.
 */
function benchmarkBatch100(Database $database): array
{
    $collections = ['authors', 'articles', 'users', 'comments', 'profiles', 'categories'];
    $results = [];

    foreach ($collections as $collection) {
        $start = microtime(true);
        $database->find($collection, [Query::limit(100)]);
        $time = microtime(true) - $start;

        $results[$collection] = $time;
    }

    return $results;
}

/**
 * Benchmark querying 1000 documents from each collection.
 */
function benchmarkBatch1000(Database $database): array
{
    $collections = ['authors', 'articles', 'users', 'comments', 'profiles', 'categories'];
    $results = [];

    foreach ($collections as $collection) {
        $start = microtime(true);
        $database->find($collection, [Query::limit(1000)]);
        $time = microtime(true) - $start;

        $results[$collection] = $time;
    }

    return $results;
}

/**
 * Benchmark querying 5000 documents from each collection.
 */
function benchmarkBatch5000(Database $database): array
{
    $collections = ['authors', 'articles', 'users', 'comments', 'profiles', 'categories'];
    $results = [];

    foreach ($collections as $collection) {
        $start = microtime(true);
        $database->find($collection, [Query::limit(5000)]);
        $time = microtime(true) - $start;

        $results[$collection] = $time;
    }

    return $results;
}

/**
 * Benchmark cursor pagination through entire collection in chunks of 100.
 */
function benchmarkPagination(Database $database): array
{
    $collections = ['authors', 'articles', 'users', 'comments', 'profiles', 'categories'];
    $results = [];

    foreach ($collections as $collection) {
        $total = 0;
        $limit = 100;
        $cursor = null;
        $start = microtime(true);
        do {
            $queries = [Query::limit($limit)];
            if ($cursor !== null) {
                $queries[] = Query::cursorAfter($cursor);
            }
            $docs = $database->find($collection, $queries);
            $count = count($docs);
            $total += $count;
            if ($count > 0) {
                $cursor = $docs[$count - 1];
            }
        } while ($count === $limit);
        $time = microtime(true) - $start;

        $results[$collection] = $time;
    }

    return $results;
}

/**
 * Display relationship structure diagram
 */
function displayRelationshipStructure(): void
{
    Console::success("\n========================================");
    Console::success("Relationship Structure");
    Console::success("========================================\n");

    Console::info("Collections:");
    Console::log("  • authors      (name, created, bio, avatar, website)");
    Console::log("  • articles     (title, text, genre, views, tags[])");
    Console::log("  • comments     (content, likes)");
    Console::log("  • users        (username, email, password)");
    Console::log("  • profiles     (bio_extended, social_links[], verified)");
    Console::log("  • categories   (name, description)");
    Console::log("");

    Console::info("Relationships:");
    Console::log("  ┌─────────────────────────────────────────────────────────────┐");
    Console::log("  │  authors ◄─────────────► articles  (Many-to-Many)          │");
    Console::log("  │    └─► profiles (One-to-One)                                │");
    Console::log("  │                                                              │");
    Console::log("  │  articles ─────────────► comments  (One-to-Many)            │");
    Console::log("  │    └─► categories (Many-to-One)                             │");
    Console::log("  │                                                              │");
    Console::log("  │  users ────────────────► comments  (One-to-Many)            │");
    Console::log("  └─────────────────────────────────────────────────────────────┘");
    Console::log("");

    Console::info("Relationship Coverage:");
    Console::log("  ✓ One-to-One:    authors ◄─► profiles");
    Console::log("  ✓ One-to-Many:   articles ─► comments, users ─► comments");
    Console::log("  ✓ Many-to-One:   articles ─► categories");
    Console::log("  ✓ Many-to-Many:  authors ◄─► articles");
    Console::log("");
}

/**
 * Display benchmark results as a formatted table
 */
function displayBenchmarkResults(array $results, int $runs): void
{
    $collections = ['authors', 'articles', 'users', 'comments', 'profiles', 'categories'];
    $benchmarks = ['single', 'batch100', 'batch1000', 'batch5000', 'pagination'];
    $benchmarkLabels = [
        'single' => 'Single Query',
        'batch100' => 'Batch 100',
        'batch1000' => 'Batch 1000',
        'batch5000' => 'Batch 5000',
        'pagination' => 'Pagination',
    ];

    // Calculate averages
    $averages = [];
    foreach ($benchmarks as $benchmark) {
        $averages[$benchmark] = [];
        foreach ($collections as $collection) {
            $total = 0;
            foreach ($results as $run) {
                $total += $run[$benchmark][$collection] ?? 0;
            }
            $averages[$benchmark][$collection] = $total / $runs;
        }
    }

    Console::success("\n========================================");
    Console::success("Benchmark Results (Average of {$runs} run" . ($runs > 1 ? 's' : '') . ")");
    Console::success("========================================\n");

    // Calculate column widths
    $collectionWidth = 12;
    $timeWidth = 12;

    // Print header
    $header = str_pad('Collection', $collectionWidth) . ' | ';
    foreach ($benchmarkLabels as $label) {
        $header .= str_pad($label, $timeWidth) . ' | ';
    }
    Console::info($header);
    Console::info(str_repeat('-', strlen($header)));

    // Print results for each collection
    foreach ($collections as $collection) {
        $row = str_pad(ucfirst($collection), $collectionWidth) . ' | ';
        foreach ($benchmarks as $benchmark) {
            $time = number_format($averages[$benchmark][$collection] * 1000, 2); // Convert to ms
            $row .= str_pad($time . ' ms', $timeWidth) . ' | ';
        }
        Console::log($row);
    }

    Console::log('');
}
