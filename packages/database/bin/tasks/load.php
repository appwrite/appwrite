<?php

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
use Utopia\Validator\Boolean;
use Utopia\Validator\Integer;
use Utopia\Validator\Text;

// Global pools for faster document generation
$namesPool = ['Alice', 'Bob', 'Carol', 'Dave', 'Eve', 'Frank', 'Grace', 'Heidi', 'Ivan', 'Judy', 'Mallory', 'Niaj', 'Olivia', 'Peggy', 'Quentin', 'Rupert', 'Sybil', 'Trent', 'Uma', 'Victor'];
$genresPool = ['fashion', 'food', 'travel', 'music', 'lifestyle', 'fitness', 'diy', 'sports', 'finance'];
$tagsPool = ['short', 'quick', 'easy', 'medium', 'hard'];


/**
 * @Example
 * docker compose exec tests bin/load --adapter=mariadb --limit=1000
 */
$cli
    ->task('load')
    ->desc('Load database with mock data for testing')
    ->param('adapter', '', new Text(0), 'Database adapter')
    ->param('limit', 0, new Integer(true), 'Total number of records to add to database')
    ->param('name', 'myapp_' . uniqid(), new Text(0), 'Name of created database.', true)
    ->param('sharedTables', false, new Boolean(true), 'Whether to use shared tables', true)
    ->action(function (string $adapter, int $limit, string $name, bool $sharedTables) {


        $createSchema = function (Database $database): void {
            if ($database->exists($database->getDatabase())) {
                $database->delete($database->getDatabase());
            }
            $database->getAuthorization()->addRole(Role::any()->toString());
            $database->create();

            $database->createCollection('articles', permissions: [
                Permission::create(Role::any()),
                Permission::read(Role::any()),
            ]);

            $database->createAttribute('articles', 'author', Database::VAR_STRING, 256, true);
            $database->createAttribute('articles', 'created', Database::VAR_DATETIME, 0, true, filters: ['datetime']);
            $database->createAttribute('articles', 'text', Database::VAR_STRING, 5000, true);
            $database->createAttribute('articles', 'genre', Database::VAR_STRING, 256, true);
            $database->createAttribute('articles', 'views', Database::VAR_INTEGER, 0, true);
            $database->createAttribute('articles', 'tags', Database::VAR_STRING, 0, true, array: true);
            $database->createIndex('articles', 'text', Database::INDEX_FULLTEXT, ['text']);
        };


        $start = null;
        $namespace = '_ns';
        $cache = new Cache(new NoCache());

        Console::info("Filling {$adapter} with {$limit} records: {$name}");

        //Runtime::enableCoroutine();

        $dbAdapters = [
            'mariadb' => [
                'host' => 'mariadb',
                'port' => 3306,
                'user' => 'root',
                'pass' => 'password',
                'dsn' => static fn (string $host, int $port) => "mysql:host={$host};port={$port};charset=utf8mb4",
                'driver' => 'mysql',
                'adapter' => MariaDB::class,
                'attrs' => MariaDB::getPDOAttributes(),
            ],
            'mysql' => [
                'host' => 'mysql',
                'port' => 3307,
                'user' => 'root',
                'pass' => 'password',
                'dsn' => static fn (string $host, int $port) => "mysql:host={$host};port={$port};charset=utf8mb4",
                'driver' => 'mysql',
                'adapter' => MySQL::class,
                'attrs' => MySQL::getPDOAttributes(),
            ],
            'postgres' => [
                'host' => 'postgres',
                'port' => 5432,
                'user' => 'postgres',
                'pass' => 'password',
                'dsn' => static fn (string $host, int $port) => "pgsql:host={$host};port={$port}",
                'driver' => 'pgsql',
                'adapter' => Postgres::class,
                'attrs' => Postgres::getPDOAttributes(),
            ],
        ];

        if (!isset($dbAdapters[$adapter])) {
            Console::error("Adapter '{$adapter}' not supported");
            return;
        }

        $cfg = $dbAdapters[$adapter];
        $dsn = ($cfg['dsn'])($cfg['host'], $cfg['port']);

        //Co\run(function () use (&$start, $limit, $name, $sharedTables, $namespace, $cache, $cfg) {
        $pdo = new PDO(
            $dsn,
            $cfg['user'],
            $cfg['pass'],
            $cfg['attrs']
        );

        $createSchema(
            (new Database(new ($cfg['adapter'])($pdo), $cache))
                ->setDatabase($name)
                ->setNamespace($namespace)
                ->setSharedTables($sharedTables)
        );

        $pool = new PDOPool(
            (new PDOConfig())
                ->withDriver($cfg['driver'])
                ->withHost($cfg['host'])
                ->withPort($cfg['port'])
                ->withDbName($name)
                //->withCharset('utf8mb4')
                ->withUsername($cfg['user'])
                ->withPassword($cfg['pass']),
            128
        );

        $start = \microtime(true);

        for ($i = 0; $i < $limit / 1000; $i++) {
            //\go(function () use ($cfg, $pool, $name, $namespace, $sharedTables, $cache) {
            try {
                //$pdo = $pool->get();

                $database = (new Database(new ($cfg['adapter'])($pdo), $cache))
                    ->setDatabase($name)
                    ->setNamespace($namespace)
                    ->setSharedTables($sharedTables);

                createDocuments($database);
                //$pool->put($pdo);
            } catch (\Throwable $error) {
                Console::error('Coroutine error: ' . $error->getMessage());
            }
            //});
        }

        $time = microtime(true) - $start;
        Console::success("Completed in {$time} seconds");
    });



function createDocuments(Database $database): void
{
    global $namesPool, $genresPool, $tagsPool;

    $documents = [];

    $start = \microtime(true);
    for ($i = 0; $i < 1000; $i++) {
        $length = \mt_rand(1000, 4000);
        $bytes = \random_bytes(intdiv($length + 1, 2));
        $text = \substr(\bin2hex($bytes), 0, $length);
        $tagCount = \mt_rand(1, count($tagsPool));
        $tagKeys = (array)\array_rand($tagsPool, $tagCount);
        $tags = \array_map(fn ($k) => $tagsPool[$k], $tagKeys);

        $documents[] = new Document([
            '$permissions' => [
                Permission::read(Role::any()),
                ...array_map(fn () => Permission::read(Role::user(mt_rand(0, 999999999))), range(1, 4)),
                ...array_map(fn () => Permission::create(Role::user(mt_rand(0, 999999999))), range(1, 3)),
                ...array_map(fn () => Permission::update(Role::user(mt_rand(0, 999999999))), range(1, 3)),
                ...array_map(fn () => Permission::delete(Role::user(mt_rand(0, 999999999))), range(1, 3)),
            ],
            'author' => $namesPool[\array_rand($namesPool)],
            'created' => DateTime::now(),
            'text' => $text,
            'genre' => $genresPool[\array_rand($genresPool)],
            'views' => \mt_rand(0, 999999),
            'tags' => $tags,
        ]);
    }
    $time = \microtime(true) - $start;
    Console::info("Prepared 1000 documents in {$time} seconds");
    $start = \microtime(true);
    $database->createDocuments('articles', $documents, 1000);
    $time = \microtime(true) - $start;
    Console::success("Inserted 1000 documents in {$time} seconds");
}
