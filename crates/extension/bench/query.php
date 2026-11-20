<?php

// Times Utopia\Query\Query on whichever implementation loaded, directly and through
// utopia-php/database's Query (what every list endpoint's `queries[]` goes through). Compare both
// with `python3 crates/extension/bench/run.py crates/extension/bench/query.php . <utopia.so> 5`.

require __DIR__ . '/../../../vendor/autoload.php';

use Utopia\Database\Document;
use Utopia\Database\Query as DatabaseQuery;
use Utopia\Query\Method;
use Utopia\Query\Query;

// A run meant for the extension that gets the PHP class would time PHP against PHP.
$internal = (new ReflectionClass(Query::class))->isInternal();
$requested = in_array('query', array_map(trim(...), explode(',', getenv('_APP_EXTENSION_LIBRARIES') ?: '')), true);
if ($internal !== $requested) {
    fwrite(STDERR, '_APP_EXTENSION_LIBRARIES ' . ($requested ? 'names' : 'does not name') . ' query, but the other implementation loaded' . PHP_EOL);
    exit(1);
}

// A typical list request's queries[], and one with nested logical queries.
$typical = [
    '{"method":"equal","attribute":"status","values":["active","pending"]}',
    '{"method":"greaterThan","attribute":"$createdAt","values":["2024-01-01T00:00:00.000+00:00"]}',
    '{"method":"orderDesc","attribute":"$createdAt"}',
    '{"method":"limit","values":[25]}',
    '{"method":"offset","values":[50]}',
];
$nested = [
    '{"method":"or","values":[{"method":"equal","attribute":"a","values":[1]},{"method":"and","values":[{"method":"lessThan","attribute":"b","values":[2.5]},{"method":"isNotNull","attribute":"c"},{"method":"startsWith","attribute":"d","values":["x"]}]}]}',
    '{"method":"select","values":["name","$id","author.*"]}',
    '{"method":"cursorAfter","values":["doc1"]}',
    '{"method":"limit","values":[25]}',
];
$array = ['method' => 'equal', 'attribute' => 'status', 'values' => ['active', 'pending']];
$built = Query::or([Query::equal('a', [1]), Query::and([Query::lessThan('b', 2.5), Query::isNotNull('c'), Query::startsWith('d', 'x')])]);
$list = Query::parseQueries($typical);
$dbList = DatabaseQuery::parseQueries($typical);
$document = new Document(['$id' => 'doc1', 'name' => 'x']);
$dbWithCursor = [...$dbList, DatabaseQuery::cursorAfter($document)];
$equal = Query::equal('status', ['active']);

$cases = [
    'parse' => [fn () => Query::parse($typical[0]), 20000],
    'parse nested' => [fn () => Query::parse($nested[0]), 10000],
    'parseQuery' => [fn () => Query::parseQuery($array), 20000],
    'parseQueries (5)' => [fn () => Query::parseQueries($typical), 5000],
    'parseQueries nested' => [fn () => Query::parseQueries($nested), 5000],
    'new Query' => [fn () => new Query(Method::Equal, 'a', [1]), 50000],
    'new Query (string)' => [fn () => new Query('equal', 'a', [1]), 50000],
    'Query::equal' => [fn () => Query::equal('a', [1]), 50000],
    'Query::limit' => [fn () => Query::limit(25), 50000],
    'Query::and (2)' => [fn () => Query::and([Query::equal('a', [1]), Query::isNull('b')]), 20000],
    'Query::join' => [fn () => Query::join('orders', 'o', [Query::on('$id', 'orderId')]), 20000],
    'toString' => [fn () => $equal->toString(), 50000],
    'toString nested' => [fn () => $built->toString(), 20000],
    'toArray nested' => [fn () => $built->toArray(), 20000],
    'clone nested' => [fn () => clone $built, 20000],
    'getters' => [fn () => [$equal->getMethod(), $equal->getAttribute(), $equal->getValues(), $equal->getValue()], 50000],
    'shape nested' => [fn () => $built->shape(), 20000],
    'fingerprint (5)' => [fn () => Query::fingerprint($list), 10000],
    'groupByType (5)' => [fn () => Query::groupByType($list), 10000],
    'getCursorQueries (5)' => [fn () => Query::getCursorQueries($list), 20000],
    'DB parse' => [fn () => DatabaseQuery::parse($typical[0]), 20000],
    'DB parseQueries (5)' => [fn () => DatabaseQuery::parseQueries($typical), 5000],
    'DB parseQueries nested' => [fn () => DatabaseQuery::parseQueries($nested), 5000],
    'DB list request' => [function () use ($typical, $document) {
        $queries = DatabaseQuery::parseQueries($typical);
        $queries[] = DatabaseQuery::cursorAfter($document);
        $cursor = DatabaseQuery::getCursorQueries($queries);
        $grouped = DatabaseQuery::groupByType($queries);

        return [$cursor, $grouped];
    }, 5000],
    'DB groupByType (6)' => [fn () => DatabaseQuery::groupByType($dbWithCursor), 10000],
    'DB toString' => [fn () => $dbList[0]->toString(), 50000],
    'DB equal' => [fn () => DatabaseQuery::equal('a', [1]), 50000],
];
$out = [];
foreach ($cases as $name => [$fn, $n]) {
    for ($i = 0; $i < max(1, intdiv($n, 20)); $i++) {
        $fn();
    }
    $runs = [];
    for ($r = 0; $r < 5; $r++) {
        $t = hrtime(true);
        for ($i = 0; $i < $n; $i++) {
            $fn();
        }
        $runs[] = (hrtime(true) - $t) / $n;
    }
    sort($runs);
    $out[$name] = $runs[2];
}
$out['peak memory MB'] = round(memory_get_peak_usage(true) / 1048576, 1);
echo json_encode($out), "\n";
