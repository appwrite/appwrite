<?php

/**
 * @var CLI $cli
 */
global $cli;

use Faker\Factory;
use Utopia\Cache\Adapter\None as NoCache;
use Utopia\Cache\Cache;
use Utopia\CLI\CLI;
use Utopia\Console;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Adapter\MySQL;
use Utopia\Database\Adapter\Postgres;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Validator\Boolean;
use Utopia\Validator\Integer;
use Utopia\Validator\Text;

/**
 * @Example
 * docker compose exec tests bin/query --adapter=mariadb --limit=1000 --name=testing
 */

$cli
    ->task('query')
    ->desc('Query mock data')
    ->param('adapter', '', new Text(0), 'Database adapter')
    ->param('name', '', new Text(0), 'Name of created database.')
    ->param('limit', 25, new Integer(true), 'Limit on queried documents', true)
    ->param('sharedTables', false, new Boolean(true), 'Whether to use shared tables', true)
    ->action(function (string $adapter, string $name, int $limit, bool $sharedTables) {

        $setRoles = function (Authorization $authorization, $faker, $count): int {
            for ($i = 0; $i < $count; $i++) {
                $authorization->addRole($faker->numerify('user####'));
            }
            return \count($authorization->getRoles());
        };

        $namespace = '_ns';
        $cache = new Cache(new NoCache());

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

        $faker = Factory::create();

        $report = [];

        $count = $setRoles($database->getAuthorization(), $faker, 1);
        Console::info("\nRunning queries with {$count} authorization roles:");
        $report[] = [
            'roles' => $count,
            'results' => runQueries($database, $limit)
        ];

        $count = $setRoles($database->getAuthorization(), $faker, 100);
        Console::info("\nRunning queries with {$count} authorization roles:");
        $report[] = [
            'roles' => $count,
            'results' => runQueries($database, $limit)
        ];

        $count = $setRoles($database->getAuthorization(), $faker, 400);
        Console::info("\nRunning queries with {$count} authorization roles:");
        $report[] = [
            'roles' => $count,
            'results' => runQueries($database, $limit)
        ];

        $count = $setRoles($database->getAuthorization(), $faker, 500);
        Console::info("\nRunning queries with {$count} authorization roles:");
        $report[] = [
            'roles' => $count,
            'results' => runQueries($database, $limit)
        ];

        $count = $setRoles($database->getAuthorization(), $faker, 1000);
        Console::info("\nRunning queries with {$count} authorization roles:");
        $report[] = [
            'roles' => $count,
            'results' => runQueries($database, $limit)
        ];

        if (!file_exists('bin/view/results')) {
            \mkdir('bin/view/results', 0777, true);
        }

        $time = \time();
        $results = \fopen("bin/view/results/{$adapter}_{$name}_{$limit}_{$time}.json", 'w');
        \fwrite($results, \json_encode($report));
        \fclose($results);
    });


function runQueries(Database $database, int $limit): array
{
    $results = [];

    // Recent travel blogs
    $results["Querying greater than, equal[1] and limit"] = runQuery([
        Query::greaterThan('created', '2010-01-01 05:00:00'),
        Query::equal('genre', ['travel']),
        Query::limit($limit)
    ], $database);

    // Favorite genres
    $results["Querying equal[3] and limit"] = runQuery([
        Query::equal('genre', ['fashion', 'finance', 'sports']),
        Query::limit($limit)
    ], $database);

    // Popular posts
    $results["Querying greaterThan, limit({$limit})"] = runQuery([
        Query::greaterThan('views', 100000),
        Query::limit($limit)
    ], $database);

    // Fulltext search
    $results["Query search, limit({$limit})"] = runQuery([
        Query::search('text', 'Alice'),
        Query::limit($limit)
    ], $database);

    // Tags contain query
    $results["Querying contains[1], limit({$limit})"] = runQuery([
        Query::contains('tags', ['tag1']),
        Query::limit($limit)
    ], $database);

    return $results;
}

function runQuery(array $query, Database $database)
{
    $info = array_map(function (Query $q) {
        return $q->getAttribute() . ': ' . $q->getMethod() . ' = ' . implode(',', $q->getValues());
    }, $query);

    Console::info("Running query: [" . implode(', ', $info) . "]");
    $start = microtime(true);
    $database->find('articles', $query);
    $time = microtime(true) - $start;
    Console::success("Query executed in {$time} seconds");
    return $time;
}
