<?php

// Generates redis_queries_php.json: documents, finds (queries, orders,
// cursors, pages, selections) and what the Redis adapter of PHP 8.5
// (utopia-php/database 8.1.3) returns for each, against a real Redis
// (compat-database-redis, database 7).
//
//   docker cp redis_queries_php.php appwrite:/tmp/ && docker exec appwrite php -d display_errors=0 /tmp/redis_queries_php.php

require '/usr/src/code/vendor/autoload.php';

use Utopia\Database\Adapter\Redis as RedisAdapter;
use Utopia\Database\Attribute;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Query\CursorDirection;
use Utopia\Query\OrderDirection;

$inputs = [
    ['$id' => 'a', 'title' => 'The quick brown fox', 'n' => 5, 'f' => 1.5, 's' => '10', 'tags' => ['red', 'blue'], 'meta' => ['size' => 3, 'tag' => 'x'], 'json' => '{"k":[1,2]}'],
    ['$id' => 'b', 'title' => 'Lazy dog sleeps', 'n' => 10, 'f' => 2.0, 's' => '9', 'tags' => ['green'], 'meta' => ['size' => 7], 'json' => '[1,2,3]'],
    ['$id' => 'c', 'title' => 'quick-silver Über straße', 'n' => -3, 'f' => null, 's' => 'abc', 'tags' => [], 'meta' => null],
    ['$id' => 'd', 'title' => null, 'n' => 5, 'f' => 5, 's' => '5.0', 'tags' => ['red'], 'meta' => ['size' => '3']],
    ['$id' => 'E', 'title' => 'Brown bears #1', 'n' => 0, 'f' => 0.0, 's' => '', 'tags' => ['RED', 'red'], 'meta' => ['size' => 3.0, 'deep' => ['x' => 1]]],
    ['$id' => 'f', 'title' => 'fox', 'n' => 100, 'f' => -1.25, 's' => '1e2', 'tags' => ['blue', 'green', 'red']],
];

$finds = [
    ['queries' => ['{"method":"equal","attribute":"n","values":[5]}']],
    ['queries' => ['{"method":"equal","attribute":"n","values":["5"]}']],
    ['queries' => ['{"method":"equal","attribute":"f","values":[2]}']],
    ['queries' => ['{"method":"equal","attribute":"s","values":[10]}']],
    ['queries' => ['{"method":"equal","attribute":"s","values":["5"]}']],
    ['queries' => ['{"method":"equal","attribute":"title","values":["fox","Lazy dog sleeps"]}']],
    ['queries' => ['{"method":"notEqual","attribute":"n","values":[5]}']],
    ['queries' => ['{"method":"lessThan","attribute":"n","values":[5]}']],
    ['queries' => ['{"method":"lessThanEqual","attribute":"s","values":["9"]}']],
    ['queries' => ['{"method":"greaterThan","attribute":"f","values":[1]}']],
    ['queries' => ['{"method":"greaterThanEqual","attribute":"title","values":["L"]}']],
    ['queries' => ['{"method":"between","attribute":"n","values":[0,10]}']],
    ['queries' => ['{"method":"notBetween","attribute":"n","values":[0,10]}']],
    ['queries' => ['{"method":"startsWith","attribute":"title","values":["quick"]}']],
    ['queries' => ['{"method":"notStartsWith","attribute":"title","values":["quick"]}']],
    ['queries' => ['{"method":"endsWith","attribute":"title","values":["fox"]}']],
    ['queries' => ['{"method":"notEndsWith","attribute":"title","values":["fox"]}']],
    ['queries' => ['{"method":"contains","attribute":"tags","values":["red"]}']],
    ['queries' => ['{"method":"contains","attribute":"title","values":["QUICK"]}']],
    ['queries' => ['{"method":"containsAny","attribute":"tags","values":["green","RED"]}']],
    ['queries' => ['{"method":"containsAll","attribute":"tags","values":["red","blue"]}']],
    ['queries' => ['{"method":"notContains","attribute":"tags","values":["red"]}']],
    ['queries' => ['{"method":"contains","attribute":"json","values":[2]}']],
    ['queries' => ['{"method":"search","attribute":"title","values":["quick"]}']],
    ['queries' => ['{"method":"search","attribute":"title","values":["\"brown fox\""]}']],
    ['queries' => ['{"method":"search","attribute":"title","values":["bro* über"]}']],
    ['queries' => ['{"method":"search","attribute":"title","values":["STRASSE straße"]}']],
    ['queries' => ['{"method":"notSearch","attribute":"title","values":["fox"]}']],
    ['queries' => ['{"method":"regex","attribute":"title","values":["^[A-Z].*#\\\\d$"]}']],
    ['queries' => ['{"method":"regex","attribute":"title","values":["(?i)über"]}']],
    ['queries' => ['{"method":"isNull","attribute":"title","values":[]}']],
    ['queries' => ['{"method":"isNotNull","attribute":"f","values":[]}']],
    ['queries' => ['{"method":"isNull","attribute":"missing","values":[]}']],
    ['queries' => ['{"method":"equal","attribute":"meta.size","values":[3]}']],
    ['queries' => ['{"method":"greaterThan","attribute":"meta.size","values":[2]}']],
    ['queries' => ['{"method":"equal","attribute":"meta.deep.x","values":[1]}']],
    ['queries' => ['{"method":"equal","attribute":"json.k","values":[[1,2]]}']],
    ['queries' => ['{"method":"or","values":[{"method":"equal","attribute":"n","values":[0]},{"method":"lessThan","attribute":"f","values":[0]}]}']],
    ['queries' => ['{"method":"and","values":[{"method":"equal","attribute":"n","values":[5]},{"method":"isNotNull","attribute":"title","values":[]}]}']],
    ['queries' => ['{"method":"equal","attribute":"$id","values":["e"]}']],
    ['queries' => ['{"method":"equal","attribute":"$id","values":["E"]}']],
    ['queries' => ['{"method":"select","values":["title","n"]}', '{"method":"equal","attribute":"$id","values":["a"]}']],
    ['queries' => ['{"method":"select","values":["*"]}', '{"method":"equal","attribute":"$id","values":["b"]}']],
    ['order' => [['n', 'ASC'], ['f', 'DESC']]],
    ['order' => [['title', 'ASC']]],
    ['order' => [['title', 'DESC']]],
    ['order' => [['s', 'ASC']]],
    ['order' => [['f', 'ASC']]],
    ['order' => [['meta.size', 'DESC'], ['n', 'ASC']]],
    ['order' => [['n', 'ASC']], 'cursor' => ['n' => 5], 'direction' => 'after'],
    ['order' => [['n', 'ASC']], 'cursor' => ['n' => 5], 'direction' => 'before'],
    ['order' => [['n', 'DESC']], 'cursor' => ['n' => 5], 'direction' => 'after'],
    ['order' => [['title', 'ASC']], 'cursor' => ['title' => null], 'direction' => 'after'],
    ['order' => [], 'cursor' => ['$sequence' => '3'], 'direction' => 'before'],
    ['order' => [['n', 'ASC']], 'limit' => 2, 'offset' => 1],
    ['order' => [['n', 'ASC']], 'limit' => 2, 'offset' => 1, 'direction' => 'before'],
    ['order' => [], 'limit' => 0],
    ['sum' => 'n'], ['sum' => 'f'], ['sum' => 's'], ['sum' => 'meta.size'],
    ['count' => ['{"method":"greaterThan","attribute":"n","values":[1]}'], 'max' => 2],
];

$redis = new Redis();
$redis->connect('compat-database-redis', 6379);
$redis->select(7);
foreach ($redis->keys('utopia:redis_queries_php*') as $key) {
    $redis->del($key);
}
$adapter = new RedisAdapter($redis);
$auth = new Authorization();
$auth->disable();
$adapter->setAuthorization($auth);
$adapter->setDatabase('db');
$adapter->setNamespace('redis_queries_php');
$adapter->create('db');
$adapter->createCollection('items', [Attribute::string(key: 'title', size: 64)]);
$items = new Document(['$id' => 'items']);
foreach ($inputs as $document) {
    $adapter->createDocument($items, new Document([...$document, '$permissions' => []]));
}

$encode = fn (mixed $value): string => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
$results = [];
foreach ($finds as $find) {
    try {
        if (isset($find['sum'])) {
            $result = var_export($adapter->sum($items, $find['sum']), true);
        } elseif (isset($find['count'])) {
            $result = var_export($adapter->count($items, \array_map(Query::parse(...), $find['count']), $find['max']), true);
        } else {
            $queries = \array_map(Query::parse(...), $find['queries'] ?? []);
            $order = $find['order'] ?? [];
            $documents = $adapter->find(
                $items,
                $queries,
                \array_key_exists('limit', $find) ? $find['limit'] : 25,
                $find['offset'] ?? null,
                \array_map(fn ($o) => $o[0], $order),
                \array_map(fn ($o) => OrderDirection::from($o[1]), $order),
                $find['cursor'] ?? [],
                CursorDirection::from($find['direction'] ?? 'after'),
            );
            $result = \array_map(fn (Document $d) => $encode($d->getArrayCopy()), $documents);
        }
        $results[] = [...$find, 'result' => ['ok', $result]];
    } catch (\Throwable $e) {
        $results[] = [...$find, 'result' => ['error', $e::class, $e->getMessage()]];
    }
}

echo json_encode(['documents' => $inputs, 'finds' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), "\n";
