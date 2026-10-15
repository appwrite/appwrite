<?php

// Records the statements and results of SQL::find()/count()/sum() for the
// scenarios of tests/fixtures/sql_find/spec.json, per engine and table mode.
// Usage: php oracle.php spec.json out.json
require '/usr/src/code/vendor/autoload.php';

use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Adapter\MySQL;
use Utopia\Database\Adapter\Postgres;
use Utopia\Database\Adapter\SQLite;
use Utopia\Database\Collection;
use Utopia\Database\Document;
use Utopia\Database\Event;
use Utopia\Database\Hook\Permissions;
use Utopia\Database\Hook\Transform;
use Utopia\Database\PermissionType;
use Utopia\Database\Profiler;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Query\CursorDirection;
use Utopia\Query\OrderDirection;

class RecStmt extends PDOStatement
{
    public static array $log = [];
    private array $bound = [];
    protected function __construct()
    {
    }
    public function bindValue(int|string $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $this->bound[] = [$param, $value, $type];
        return parent::bindValue($param, $value, $type);
    }
    public function execute(?array $params = null): bool
    {
        self::$log[] = [$this->queryString, $this->bound];
        return parent::execute($params);
    }
}

class RecPDO extends PDO
{
    public function exec(string $statement): int|false
    {
        RecStmt::$log[] = [$statement, []];
        return parent::exec($statement);
    }
}

$spec = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$only = $argv[3] ?? null; // one engine only, when given

$engines = [
    'postgres' => [Postgres::class, 'pgsql:host=compat-database-postgres;port=5432', 'root', 'password'],
    'mariadb' => [MariaDB::class, 'mysql:host=compat-database-mariadb;port=3306;charset=utf8mb4', 'root', 'password'],
    'mysql' => [MySQL::class, 'mysql:host=compat-database-mysql;port=3306;charset=utf8mb4', 'root', 'password'],
    'sqlite' => [SQLite::class, 'sqlite::memory:', null, null],
];

$transform = new class () implements Transform {
    public array $seen = [];
    public function transform(Event $event, string $query): string
    {
        $this->seen[] = [$event->value, $query];
        return $query;
    }
};

function convert(mixed $value): mixed
{
    if ($value instanceof Document) {
        return array_map('convert', $value->getArrayCopy());
    }
    if (is_array($value)) {
        return array_map('convert', $value);
    }
    return $value;
}

$out = [];
foreach ($engines as $engine => [$class, $dsn, $user, $pass]) {
    if ($only !== null && $only !== $engine) {
        continue;
    }
    foreach ([false, true] as $shared) {
        $mode = $shared ? 'shared' : 'plain';
        $pdo = new RecPDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => true,
            PDO::ATTR_STRINGIFY_FETCHES => true,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [RecStmt::class, []]);
        $adapter = new $class($pdo);
        $database = 'l3a_find_' . $mode;
        $adapter->setDatabase($database);
        $adapter->setNamespace('ns');
        if ($shared) {
            $adapter->setSharedTables(true);
            $adapter->setTenant(7);
        }
        $authorization = new Authorization();
        $authorization->disable();
        $adapter->setAuthorization($authorization);
        $adapter->addWriteHook(new Permissions());

        if ($engine !== 'sqlite') {
            try {
                $adapter->delete($database);
            } catch (Throwable) {
            }
            $adapter->create($database);
        }
        RecStmt::$log = [];
        $collections = [];
        foreach ($spec['collections'] as $id => $definition) {
            if (isset($definition['engines']) && ! in_array($engine, $definition['engines'], true)) {
                continue;
            }
            unset($definition['engines']);
            $document = new Document($definition);
            $collections[$id] = $definition;
            $collection = Collection::fromDocument($document);
            $adapter->createCollection($id, $collection->attributes(), $collection->indexes());
            foreach ($spec['documents'][$id] ?? [] as $data) {
                $data['$createdAt'] = '2026-01-01 00:00:00.000';
                $data['$updatedAt'] = '2026-01-02 00:00:00.000';
                if ($shared) {
                    $data['$tenant'] = 7;
                }
                $adapter->createDocument($document, new Document($data));
            }
        }
        $setup = RecStmt::$log;

        $profiler = new Profiler();
        $profiler->enable();
        $adapter->setProfiler($profiler);
        $adapter->addTransform('rec', $transform);

        $results = [];
        foreach ($spec['scenarios'] as $scenario) {
            if (isset($scenario['engines']) && ! in_array($engine, $scenario['engines'], true)) {
                continue;
            }
            $definition = $collections[$scenario['collection'] ?? 'authors'];
            if ($scenario['collectionExtra'] ?? false) {
                $definition['joinAttributes'] = $spec['joined']['joinAttributes'];
                if ($shared) {
                    $definition['joinIndexed'] = $spec['joined']['joinIndexed'];
                }
            }
            if (isset($scenario['joinDocumentSecurity'])) {
                $definition['joinDocumentSecurity'] = $scenario['joinDocumentSecurity'];
            }
            if (isset($scenario['collectionGranted'])) {
                $definition['collectionGranted'] = $scenario['collectionGranted'];
            }
            $collection = new Document($definition);
            $queries = [];
            foreach ($scenario['queries'] as $json) {
                $query = Query::parseQuery($json);
                if (isset($scenario['types'][$query->getAttribute()])) {
                    [$type, $array] = $scenario['types'][$query->getAttribute()];
                    $query->setAttributeType($type);
                    $query->setOnArray($array);
                }
                $queries[] = $query;
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
            $profiler->reset();
            $transform->seen = [];
            try {
                $result = match ($scenario['call']) {
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
                $outcome = ['ok', ($scenario['noResult'] ?? false) ? null : convert($result)];
            } catch (Throwable $e) {
                $outcome = ['error', get_class($e), $e->getMessage()];
            }
            $results[$scenario['name']] = [
                'outcome' => $outcome,
                'statements' => $transform->seen,
                'logs' => array_map(fn ($log) => [$log->query, array_values($log->bindings)], $profiler->getLogs()),
            ];
        }
        $out["$engine/$mode"] = ['setup' => $setup, 'scenarios' => $results];
    }
}

file_put_contents($argv[2], json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n");
echo "ok\n";
