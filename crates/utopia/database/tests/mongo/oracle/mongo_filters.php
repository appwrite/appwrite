<?php

require '/usr/src/code/vendor/autoload.php';

use MongoDB\BSON\Document as Bson;
use Utopia\Database\Adapter\Mongo;
use Utopia\Database\Document;
use Utopia\Database\Operator;
use Utopia\Database\OperatorType;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Mongo\Client;
use Utopia\Query\CursorDirection;
use Utopia\Query\OrderDirection;

final class Recorder extends Client
{
    public array $calls = [];
    public function __construct()
    {
    }
    public function connect(): self
    {
        return $this;
    }
    public function close(): void
    {
    }
    public function find(string $collection, array $filters = [], array $options = []): stdClass
    {
        $this->calls[] = ['find', $filters, $options];
        return (object) ['cursor' => (object) ['firstBatch' => [], 'id' => 0]];
    }
    public function query(array $command, ?string $db = null): stdClass|array|int
    {
        $this->calls[] = ['query', $command];
        return 1;
    }
    public function update(string $collection, array $where = [], array $updates = [], array $options = [], bool $multi = false): int
    {
        $this->calls[] = ['update', $where, $updates];
        return 1;
    }
    public function aggregate(string $collection, array $pipeline, array $options = []): stdClass
    {
        $this->calls[] = ['aggregate', $pipeline];
        return (object) ['cursor' => (object) ['firstBatch' => []]];
    }
}

function encode(mixed $value): string
{
    return Bson::fromPHP(['v' => $value])->toCanonicalExtendedJSON();
}

$client = new Recorder();
$adapter = new Mongo($client);
$auth = new Authorization();
$auth->disable();
$adapter->setAuthorization($auth);
$adapter->setNamespace('f');
$collection = new Document(['$id' => 'c', 'attributes' => [
    ['$id' => 'a.b', 'key' => 'a.b', 'type' => 'integer'],
]]);

$object = fn (Query $q) => (function () use ($q) {
    $q->setAttributeType('object');
    return $q;
})();
$array = fn (Query $q) => (function () use ($q) {
    $q->setOnArray(true);
    return $q;
})();

$queries = [
    'equal1' => [Query::equal('x', [1])],
    'equal2' => [Query::equal('x', ['a', 'b'])],
    'notEqual1' => [Query::notEqual('x', 'a')],
    'notEqual2' => [Query::notEqual('x', ['a', 'b'])],
    'cmp' => [Query::lessThan('x', 1), Query::lessThanEqual('x', 2.5), Query::greaterThan('x', 'c'), Query::greaterThanEqual('x', true)],
    'between' => [Query::between('x', 1, 9)],
    'notBetween' => [Query::notBetween('x', 'a', 'z')],
    'nulls' => [Query::isNull('x'), Query::isNotNull('y')],
    'starts' => [Query::startsWith('x', 'a.b*'), Query::endsWith('x', '(x)'), Query::notStartsWith('x', '[a'), Query::notEndsWith('x', 'z|')],
    'regex' => [Query::regex('x', '^a.*$')],
    'containsString' => [Query::contains('x', ['one']), Query::contains('y', ['one', 'two'])],
    'containsArray' => [$array(Query::contains('x', ['one'])), $array(Query::containsAny('y', [1, 2])), $array(Query::containsAll('z', ['p', 'q']))],
    'notContains' => [Query::notContains('x', ['one']), $array(Query::notContains('y', ['a', 'b']))],
    'search' => [Query::search('x', 'hello world'), Query::notSearch('y', 'bye'), Query::notSearch('z', '')],
    'exists' => [Query::exists(['x', 'a.b']), Query::notExists('y')],
    'logical' => [Query::or([Query::equal('x', [1]), Query::and([Query::greaterThan('a.b', 5), Query::lessThan('y', 9)])])],
    'elemMatch' => [Query::elemMatch('items', [Query::equal('k', ['v']), Query::greaterThan('n', 1)])],
    'internal' => [Query::equal('$id', ['d1']), Query::equal('$sequence', ['s1', 's2']), Query::greaterThan('$createdAt', '2026-01-01T00:00:00.000+00:00'), Query::lessThan('$updatedAt', '2026-01-01 00:00:00'), Query::equal('$custom.key', ['v'])],
    'object' => [$object(Query::equal('meta', [['a' => ['b' => 1]]])), $object(Query::notEqual('meta', ['a' => 'x'])), $object(Query::contains('meta', [['tags' => ['x', 'y']]])), $object(Query::equal('meta.a', ['z']))],
];

$out = [];
foreach ($queries as $name => $list) {
    $client->calls = [];
    $adapter->find($collection, $list, 10, 5);
    $out[$name] = encode($client->calls[0][1]);
}

$adapter->setSchemaless(true);
$client->calls = [];
$adapter->find($collection, [Query::equal('when', ['2026-01-02T03:04:05.678Z', 'plain'])]);
$out['schemaless'] = encode($client->calls[0][1]);
$adapter->setSchemaless(false);

$client->calls = [];
$adapter->find($collection, [Query::select(['x', '$id'])], 25, null, ['x', '$sequence'], [OrderDirection::Desc, OrderDirection::Asc], ['x' => 3, '$sequence' => 'abc'], CursorDirection::Before);
$out['cursor_filters'] = encode($client->calls[0][1]);
$out['cursor_options'] = encode($client->calls[0][2]);
$client->calls = [];
$adapter->find($collection, [], 25, null, ['$sequence'], [OrderDirection::Asc], ['$sequence' => 'abc'], CursorDirection::After);
$out['sequence_cursor'] = encode($client->calls[0][1]);

$operators = [
    'inc' => Operator::increment(2),
    'incMax' => Operator::increment(2, 10),
    'dec' => Operator::decrement(1, 0),
    'mul' => Operator::multiply(1.5, 100),
    'div' => Operator::divide(2, 1),
    'mod' => Operator::modulo(3),
    'pow' => Operator::power(2, 1000),
    'powNeg' => Operator::power(-1, 1000),
    'powFrac' => Operator::power(0.5, 1000),
    'powBoth' => Operator::power(-0.5, 1000),
    'concat' => Operator::stringConcat('!'),
    'replace' => Operator::stringReplace('a', 'b'),
    'replaceEmpty' => new Operator(OperatorType::StringReplace, '', ['', 'b']),
    'toggle' => Operator::toggle(),
    'append' => Operator::arrayAppend(['x', 1]),
    'prepend' => Operator::arrayPrepend(['y']),
    'insert' => Operator::arrayInsert(2, 'v'),
    'remove' => Operator::arrayRemove('r'),
    'unique' => Operator::arrayUnique(),
    'intersect' => Operator::arrayIntersect(['a', 'b']),
    'diff' => Operator::arrayDiff(['c']),
    'filterEq' => Operator::arrayFilter('equal', 3),
    'filterNull' => Operator::arrayFilter('isNull', null),
    'filterOther' => new Operator(OperatorType::ArrayFilter, '', ['weird', 1]),
    'addDays' => Operator::dateAddDays(3),
    'subDays' => Operator::dateSubDays(2),
    'now' => Operator::dateSetNow(),
];
$client->calls = [];
$adapter->updateDocument($collection, 'd1', new Document($operators + ['plain' => 'p', 'list' => [1, 2], '$updatedAt' => 'u']), true);
$out['pipeline'] = encode($client->calls[0][1]['updates']);

$client->calls = [];
$adapter->updateDocument($collection, 'd1', new Document(['plain' => 'p', '$custom' => 'c', 'dot.ted' => 1, '$sequence' => 's']), true);
$out['set'] = encode([$client->calls[0][1], $client->calls[0][2]]);

$client->calls = [];
$adapter->count($collection, [Query::equal('x', [1])], 5);
$adapter->count($collection, []);
$adapter->sum($collection, 'a.b', [Query::greaterThan('a.b', 1)], 3);
$out['aggregates'] = encode(array_map(fn ($c) => $c[1], $client->calls));

$client->calls = [];
$adapter->deleteDocuments($collection, ['s1', 's2'], []);
$adapter->deleteDocuments($collection, ['s1'], []);
$out['deletes'] = count($client->calls);

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
