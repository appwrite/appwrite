<?php

// Records the first statement SQL::find()/count()/sum() prepares (or the
// error raised before one) for each read of capture.json, per engine and
// table mode. A transform records the statement and stops it, so no table
// has to exist. Usage: php oracle_capture.php capture.json out.json
require '/usr/src/code/vendor/autoload.php';

use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Adapter\MySQL;
use Utopia\Database\Adapter\Postgres;
use Utopia\Database\Adapter\SQLite;
use Utopia\Database\Document;
use Utopia\Database\Event;
use Utopia\Database\Hook\Transform;
use Utopia\Database\PermissionType;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Query\CursorDirection;
use Utopia\Query\OrderDirection;

final class Stopped extends RuntimeException
{
}

$scenarios = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);

$engines = [
    'postgres' => [Postgres::class, 'pgsql:host=compat-database-postgres;port=5432', 'root', 'password'],
    'mariadb' => [MariaDB::class, 'mysql:host=compat-database-mariadb;port=3306;charset=utf8mb4', 'root', 'password'],
    'mysql' => [MySQL::class, 'mysql:host=compat-database-mysql;port=3306;charset=utf8mb4', 'root', 'password'],
    'sqlite' => [SQLite::class, 'sqlite::memory:', null, null],
];
$pdos = [];
foreach ($engines as $engine => [$class, $dsn, $user, $pass]) {
    $pdos[$engine] = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => true,
        PDO::ATTR_STRINGIFY_FETCHES => true,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

$out = [];
foreach ($scenarios as $scenario) {
    foreach ($engines as $engine => [$class]) {
        if (isset($scenario['engines']) && ! in_array($engine, $scenario['engines'], true)) {
            continue;
        }
        foreach ($scenario['modes'] ?? ['plain'] as $mode) {
            $adapter = new $class($pdos[$engine]);
            $adapter->setDatabase($scenario['database'] ?? 'database');
            $adapter->setNamespace($scenario['namespace'] ?? 'namespace');
            if ($mode === 'shared') {
                $adapter->setSharedTables(true);
                $adapter->setTenant(array_key_exists('tenant', $scenario) ? $scenario['tenant'] : 7);
            }
            $authorization = new Authorization();
            if (isset($scenario['auth'])) {
                foreach ($scenario['auth'] as $role) {
                    $authorization->addRole($role);
                }
            } else {
                $authorization->disable();
            }
            $adapter->setAuthorization($authorization);
            $seen = [];
            $adapter->addTransform('stop', new class ($seen) implements Transform {
                public function __construct(private array &$seen)
                {
                }
                public function transform(Event $event, string $query): string
                {
                    $this->seen[] = $query;
                    throw new Stopped();
                }
            });

            $collection = new Document($scenario['collection'] ?? ['$id' => 'collection']);
            $queries = array_map(fn ($json) => Query::parseQuery($json), $scenario['queries']);
            try {
                match ($scenario['call'] ?? 'find') {
                    'find' => $adapter->find(
                        $collection,
                        $queries,
                        array_key_exists('limit', $scenario) ? $scenario['limit'] : 25,
                        $scenario['offset'] ?? null,
                        $scenario['orderAttributes'] ?? [],
                        array_map(fn ($t) => OrderDirection::from($t), $scenario['orderTypes'] ?? []),
                        $scenario['cursor'] ?? [],
                        CursorDirection::from($scenario['cursorDirection'] ?? 'after'),
                        PermissionType::from($scenario['forPermission'] ?? 'read'),
                    ),
                    'count' => $adapter->count($collection, $queries, $scenario['max'] ?? null),
                    'sum' => $adapter->sum($collection, $scenario['attribute'], $queries, $scenario['max'] ?? null),
                };
                $outcome = ['none'];
            } catch (Stopped) {
                $outcome = ['sql', $seen[0]];
            } catch (Throwable $e) {
                $outcome = ['error', get_class($e), $e->getMessage()];
            }
            $out[$scenario['name'] . " $engine/$mode"] = $outcome;
        }
    }
}

file_put_contents($argv[2], json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo count($out), "\n";
