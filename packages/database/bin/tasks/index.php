<?php

/**
 * @var CLI $cli
 */
global $cli;

use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\CLI\CLI;
use Utopia\Console;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Adapter\MySQL;
use Utopia\Database\Adapter\Postgres;
use Utopia\Database\Database;
use Utopia\Database\PDO;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;

/**
 * @Example
 * docker compose exec tests bin/index --adapter=mysql --name=testing
 */
$cli
    ->task('index')
    ->desc('Index mock data for testing queries')
    ->param('adapter', '', new Text(0), 'Database adapter')
    ->param('name', '', new Text(0), 'Name of created database.')
    ->param('sharedTables', false, new Boolean(true), 'Whether to use shared tables', true)
    ->action(function (string $adapter, string $name, bool $sharedTables) {
        $namespace = '_ns';
        $cache = new Cache(new NoCache());

        $dbAdapters = [
            'mariadb' => [
                'host' => 'mariadb',
                'port' => 3306,
                'user' => 'root',
                'pass' => 'password',
                'dsn' => static fn (string $host, int $port) => "mysql:host={$host};port={$port};charset=utf8mb4",
                'adapter' => MariaDB::class,
                'pdoAttr' => MariaDB::getPDOAttributes(),
            ],
            'mysql' => [
                'host' => 'mysql',
                'port' => 3307,
                'user' => 'root',
                'pass' => 'password',
                'dsn' => static fn (string $host, int $port) => "mysql:host={$host};port={$port};charset=utf8mb4",
                'adapter' => MySQL::class,
                'pdoAttr' => MySQL::getPDOAttributes(),
            ],
            'postgres' => [
                'host' => 'postgres',
                'port' => 5432,
                'user' => 'postgres',
                'pass' => 'password',
                'dsn' => static fn (string $host, int $port) => "pgsql:host={$host};port={$port}",
                'adapter' => Postgres::class,
                'pdoAttr' => Postgres::getPDOAttributes(),
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
            $cfg['pdoAttr']
        );

        $database = (new Database(new ($cfg['adapter'])($pdo), $cache))
            ->setDatabase($name)
            ->setNamespace($namespace)
            ->setSharedTables($sharedTables);

        Console::info("Creating key index 'createdGenre' on 'articles' for created > '2010-01-01 05:00:00' and genre = 'travel'");
        $start = microtime(true);
        $database->createIndex('articles', 'createdGenre', Database::INDEX_KEY, ['created', 'genre'], [], [Database::ORDER_DESC, Database::ORDER_DESC]);
        $time = microtime(true) - $start;
        Console::success("Index 'createdGenre' created in {$time} seconds");

        Console::info("Creating key index 'genre' on 'articles' for genres: fashion, finance, sports");
        $start = microtime(true);
        $database->createIndex('articles', 'genre', Database::INDEX_KEY, ['genre'], [], [Database::ORDER_ASC]);
        $time = microtime(true) - $start;
        Console::success("Index 'genre' created in {$time} seconds");

        Console::info("Creating key index 'views' on 'articles' for views > 100000");
        $start = microtime(true);
        $database->createIndex('articles', 'views', Database::INDEX_KEY, ['views'], [], [Database::ORDER_DESC]);
        $time = microtime(true) - $start;
        Console::success("Index 'views' created in {$time} seconds");

        Console::info("Creating fulltext index 'fulltextsearch' on 'articles' for search term 'Alice'");
        $start = microtime(true);
        $database->createIndex('articles', 'fulltextsearch', Database::INDEX_FULLTEXT, ['text']);
        $time = microtime(true) - $start;
        Console::success("Index 'fulltextsearch' created in {$time} seconds");

        Console::info("Creating key index 'tags' on 'articles' for tags containing 'tag1'");
        $start = microtime(true);
        $database->createIndex('articles', 'tags', Database::INDEX_KEY, ['tags']);
        $time = microtime(true) - $start;
        Console::success("Index 'tags' created in {$time} seconds");
    });
