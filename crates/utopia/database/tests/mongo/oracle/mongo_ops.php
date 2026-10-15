<?php

require '/usr/src/code/vendor/autoload.php';

use Utopia\Database\Adapter\Mongo;
use Utopia\Database\Attribute;
use Utopia\Database\Change;
use Utopia\Database\Document;
use Utopia\Database\Index;
use Utopia\Database\Operator;
use Utopia\Database\PermissionType;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Mongo\Client;
use Utopia\Query\CursorDirection;
use Utopia\Query\OrderDirection;

$ns = $argv[1] ?? 'php';
$client = new Client('crosscheck', 'compat-database-mongo', 27017, 'root', 'password', false);
$adapter = new Mongo($client);
$auth = new Authorization();
$auth->disable();
$adapter->setAuthorization($auth);
$adapter->setDatabase('crosscheck');
$adapter->setNamespace($ns);
if (($argv[2] ?? '') === 'shared') { $adapter->setSharedTables(true); $adapter->setTenant(7); }

$out = [];
$attributes = [
    Attribute::string(key: 'title', size: 128),
    Attribute::integer(key: 'year'),
    Attribute::string(key: 'tags', size: 32, array: true),
    Attribute::object(key: 'meta'),
    Attribute::datetime(key: 'released'),
    Attribute::double(key: 'rating'),
];
$indexes = [
    Index::unique(key: 'by_title', attributes: ['title']),
    Index::key(key: 'by_year', attributes: ['year'], orders: [OrderDirection::Desc]),
];
try { $adapter->deleteCollection('movies'); } catch (\Throwable) {}
$out['create'] = $adapter->createCollection('movies', $attributes, $indexes);
$collection = new Document(['$id' => 'movies', 'attributes' => array_map(fn ($a) => $a->toDocument(), $attributes), 'indexes' => []]);

$doc = new Document([
    '$id' => 'm1',
    '$permissions' => ['read("any")'],
    '$createdAt' => '2026-01-01 10:00:00.000',
    '$updatedAt' => '2026-01-01 10:00:00.000',
    'title' => 'Alpha.$One',
    'year' => 2001,
    'tags' => ['a', 'b'],
    'meta' => '{"k":{"n":1},"list":[1,2]}',
    'released' => '2001-05-06 07:08:09.123',
    'rating' => 7.5,
    '$custom' => 'x',
    'dotted.key' => 'v',
]);
$created = $adapter->createDocument($collection, $adapter->castBefore($collection, $doc));
$out['created'] = array_keys($created->getArrayCopy());

$batch = [];
foreach ([['m2', 'Beta', 1999], ['m3', 'Gamma', 2010]] as [$id, $title, $year]) {
    $batch[] = $adapter->castBefore($collection, new Document([
        '$id' => $id, '$permissions' => [], '$createdAt' => '2026-01-02 00:00:00.000', '$updatedAt' => '2026-01-02 00:00:00.000',
        'title' => $title, 'year' => $year, 'tags' => ['c'], 'released' => '2010-01-01T00:00:00.000+02:00', 'rating' => 5,
    ]));
}
$out['createMany'] = count($adapter->createDocuments($collection, $batch));

$adapter->updateDocument($collection, 'm1', $adapter->castBefore($collection, new Document([
    'year' => Operator::increment(5, 3000),
    'tags' => Operator::arrayAppend(['z']),
    'title' => Operator::stringConcat('!'),
    'rating' => Operator::multiply(2),
    '$updatedAt' => '2026-02-01 00:00:00.000',
])), true);
$adapter->increaseDocumentAttribute($collection, 'm2', 'year', 3, '2026-03-01 00:00:00.000', null, 5000);
$adapter->increaseDocumentAttribute($collection, 'm3', 'year', '-10', '2026-03-01 00:00:00.000', '0', null);

$adapter->upsertDocuments($collection, [
    new Change(new Document(), $adapter->castBefore($collection, new Document(['$id' => 'm4', '$permissions' => [], '$createdAt' => '2026-04-01 00:00:00.000', '$updatedAt' => '2026-04-01 00:00:00.000', 'title' => 'Delta', 'year' => 1980, 'tags' => []]))),
    new Change(new Document(), $adapter->castBefore($collection, new Document(['$id' => 'm2', '$permissions' => ['read("any")'], '$createdAt' => '2026-01-02 00:00:00.000', '$updatedAt' => '2026-04-01 00:00:00.000', 'title' => 'Beta2', 'year' => 1999, 'tags' => ['q']]))),
]);

$ids = fn (array $docs) => array_map(fn (Document $d) => $d->getId(), $docs);
$out['find_all'] = $ids($adapter->find($collection, [], 25, null, ['year'], [OrderDirection::Asc]));
$out['find_between'] = $ids($adapter->find($collection, [Query::between('year', 1990, 2010)]));
$out['find_contains'] = $ids($adapter->find($collection, [Query::contains('title', ['ET'])]));
$out['find_starts'] = $ids($adapter->find($collection, [Query::startsWith('title', 'Alpha.$')]));
$out['find_tags'] = $ids($adapter->find($collection, [(fn ($q) => $q->setOnArray(true) ?? $q)(Query::contains('tags', ['c', 'z']))]));
$q = Query::contains('tags', ['c', 'z']); $q->setOnArray(true);
$out['find_tags'] = $ids($adapter->find($collection, [$q]));
$out['find_created'] = $ids($adapter->find($collection, [Query::greaterThan('$createdAt', '2026-01-01T12:00:00.000+00:00')]));
$m2 = $adapter->getDocument($collection, 'm2');
$out['find_cursor'] = $ids($adapter->find($collection, [], 25, null, ['year', '$sequence'], [OrderDirection::Desc, OrderDirection::Asc], ['year' => 2002, '$sequence' => '0'], CursorDirection::After));
$out['find_or'] = $ids($adapter->find($collection, [Query::or([Query::equal('year', [2006]), Query::lessThan('year', 1990)])]));
$out['find_select'] = array_map(fn ($d) => array_keys($d->getArrayCopy()), $adapter->find($collection, [Query::select(['title']), Query::equal('$id', ['m1'])]));
$out['count'] = $adapter->count($collection, [Query::greaterThan('year', 1990)]);
$out['count_max'] = $adapter->count($collection, [], 2);
$out['sum'] = $adapter->sum($collection, 'year', []);
$out['sum_rating'] = $adapter->sum($collection, 'rating', []);
$g = $adapter->castAfter($collection, [$adapter->getDocument($collection, 'm1')])[0];
$c = $g->getArrayCopy();
unset($c['$sequence']);
ksort($c);
$out['get_m1'] = $c;
$out['sequences'] = array_map(fn ($d) => $d->getSequence() !== null, $adapter->getSequences($collection, [new Document(['$id' => 'm3']), new Document(['$id' => 'nope'])]));
$out['delete'] = $adapter->deleteDocument($collection, 'm3');
try {
    $adapter->createDocument($collection, $adapter->castBefore($collection, new Document(['$id' => 'm9', '$permissions' => [], 'title' => 'Delta'])));
} catch (\Throwable $e) {
    $out['dup'] = [get_class($e), $e->getMessage()];
}
try {
    $adapter->createDocument($collection, $adapter->castBefore($collection, new Document(['$id' => 'm1', '$permissions' => [], 'title' => 'Other'])));
} catch (\Throwable $e) {
    $out['dup_id'] = [get_class($e), $e->getMessage()];
}
$out['tx'] = $adapter->withTransaction(function () use ($adapter, $collection) {
    $adapter->createDocument($collection, $adapter->castBefore($collection, new Document(['$id' => 'm5', '$permissions' => [], 'title' => 'Tx', 'year' => 1])));
    $adapter->increaseDocumentAttribute($collection, 'm5', 'year', 1, '2026-05-01 00:00:00.000');
    return 'ok';
});
try {
    $adapter->withTransaction(function () use ($adapter, $collection) {
        $adapter->createDocument($collection, $adapter->castBefore($collection, new Document(['$id' => 'm6', '$permissions' => [], 'title' => 'Rolled', 'year' => 1])));
        throw new \Utopia\Database\Exception\Structure('boom');
    });
} catch (\Throwable $e) {
    $out['tx_failed'] = [get_class($e), $e->getMessage()];
}
$out['tx_m6'] = $adapter->getDocument($collection, 'm6')->isEmpty();
$adapter->renameAttribute('movies', 'rating', 'score');
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
